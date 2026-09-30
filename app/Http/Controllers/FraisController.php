<?php

namespace App\Http\Controllers;

use App\Models\TypeFrais;
use App\Models\GrilleTarifaire;
use App\Models\FraisEleve;
use App\Models\Paiement;
use App\Models\Classe;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Services\FraisService;

class FraisController extends Controller
{
    private function estFraisParEleve(GrilleTarifaire $grille): bool
    {
        return FraisService::estFraisParEleve($grille);
    }

    public function typesFrais(Request $request)
    {
        return response()->json(TypeFrais::where('etablissement_id', $request->user()->etablissement_id)->get());
    }

    public function storeTypeFrais(Request $request)
    {
        $request->validate(['nom' => 'required|string']);
        $type = TypeFrais::firstOrCreate(['etablissement_id' => $request->user()->etablissement_id, 'nom' => $request->nom]);
        return response()->json($type, 201);
    }

    public function grilles(Request $request)
    {
        $grilles = GrilleTarifaire::where('etablissement_id', $request->user()->etablissement_id)
            ->with(['classe', 'typeFrais', 'echeances'])
            ->get();

        // Memes regles que elevesViseesParGrille(), en deux requetes pour toutes les grilles (au
        // lieu de trois par grille) : inscriptions actives de la session active par classe, puis
        // frais deja crees pour ces eleves.
        $inscriptionsParClasse = \App\Models\Inscription::whereIn('classe_id', $grilles->pluck('classe_id')->unique())
            ->where('statut', 'active')
            ->whereHas('sessionScolaire', fn ($q) => $q->where('est_active', true))
            ->whereHas('eleve')
            ->get(['eleve_id', 'classe_id', 'type_inscription'])
            ->groupBy('classe_id');

        $fraisExistants = FraisEleve::whereIn('type_frais_id', $grilles->pluck('type_frais_id')->unique())
            ->whereIn('eleve_id', $inscriptionsParClasse->flatten()->pluck('eleve_id')->unique())
            ->get(['eleve_id', 'type_frais_id', 'session_scolaire_id'])
            ->mapWithKeys(fn ($f) => ["{$f->type_frais_id}|{$f->session_scolaire_id}|{$f->eleve_id}" => true]);

        $grilles->each(function ($grille) use ($inscriptionsParClasse, $fraisExistants) {
            // Eleves vises : ceux de la classe, restreints au public de la grille (nouveaux /
            // anciens) ; couverts = ceux qui ont deja un frais de ce type sur la session.
            $eleveIds = ($inscriptionsParClasse[$grille->classe_id] ?? collect())
                ->filter(fn ($i) => !in_array($grille->applicable_a, ['nouveau', 'ancien'], true)
                    || ($i->type_inscription === 'reinscription' ? 'ancien' : 'nouveau') === $grille->applicable_a)
                ->pluck('eleve_id')
                ->unique();

            $grille->nombre_eleves_classe = $eleveIds->count();
            $grille->nombre_eleves_couverts = $eleveIds
                ->filter(fn ($id) => isset($fraisExistants["{$grille->type_frais_id}|{$grille->session_scolaire_id}|{$id}"]))
                ->count();
        });

        return response()->json($grilles);
    }

    public function storeGrille(Request $request)
    {
        $request->validate([
            'classe_id' => 'required|exists:classes,id',
            'type_frais_id' => 'required|exists:types_frais,id',
            'montant' => 'required|numeric',
            'echeances' => 'required|array|min:1',
            'echeances.*.libelle' => 'required|string',
            'echeances.*.montant' => 'required|numeric',
            'echeances.*.date_limite' => 'required|date',
            'applicable_a' => 'nullable|in:tous,nouveau,ancien',
            'actif' => 'nullable|boolean',
        ]);

        $etablissementId = $request->user()->etablissement_id;
        $classe = Classe::findOrFail($request->classe_id);
        $applicableA = $request->input('applicable_a', 'tous');

        $grilleExistante = GrilleTarifaire::where('classe_id', $request->classe_id)
            ->where('type_frais_id', $request->type_frais_id)
            ->where('session_scolaire_id', $classe->session_scolaire_id)
            ->where('applicable_a', $applicableA)
            ->first();

        if ($grilleExistante) {
            return response()->json([
                'message' => 'Une grille tarifaire existe deja pour cette classe, ce type de frais et ce public sur cette session scolaire.',
            ], 422);
        }

        $grille = GrilleTarifaire::create([
            'etablissement_id' => $etablissementId,
            'session_scolaire_id' => $classe->session_scolaire_id,
            'classe_id' => $request->classe_id,
            'type_frais_id' => $request->type_frais_id,
            'montant' => $request->montant,
            'applicable_a' => $applicableA,
            'actif' => $request->boolean('actif', true),
        ]);

        foreach ($request->echeances as $ech) {
            $grille->echeances()->create($ech);
        }

        $this->appliquerGrilleAuxEleves($grille);

        return response()->json($grille->load('echeances'), 201);
    }

