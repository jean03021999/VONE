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
            ->get()
            ->map(function ($grille) {
                $nombreElevesClasse = \App\Models\Eleve::whereHas('inscriptionActive', fn($q) => $q->where('classe_id', $grille->classe_id)->where('statut', 'active'))->count();
                $nombreCouverts = FraisEleve::where('type_frais_id', $grille->type_frais_id)
                    ->where('session_scolaire_id', $grille->session_scolaire_id)
                    ->whereHas('eleve.inscriptionActive', fn($q) => $q->where('classe_id', $grille->classe_id))
                    ->count();

                $grille->nombre_eleves_classe = $nombreElevesClasse;
                $grille->nombre_eleves_couverts = $nombreCouverts;
                return $grille;
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
        ]);

        $etablissementId = $request->user()->etablissement_id;
        $classe = Classe::findOrFail($request->classe_id);

        $grilleExistante = GrilleTarifaire::where('classe_id', $request->classe_id)
            ->where('type_frais_id', $request->type_frais_id)
            ->where('session_scolaire_id', $classe->session_scolaire_id)
            ->first();

        if ($grilleExistante) {
            return response()->json([
                'message' => 'Une grille tarifaire existe deja pour cette classe et ce type de frais sur cette session scolaire.',
            ], 422);
        }

        $grille = GrilleTarifaire::create([
            'etablissement_id' => $etablissementId,
            'session_scolaire_id' => $classe->session_scolaire_id,
            'classe_id' => $request->classe_id,
            'type_frais_id' => $request->type_frais_id,
            'montant' => $request->montant,
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
        if ($this->estFraisParEleve($grille)) {
            return 0;
        }
        $crees = 0;

        $service = new FraisService();
        $eleves = \App\Models\Eleve::whereHas('inscriptionActive', fn($q) => $q->where('classe_id', $grille->classe_id)->where('statut', 'active'))
            ->with('inscriptionActive')
            ->get();
        foreach ($eleves as $eleve) {
            if ($service->creerFraisDepuisGrille($eleve->id, $grille, $eleve->inscriptionActive?->id)) {
                $crees++;
            }
        }

        return $crees;
    }

    public function synchroniserGrille(Request $request, $id)
    {
        $grille = GrilleTarifaire::where('etablissement_id', $request->user()->etablissement_id)
            ->with('typeFrais')
            ->findOrFail($id);

        if ($this->estFraisParEleve($grille)) {
            return response()->json(['message' => "Les frais d'inscription se gèrent élève par élève."], 422);
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

        $resultat = DB::transaction(function () use ($request, $eleve, $typeFrais, $inscription, $grille) {
            $frais = FraisEleve::firstOrCreate(
                [
                    'eleve_id' => $eleve->id,
                    'type_frais_id' => $typeFrais->id,
                    'session_scolaire_id' => $inscription->session_scolaire_id,
                ],
                [
                    'montant_total' => $grille->montant,
                    'montant_original' => $grille->montant,
                    'inscription_id' => $inscription->id,
                    'grille_tarifaire_id' => $grille->id,
                ]
            );

            if (! $frais->wasRecentlyCreated) {
                return null;
            }

            $echeance = $frais->echeances()->create([
                'libelle' => $typeFrais->nom,
                'montant' => $grille->montant,
                'date_limite' => today()->toDateString(),
            ]);

            $paiement = Paiement::create([
                'eleve_id' => $eleve->id,
                'echeance_eleve_id' => $echeance->id,
                'libelle' => $echeance->libelle,
                'montant' => $request->montant,
                'moyen_paiement' => $request->moyen_paiement,
                'date_paiement' => today()->toDateString(),
                'reference' => 'INS-' . now()->year . '-' . $eleve->id . '-' . now()->timestamp,
                'caissier_id' => $request->user()->id,
            ]);

            return compact('frais', 'paiement');
        });

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
            ->with(['eleve.inscriptionActive.classe', 'echeanceEleve.fraisEleve.typeFrais'])
            ->orderByDesc('date_paiement')
            ->orderByDesc('id')
            ->get()
            ->map(fn($p) => [
                'id' => $p->id,
                'montant' => $p->montant,
                'moyen_paiement' => $p->moyen_paiement,
                'date_paiement' => $p->date_paiement,
                'heure' => $p->created_at?->format('H:i'),
                'libelle' => $p->libelle,
                'type_frais' => $p->echeanceEleve?->fraisEleve?->typeFrais?->nom,
                'eleve' => $p->eleve ? [
                    'id' => $p->eleve->id,
                    'nom_complet' => "{$p->eleve->nom} {$p->eleve->prenom}",
                    'matricule' => $p->eleve->matricule,
                    'classe' => $p->eleve->inscriptionActive?->classe?->nom,
                ] : null,
            ]);

        return response()->json($paiements);
    }

    public function paiementsRecent(Request $request)
    {
        $etablissementId = $request->user()->etablissement_id;

        $paiements = Paiement::whereHas('eleve', function ($q) use ($etablissementId) {
            $q->where('etablissement_id', $etablissementId);
        })
            ->with('eleve.inscriptionActive.classe')
            ->orderByDesc('date_paiement')
            ->orderByDesc('id')
            ->limit(5)
            ->get()
            ->map(fn($p) => [
                'id' => $p->id,
                'montant' => $p->montant,
                'periode' => $p->libelle,
                'date_paiement' => $p->date_paiement,
                'heure' => $p->created_at?->format('H:i'),
                'eleve' => $p->eleve ? [
                    'id' => $p->eleve->id,
                    'nom_complet' => "{$p->eleve->nom} {$p->eleve->prenom}",
                    'classe' => $p->eleve->inscriptionActive?->classe?->nom,
                ] : null,
            ]);

        return response()->json($paiements);
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

                $aDuRetard = $echeances->contains(fn($e) => $e->statut === 'en_retard');
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