    /**
     * Cree les frais et echeances manquants pour les eleves de la classe
     * qui n'ont pas encore de FraisEleve pour cette grille (nouveaux inscrits notamment).
     */
    private function appliquerGrilleAuxEleves(GrilleTarifaire $grille): int
    {
        $grille->loadMissing('echeances', 'typeFrais');
        if ($this->estFraisParEleve($grille) || ! $grille->actif) {
            return 0;
        }
        $crees = 0;

        $service = new FraisService();
        $eleves = $this->elevesViseesParGrille($grille);

        // Grille "tous" : les eleves dont le public (nouveau / ancien) a sa propre grille active
        // du meme type la recoivent en priorite (meme regle que FraisService::appliquerGrillesAInscription).
        if ($grille->applicable_a === 'tous') {
            $publicsCouverts = GrilleTarifaire::where('classe_id', $grille->classe_id)
                ->where('type_frais_id', $grille->type_frais_id)
                ->where('session_scolaire_id', $grille->session_scolaire_id)
                ->where('actif', true)
                ->whereIn('applicable_a', ['nouveau', 'ancien'])
                ->pluck('applicable_a')
                ->all();
            $eleves = $eleves->reject(fn ($e) => in_array($this->publicEleve($e), $publicsCouverts, true));
        }

        foreach ($eleves as $eleve) {
            if ($service->creerFraisDepuisGrille($eleve->id, $grille, $eleve->inscriptionActive?->id)) {
                $crees++;
            }
        }

        return $crees;
    }

    /** 'ancien' pour une reinscription, 'nouveau' sinon (meme regle que FraisService). */
    private function publicEleve($eleve): string
    {
        return $eleve->inscriptionActive?->type_inscription === 'reinscription' ? 'ancien' : 'nouveau';
    }

    /** Eleves inscrits dans la classe de la grille, restreints au public vise (nouveaux / anciens). */
    private function elevesViseesParGrille(GrilleTarifaire $grille)
    {
        $eleves = \App\Models\Eleve::whereHas('inscriptionActive', fn($q) => $q->where('classe_id', $grille->classe_id)->where('statut', 'active'))
            ->with('inscriptionActive')
            ->get();

        if (in_array($grille->applicable_a, ['nouveau', 'ancien'], true)) {
            $eleves = $eleves->filter(fn ($e) => $this->publicEleve($e) === $grille->applicable_a)->values();
        }

        return $eleves;
    }

    /** Active ou desactive une grille (une grille inactive n'est plus appliquee aux nouveaux inscrits). */
    public function basculerGrille(Request $request, $id)
    {
        $grille = GrilleTarifaire::where('etablissement_id', $request->user()->etablissement_id)->findOrFail($id);
        $grille->update(['actif' => ! $grille->actif]);

        return response()->json([
            'actif' => (bool) $grille->actif,
            'message' => $grille->actif ? 'Grille tarifaire activée.' : 'Grille tarifaire désactivée : elle ne sera plus appliquée aux nouveaux élèves.',
        ]);
    }

    /** Renomme un type de frais (nom unique dans l'etablissement). */
    public function updateTypeFrais(Request $request, $id)
    {
        $etablissementId = $request->user()->etablissement_id;
        $type = TypeFrais::where('etablissement_id', $etablissementId)->findOrFail($id);
        $request->validate([
            'nom' => ['required', 'string', 'max:100', \Illuminate\Validation\Rule::unique('types_frais', 'nom')->where('etablissement_id', $etablissementId)->ignore($type->id)],
        ], ['nom.unique' => 'Un type de frais porte déjà ce nom.']);

        $type->update(['nom' => trim($request->nom)]);

        return response()->json($type);
    }

    /** Supprime un type de frais jamais utilise (ni grille, ni frais d'eleve). */
    public function destroyTypeFrais(Request $request, $id)
    {
        $type = TypeFrais::where('etablissement_id', $request->user()->etablissement_id)->findOrFail($id);

        $grilles = GrilleTarifaire::where('type_frais_id', $type->id)->count();
        $frais = FraisEleve::where('type_frais_id', $type->id)->count();
        if ($grilles || $frais) {
            return response()->json([
                'message' => "Suppression impossible : « {$type->nom} » est utilisé par {$grilles} grille(s) et {$frais} frais d'élève(s). Supprimez d'abord ses grilles.",
            ], 422);
        }

        $type->delete();

        return response()->json(['message' => "Type de frais « {$type->nom} » supprimé."]);
    }

    /**
     * Modifie le montant et les echeances d'une grille. Avec `propager`, les eleves deja factures
     * par cette grille et n'ayant encore rien paye dessus recoivent le nouveau tarif ; ceux qui ont
     * deja paye (ou dont le frais a ete personnalise) gardent leur echeancier.
     */
    public function updateGrille(Request $request, $id)
    {
        $request->validate([
            'montant' => 'required|numeric|min:0',
            'echeances' => 'required|array|min:1',
            'echeances.*.libelle' => 'required|string|max:100',
            'echeances.*.montant' => 'required|numeric|min:0',
            'echeances.*.date_limite' => 'required|date',
            'propager' => 'boolean',
        ]);

        $grille = GrilleTarifaire::where('etablissement_id', $request->user()->etablissement_id)->findOrFail($id);

        $totalEcheances = collect($request->echeances)->sum(fn ($e) => (float) $e['montant']);
        if (abs($totalEcheances - (float) $request->montant) > 1) {
            return response()->json(['message' => 'Le total des échéances doit être égal au montant de la grille.'], 422);
        }

        $resultat = DB::transaction(function () use ($request, $grille) {
            $grille->update(['montant' => $request->montant]);
            $grille->echeances()->delete();
            foreach ($request->echeances as $ech) {
                $grille->echeances()->create([
                    'libelle' => $ech['libelle'],
                    'montant' => $ech['montant'],
                    'date_limite' => $ech['date_limite'],
                ]);
            }

            $misAJour = 0;
            $conserves = 0;
            if ($request->boolean('propager')) {
                $frais = FraisEleve::where('grille_tarifaire_id', $grille->id)->with('echeances:id,frais_eleve_id')->get();
                // Tout paiement, meme annule, est conserve : refaire l'echeancier l'effacerait (cascade).
                $avecPaiement = Paiement::whereIn('echeance_eleve_id', $frais->flatMap->echeances->pluck('id'))
                    ->join('echeances_eleves', 'echeances_eleves.id', '=', 'paiements.echeance_eleve_id')
                    ->distinct()
                    ->pluck('echeances_eleves.frais_eleve_id')
                    ->flip();
                foreach ($frais as $f) {
                    if (isset($avecPaiement[$f->id]) || $f->motif_personnalisation) {
                        $conserves++;
                        continue;
                    }
                    // Aucun paiement : l'echeancier est refait au nouveau tarif.
                    $f->update(['montant_total' => $request->montant, 'montant_original' => $request->montant]);
                    $f->echeances()->delete();
                    foreach ($request->echeances as $ech) {
                        $f->echeances()->create([
                            'libelle' => $ech['libelle'],
                            'montant' => $ech['montant'],
                            'date_limite' => $ech['date_limite'],
                        ]);
                    }
                    $misAJour++;
                }
            }

            return ['mis_a_jour' => $misAJour, 'conserves' => $conserves];
        });

        $message = 'Grille tarifaire mise à jour.';
        if ($request->boolean('propager')) {
            $message .= " {$resultat['mis_a_jour']} élève(s) passé(s) au nouveau tarif";
            $message .= $resultat['conserves'] ? ", {$resultat['conserves']} conservé(s) car ayant déjà payé." : '.';
        }

        return response()->json(['message' => $message] + $resultat + ['grille' => $grille->fresh('echeances')]);
    }

    /**
     * Supprime une grille et les frais qu'elle a crees, si aucun eleve n'a encore paye dessus.
     * Sinon refus : la desactiver empeche qu'elle s'applique aux nouveaux inscrits.
     */
    public function destroyGrille(Request $request, $id)
    {
        $grille = GrilleTarifaire::where('etablissement_id', $request->user()->etablissement_id)->with('classe', 'typeFrais')->findOrFail($id);

        $fraisIds = FraisEleve::where('grille_tarifaire_id', $grille->id)->pluck('id');
        // Paiements annules compris : les frais supprimes effaceraient leur trace du journal.
        $payeurs = Paiement::whereHas('echeanceEleve', fn ($q) => $q->whereIn('frais_eleve_id', $fraisIds))
            ->distinct('eleve_id')
            ->count('eleve_id');
        if ($payeurs > 0) {
            return response()->json([
                'message' => "Suppression impossible : {$payeurs} élève(s) ont déjà des paiements sur cette grille. Désactivez-la plutôt pour qu'elle ne s'applique plus aux nouveaux inscrits.",
            ], 422);
        }

        DB::transaction(function () use ($grille, $fraisIds) {
            FraisEleve::whereIn('id', $fraisIds)->delete();
            $grille->echeances()->delete();
            $grille->delete();
        });

        return response()->json([
            'message' => "Grille « {$grille->typeFrais?->nom} · {$grille->classe?->nom} » supprimée"
                . ($fraisIds->count() ? " ainsi que {$fraisIds->count()} frais d'élève(s) non payés." : '.'),
        ]);
    }

    /**
     * Annule un versement (tous ses paiements) enregistre par erreur. Les paiements restent dans le
     * journal, marques annules avec l'auteur et le motif, et ne comptent plus dans les soldes.
     */
    public function annulerPaiements(Request $request)
    {
        $request->validate([
            'paiement_ids' => 'required|array|min:1',
            'paiement_ids.*' => 'integer',
            'motif' => 'required|string|min:3|max:255',
        ], ['motif.required' => "Indiquez le motif de l'annulation.", 'motif.min' => "Indiquez le motif de l'annulation."]);

        $etablissementId = $request->user()->etablissement_id;
        $paiements = Paiement::whereIn('id', $request->paiement_ids)
            ->whereHas('eleve', fn ($q) => $q->where('etablissement_id', $etablissementId))
            ->get();

        if ($paiements->count() !== count(array_unique($request->paiement_ids))) {
            return response()->json(['message' => 'Paiement introuvable.'], 404);
        }
        if ($paiements->whereNotNull('annule_le')->isNotEmpty()) {
            return response()->json(['message' => 'Ce versement est déjà annulé.'], 422);
        }

        DB::transaction(function () use ($paiements, $request) {
            Paiement::whereIn('id', $paiements->pluck('id'))->update([
                'annule_le' => now(),
                'annule_par' => $request->user()->id,
                'motif_annulation' => trim($request->motif),
            ]);
        });

        return response()->json([
            'message' => 'Versement de ' . number_format($paiements->sum(fn ($p) => (float) $p->montant), 0, ',', ' ') . ' GNF annulé.',
        ]);
    }

    public function synchroniserGrille(Request $request, $id)
    {
        $grille = GrilleTarifaire::where('etablissement_id', $request->user()->etablissement_id)
            ->with('typeFrais')
            ->findOrFail($id);

        if ($this->estFraisParEleve($grille)) {
            return response()->json(['message' => "Les frais d'inscription se gèrent élève par élève."], 422);
        }
        if (! $grille->actif) {
            return response()->json(['message' => 'Activez la grille avant de la synchroniser.'], 422);
        }

        $crees = $this->appliquerGrilleAuxEleves($grille);

        return response()->json([
            'message' => $crees > 0
                ? "{$crees} élève(s) synchronisé(s) avec cette grille tarifaire."
                : "Tous les élèves de cette classe sont déjà à jour avec cette grille.",
            'crees' => $crees,
        ]);
    }

    /**
     * Applique a un eleve les frais d'inscription ou de reinscription de sa classe (grille du type
     * de frais donne) ET enregistre le paiement dans le meme geste : un FraisEleve (unique par
     * eleve / type / session), une echeance unique du montant total due aujourd'hui, un paiement.
     * La reference du paiement (INS-{annee}-{eleve_id}-{timestamp}) est generee ici et stockee.
     */
    public function appliquerInscription(Request $request)
    {
        $request->validate([
            'eleve_id' => 'required|integer',
            'type_frais_id' => 'required|integer',
            'montant' => 'required|numeric|min:1',
            'moyen_paiement' => 'required|in:especes,mobile_money,virement,cheque',
        ]);

        $etablissementId = $request->user()->etablissement_id;

        $eleve = \App\Models\Eleve::where('etablissement_id', $etablissementId)
            ->with('inscriptionActive.classe')
            ->findOrFail($request->eleve_id);
        $typeFrais = TypeFrais::where('etablissement_id', $etablissementId)->findOrFail($request->type_frais_id);

        $inscription = $eleve->inscriptionActive;
        if (! $inscription) {
            return response()->json(['message' => "Cet élève n'a pas d'inscription active sur la session en cours."], 422);
        }

        $grille = GrilleTarifaire::where('etablissement_id', $etablissementId)
            ->where('classe_id', $inscription->classe_id)
            ->where('session_scolaire_id', $inscription->session_scolaire_id)
            ->where('type_frais_id', $typeFrais->id)
            ->where('actif', true)
            ->first();

        if (! $grille) {
            return response()->json([
                'message' => "Aucune grille « {$typeFrais->nom} » n'existe pour la classe {$inscription->classe?->nom}.",
            ], 422);
        }

        if ((float) $request->montant > (float) $grille->montant) {
            return response()->json([
                'message' => 'Le montant dépasse les frais dus (' . (int) $grille->montant . ' GNF).',
            ], 422);
        }

        $resultat = (new FraisService())->encaisserFraisInscription(
            $inscription,
            $typeFrais,
            $grille,
            (float) $request->montant,
            $request->moyen_paiement,
            $request->user()->id
        );

        if ($resultat === null) {
            return response()->json(['message' => 'Frais déjà appliqués'], 409);
        }

        $paiement = $resultat['paiement'];
        $reste = (float) $grille->montant - (float) $paiement->montant;

        return response()->json([
            'message' => "{$typeFrais->nom} enregistrée pour {$eleve->nom} {$eleve->prenom}.",
            'frais_eleve_id' => $resultat['frais']->id,
            'paiement_id' => $paiement->id,
            'reference' => $paiement->reference,
            'type_frais' => $typeFrais->nom,
            'montant' => $grille->montant,
            'montant_paye' => $paiement->montant,
            'reste' => $reste,
            'complet' => $reste <= 0,
            'moyen_paiement' => $paiement->moyen_paiement,
            'classe' => $inscription->classe?->nom,
            'etablissement' => \App\Models\Etablissement::find($etablissementId)?->nom,
            'caissier' => $request->user()->name,
            'date' => $paiement->date_paiement,
            'heure' => $paiement->created_at?->format('H:i'),
        ], 201);
    }

    public function suiviEleve(Request $request, $eleveId)
    {
        $eleve = \App\Models\Eleve::where('etablissement_id', $request->user()->etablissement_id)->findOrFail($eleveId);

        $fraisEleves = FraisEleve::where('eleve_id', $eleve->id)
            ->with(['typeFrais', 'echeances' => fn ($q) => $q->withSum('paiements', 'montant')])->get()
            ->map(fn($fe) => [
                'id' => $fe->id,
                'type_frais' => $fe->typeFrais->nom,
                'montant_total' => $fe->montant_total,
                'echeances' => $fe->echeances->map(fn($ech) => [
                    'id' => $ech->id,
                    'libelle' => $ech->libelle,
                    'montant' => $ech->montant,
                    'date_limite' => $ech->date_limite,
                    'montant_paye' => $ech->montant_paye,
                    'solde' => $ech->solde,
                    'statut' => $ech->statut,
                ]),
            ]);

        return response()->json(['eleve' => ['id' => $eleve->id, 'nom' => $eleve->nom, 'prenom' => $eleve->prenom], 'frais' => $fraisEleves]);
    }

    public function paiements(Request $request)
    {
        $etablissementId = $request->user()->etablissement_id;

        $paiements = Paiement::whereHas('eleve', function ($q) use ($etablissementId) {
            $q->where('etablissement_id', $etablissementId);
        })
            ->with(['eleve.inscriptionActive.classe', 'echeanceEleve.fraisEleve.typeFrais', 'caissier:id,name', 'annulateur:id,name'])
            ->orderByDesc('date_paiement')
            ->orderByDesc('id')
            ->get();

        // Identifiant du versement (passage en caisse) de chaque paiement : le plus recent du groupe.
        $versementDe = [];
        foreach ($this->regrouperEnVersements($paiements) as $groupe) {
            $idVersement = max(array_map(fn ($p) => $p->id, $groupe));
            foreach ($groupe as $p) {
                $versementDe[$p->id] = $idVersement;
            }
        }

        $paiements = $paiements->map(fn($p) => [
                'id' => $p->id,
                'versement_id' => $versementDe[$p->id],
                'reference' => $p->reference,
                'montant' => $p->montant,
                'moyen_paiement' => $p->moyen_paiement,
                'date_paiement' => $p->date_paiement,
                'heure' => $p->created_at?->format('H:i'),
                'libelle' => $p->libelle,
                'type_frais' => $p->echeanceEleve?->fraisEleve?->typeFrais?->nom,
                'echeance_eleve_id' => $p->echeance_eleve_id,
                'caissier' => $p->caissier?->name,
                // Paiement annule : reste visible dans le journal mais hors des totaux.
                'annule' => $p->annule_le !== null,
                'annule_le' => $p->annule_le?->toIso8601String(),
                'annule_par' => $p->annulateur?->name,
                'motif_annulation' => $p->motif_annulation,
                'eleve' => $p->eleve ? [
                    'id' => $p->eleve->id,
                    'nom_complet' => "{$p->eleve->nom} {$p->eleve->prenom}",
                    'matricule' => $p->eleve->matricule,
                    'classe' => $p->eleve->inscriptionActive?->classe?->nom,
                ] : null,
            ]);

        return response()->json($paiements);
    }

    /**
     * Derniers versements : les paiements d'un meme passage en caisse sont regroupes en UN versement
     * (inscription/reinscription + scolarite, ou surplus reparti sur plusieurs tranches), avec le
     * total et le detail par frais. Meme passage = meme eleve et meme reference, ou enregistres a
     * moins de VERSEMENT_ECART_SECONDES d'intervalle (couvre aussi les paiements deja enregistres
     * par le flux inscription + scolarite, qui ont deux references distinctes).
     */
    private const VERSEMENT_ECART_SECONDES = 60;

    /**
     * Decoupe une liste de paiements (deja triee du plus recent au plus ancien) en versements :
     * paiements consecutifs du meme eleve, avec la meme reference ou enregistres a moins de
     * VERSEMENT_ECART_SECONDES d'intervalle. S'arrete apres $limite versements si fourni.
     */
    private function regrouperEnVersements(iterable $paiements, ?int $limite = null): array
    {
        $groupes = [];
        foreach ($paiements as $p) {
            $courant = count($groupes) ? $groupes[count($groupes) - 1] : null;
            $dernier = $courant ? $courant[count($courant) - 1] : null;
            $memePassage = $dernier
                && $dernier->eleve_id === $p->eleve_id
                && (
                    ($p->reference && $p->reference === $dernier->reference)
                    || ($p->created_at && $dernier->created_at
                        && abs($p->created_at->diffInSeconds($dernier->created_at)) <= self::VERSEMENT_ECART_SECONDES)
                );
            if ($memePassage) {
                $groupes[count($groupes) - 1][] = $p;
                continue;
            }
            if ($limite !== null && count($groupes) === $limite) {
                break;
            }
            $groupes[] = [$p];
        }

        return $groupes;
    }

    public function paiementsRecent(Request $request)
    {
        $etablissementId = $request->user()->etablissement_id;
        $nombreVersements = 5;

        // Assez de paiements pour reconstituer 5 versements (un versement en compte rarement plus de 4).
        $paiements = Paiement::valides()->whereHas('eleve', function ($q) use ($etablissementId) {
            $q->where('etablissement_id', $etablissementId);
        })
            ->with([
                'eleve.inscriptionActive.classe',
                'echeanceEleve' => fn ($q) => $q->withSum('paiements', 'montant'),
                'echeanceEleve.fraisEleve.typeFrais',
            ])
            ->orderByDesc('date_paiement')
            ->orderByDesc('id')
            ->limit($nombreVersements * 10)
            ->get();

        $groupes = $this->regrouperEnVersements($paiements, $nombreVersements);

        $versements = collect($groupes)->map(function ($groupe) {
            $groupe = collect($groupe);
            // Inscription / reinscription d'abord, puis la scolarite par tranche.
            $details = $groupe
                ->sortBy(fn ($p) => [$this->estPaiementInscription($p) ? 0 : 1, $p->id])
                ->values()
                ->map(fn ($p) => [
                    'id' => $p->id,
                    'type_frais' => $p->echeanceEleve?->fraisEleve?->typeFrais?->nom,
                    'libelle' => $p->libelle,
                    'montant' => $p->montant,
                    // Etat actuel de l'echeance reglee : soldee ou encore partielle.
                    'statut' => $p->echeanceEleve && $p->echeanceEleve->solde > 0 ? 'partiel' : 'paye',
                ]);
            $plusRecent = $groupe->sortByDesc('id')->first();
            $eleve = $plusRecent->eleve;

            return [
                'id' => $plusRecent->id,
                'reference' => $groupe->pluck('reference')->filter()->first(),
                'montant' => $groupe->sum(fn ($p) => (float) $p->montant),
                'type_frais' => $details->pluck('type_frais')->filter()->unique()->implode(' + '),
                'periode' => $details->pluck('libelle')->filter()->implode(', '),
                'statut' => $details->contains('statut', 'partiel') ? 'partiel' : 'paye',
                'details' => $details,
                'date_paiement' => $plusRecent->date_paiement,
                'heure' => $plusRecent->created_at?->format('H:i'),
                'eleve' => $eleve ? [
                    'id' => $eleve->id,
                    'nom_complet' => "{$eleve->nom} {$eleve->prenom}",
                    'classe' => $eleve->inscriptionActive?->classe?->nom,
                ] : null,
            ];
        })->values();

        return response()->json($versements);
    }

    private function estPaiementInscription(Paiement $paiement): bool
    {
        $nom = \Illuminate\Support\Str::of($paiement->echeanceEleve?->fraisEleve?->typeFrais?->nom ?? '')->ascii()->lower()->toString();

        return in_array($nom, ['inscription', 'reinscription'], true);
    }

    public function statsParClasse(Request $request)
    {
        $etablissementId = $request->user()->etablissement_id;

        // Statistiques de SCOLARITE uniquement : les frais d'inscription / reinscription sont payes
        // une fois et ne comptent ni dans les montants ni dans les statuts (meme filtre que
        // Eleve::getStatutPaiementAttribute ; "scolarit%" couvre "Scolarite" et "Scolarité").
        $classes = Classe::where('etablissement_id', $etablissementId)
            ->with([
                'eleves.fraisEleves' => fn ($q) => $q->whereHas('typeFrais', fn ($t) => $t->where('nom', 'ILIKE', 'scolarit%')),
                // Somme des paiements prechargee : montant_paye / solde / statut sans requete par echeance.
                'eleves.fraisEleves.echeances' => fn ($q) => $q->withSum('paiements', 'montant'),
            ])
            ->ordonneesPedagogiquement()
            ->get();

        $resultat = $classes->map(function ($classe) {
            $montantTotal = 0;
            $montantEncaisse = 0;
            $nombreSoldes = 0;
            $nombreEnRetard = 0;
            $nombreSansFrais = 0;

            foreach ($classe->eleves as $eleve) {
                $echeances = $eleve->fraisEleves->flatMap->echeances;

                if ($echeances->isEmpty()) {
                    $nombreSansFrais++;
                    continue;
                }

                $montantTotal += $echeances->sum('montant');
                $montantEncaisse += $echeances->sum('montant_paye');

                // Meme calcul que le statut de l'eleve (regle du 10 comprise).
                $aDuRetard = \App\Models\Eleve::calculerStatutPaiement(
                    $echeances,
                    \App\Models\Eleve::dateLimiteSansPaiement($eleve->fraisEleves->first()?->session_scolaire_id)
                ) === 'en_retard';
                $estSolde = $echeances->every(fn($e) => $e->solde <= 0);

                if ($aDuRetard) {
                    $nombreEnRetard++;
                } elseif ($estSolde) {
                    $nombreSoldes++;
                }
            }

            return [
                'classe_id' => $classe->id,
                'classe' => $classe->nom,
                'niveau' => $classe->niveau,
                'nombre_eleves' => $classe->eleves->count(),
                'montant_total' => $montantTotal,
                'montant_encaisse' => $montantEncaisse,
                'nombre_soldes' => $nombreSoldes,
                'nombre_en_retard' => $nombreEnRetard,
                'nombre_sans_frais' => $nombreSansFrais,
            ];
        })->values();

        return response()->json($resultat);
    }

    public function enregistrerPaiement(Request $request)
    {
        $request->validate([
            'echeance_eleve_id' => 'required|exists:echeances_eleves,id',
            'montant' => 'required|numeric|min:1',
            'moyen_paiement' => 'required|in:especes,mobile_money,virement,cheque',
            'date_paiement' => 'required|date',
        ]);

        $etablissementId = $request->user()->etablissement_id;

        $echeance = \App\Models\EcheanceEleve::whereHas(
            'fraisEleve.eleve',
            fn($q) => $q->where('etablissement_id', $etablissementId)
        )->findOrFail($request->echeance_eleve_id);

        // Un versement peut couvrir plusieurs echeances (ex. toute l'annee en une fois) : il solde
        // d'abord l'echeance choisie, puis le surplus est reparti sur les autres echeances non
        // soldees du meme frais, par date limite. Un paiement (meme reference) par echeance touchee.
        $resultat = DB::transaction(function () use ($request, $echeance) {
            $echeances = \App\Models\EcheanceEleve::where('frais_eleve_id', $echeance->frais_eleve_id)
                ->orderBy('date_limite')
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->sortBy(fn($e) => $e->id === $echeance->id ? 0 : 1)
                ->values();

            $resteTotal = $echeances->sum(fn($e) => max(0, (float) $e->solde));
            $montant = (float) $request->montant;

            if ($montant > $resteTotal) {
                return ['erreur' => 'Le montant depasse le reste total a payer sur ces frais (' . (int) $resteTotal . ' GNF).'];
            }

            $eleveId = $echeance->fraisEleve->eleve_id;
            $reference = 'PAY-' . now()->year . '-' . $eleveId . '-' . now()->timestamp;
            $paiements = [];
            foreach ($echeances as $ech) {
                if ($montant <= 0) {
                    break;
                }
                $solde = max(0, (float) $ech->solde);
                if ($solde <= 0) {
                    continue;
                }
                $part = min($solde, $montant);
                $paiements[] = Paiement::create([
                    'eleve_id' => $eleveId,
                    'echeance_eleve_id' => $ech->id,
                    'libelle' => $ech->libelle,
                    'montant' => $part,
                    'moyen_paiement' => $request->moyen_paiement,
                    'date_paiement' => $request->date_paiement,
                    'reference' => $reference,
                    'caissier_id' => $request->user()->id,
                ]);
                $montant -= $part;
            }

            return ['paiements' => $paiements, 'reference' => $reference];
        });

        if (isset($resultat['erreur'])) {
            return response()->json(['message' => $resultat['erreur']], 422);
        }

        $premier = $resultat['paiements'][0];

        // Champs du premier paiement conserves a la racine pour la compatibilite ; `montant` est le
        // total verse et `paiements` le detail par echeance (pour le recu).
        return response()->json([
            'id' => $premier->id,
            'reference' => $resultat['reference'],
            'montant' => collect($resultat['paiements'])->sum('montant'),
            'moyen_paiement' => $premier->moyen_paiement,
            'date_paiement' => $premier->date_paiement,
            'created_at' => $premier->created_at,
            'paiements' => collect($resultat['paiements'])->map(fn($p) => [
                'id' => $p->id,
                'echeance_eleve_id' => $p->echeance_eleve_id,
                'libelle' => $p->libelle,
                'montant' => (float) $p->montant,
            ])->values(),
        ], 201);
    }
}

