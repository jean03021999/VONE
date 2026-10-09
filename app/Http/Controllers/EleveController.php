<?php

namespace App\Http\Controllers;

use App\Services\Numerotation;
use App\Models\Eleve;
use App\Models\EleveFiliation;
use App\Services\InscriptionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class EleveController extends Controller
{
    public function index(Request $request)
    {
        $etablissementId = $request->user()->etablissement_id;

        $query = Eleve::where('etablissement_id', $etablissementId)
            ->with('inscriptionActive.classe', 'inscriptionActive.sessionScolaire');

        if ($request->filled('classe_id')) {
            $query->whereHas('inscriptionActive', fn ($q) => $q->where('classe_id', $request->classe_id));
        }

        if ($request->filled('recherche')) {
            $recherche = $request->recherche;
            $query->where(function ($q) use ($recherche) {
                $q->where('nom', 'like', "%{$recherche}%")
                    ->orWhere('prenom', 'like', "%{$recherche}%")
                    ->orWhere('matricule', 'like', "%{$recherche}%");
            });
        }

        $eleves = $query->get();

        // Eleve reellement inscrit = au moins un paiement NON annule sur ses frais d'inscription ou de
        // reinscription de la session active (ni l'inscription "active" seule, venue d'un import, ni
        // des frais dont le paiement a ete annule ne suffisent).
        $inscriptionReglee = \App\Models\FraisEleve::whereIn('eleve_id', $eleves->pluck('id'))
            ->whereIn('session_scolaire_id', \App\Models\SessionScolaire::where('etablissement_id', $etablissementId)
                ->where('est_active', true)
                ->pluck('id'))
            ->whereHas('typeFrais', fn ($q) => $q->where('nom', 'ILIKE', 'inscription')->orWhere('nom', 'ILIKE', 'r_inscription'))
            ->whereHas('echeances.paiements')
            ->with('typeFrais:id,nom')
            ->get()
            ->mapWithKeys(fn ($f) => [
                $f->eleve_id => str_starts_with(strtolower(\Illuminate\Support\Str::ascii($f->typeFrais->nom)), 're') ? 'reinscription' : 'inscription',
            ]);

        // Statuts de paiement : echeances de scolarite de tous les eleves en une requete.
        $scolarite = Eleve::echeancesScolariteDe($eleves->pluck('id'));
        $aujourdhui = today()->toDateString();

        $eleves = $eleves->map(function ($eleve) use ($inscriptionReglee, $scolarite, $aujourdhui) {
            $echeances = $scolarite[$eleve->id]['echeances'] ?? collect();
            $sessionId = $scolarite[$eleve->id]['session_id'] ?? null;
            $statutPaiement = Eleve::statutDepuis($echeances, $sessionId, $aujourdhui);

            return [
                'id' => $eleve->id,
                'nom' => $eleve->nom,
                'prenom' => $eleve->prenom,
                'matricule' => $eleve->matricule,
                'date_naissance' => $eleve->date_naissance,
                'lieu_naissance' => $eleve->lieu_naissance,
                'classe' => $eleve->inscriptionActive?->classe?->nom,
                'classe_id' => $eleve->inscriptionActive?->classe_id,
                'inscription_active' => $eleve->inscriptionActive ? [
                    'type_inscription' => $eleve->inscriptionActive->type_inscription,
                    'statut' => $eleve->inscriptionActive->statut,
                    'session_scolaire' => $eleve->inscriptionActive->sessionScolaire ? [
                        'libelle' => $eleve->inscriptionActive->sessionScolaire->libelle,
                    ] : null,
                ] : null,
                'photo_path' => $eleve->photo_path,
                'statut_dossier' => $eleve->statut_dossier,
                // Fiche a completer : date de naissance absente (import d'un registre sans dates).
                'fiche_incomplete' => $eleve->date_naissance === null,
                'statut_paiement' => $statutPaiement,
                // 'inscription' | 'reinscription' | null (frais d'inscription pas encore enregistres)
                'inscription_reglee' => $inscriptionReglee[$eleve->id] ?? null,
                // { echeance, date_limite, nombre_echeances, montant_du } pour un eleve en retard
                'retard' => $statutPaiement === 'en_retard' ? Eleve::detailRetardDepuis($echeances, $sessionId, $aujourdhui) : null,
            ];
        });

        $total = $eleves->count();
        $aJour = $eleves->where('statut_paiement', 'a_jour')->count();
        $enRetard = $eleves->where('statut_paiement', 'en_retard')->count();
        $partiel = $eleves->where('statut_paiement', 'partiel')->count();
        $aEchoir = $eleves->where('statut_paiement', 'a_echoir')->count();
        $inscrits = $eleves->whereNotNull('inscription_reglee')->count();

        // Filtre facultatif par statut (ex. ?statut_paiement=en_retard) : les stats restent calculees
        // sur l'ensemble des eleves.
        if ($request->filled('statut_paiement')) {
            $eleves = $eleves->where('statut_paiement', $request->statut_paiement)->values();
        }

        return response()->json([
            'eleves' => $eleves,
            'etablissement' => $request->user()->etablissement?->nom,
            'stats' => [
                'total' => $total,
                'a_jour' => $aJour,
                'en_retard' => $enRetard,
                'partiel' => $partiel,
                'a_echoir' => $aEchoir,
                'inscrits' => $inscrits,
            ],
        ]);
    }

    public function show(Request $request, $id)
    {
        $etablissementId = $request->user()->etablissement_id;

        $eleve = Eleve::where('etablissement_id', $etablissementId)
            ->with([
                'etablissement:id,nom,ville,adresse,telephone,email',
                'inscriptionActive.classe',
                'inscriptionActive.sessionScolaire',
                'filiations',
                'fraisEleves.typeFrais:id,nom',
                'fraisEleves.echeances.paiements',
            ])
            ->findOrFail($id);

        // Statut global (meme calcul que la liste) pour la fiche et le releve imprime.
        $eleve->append('statut_paiement');

        // Historique des operations sensibles (annulation d'inscription...).
        $eleve->setAttribute('historique', \App\Models\HistoriqueEleve::where('eleve_id', $eleve->id)
            ->with('auteur:id,name')->orderByDesc('created_at')->get()
            ->map(fn ($h) => [
                'date' => $h->created_at?->toDateTimeString(),
                'description' => $h->description,
                'montant' => $h->montant,
                'motif' => $h->motif,
                'par' => $h->auteur?->name,
            ]));

        return response()->json($eleve);
    }

    public function store(Request $request)
    {
        $request->validate([
            'nom' => 'required|string',
            'prenom' => 'required|string',
            'date_naissance' => 'required|date',
            'lieu_naissance' => 'nullable|string',
            'classe_id' => 'required|exists:classes,id',
        ]);

        $etablissementId = $request->user()->etablissement_id;
        $classe = \App\Models\Classe::findOrFail($request->classe_id);

        $eleve = DB::transaction(function () use ($request, $etablissementId, $classe) {
            $matricule = Numerotation::matriculeEleve($etablissementId);

            $eleve = Eleve::create([
                'etablissement_id' => $etablissementId,
                'nom' => $request->nom,
                'prenom' => $request->prenom,
                'matricule' => $matricule,
                'date_naissance' => $request->date_naissance,
                'lieu_naissance' => $request->lieu_naissance,
                'statut_dossier' => 'photo_manquante',
            ]);

            (new InscriptionService())->inscrire($eleve, $classe);

            if ($request->filled('pere_nom')) {
                $eleve->filiations()->create([
                    'type_lien' => 'pere',
                    'nom_complet' => $request->pere_nom,
                    'telephone' => $request->pere_telephone,
                ]);
            }
            if ($request->filled('mere_nom')) {
                $eleve->filiations()->create([
                    'type_lien' => 'mere',
                    'nom_complet' => $request->mere_nom,
                    'telephone' => $request->mere_telephone,
                ]);
            }
            if ($request->filled('tuteur_nom')) {
                $eleve->filiations()->create([
                    'type_lien' => 'tuteur',
                    'nom_complet' => $request->tuteur_nom,
                    'telephone' => $request->tuteur_telephone,
                    'lien_avec_eleve' => $request->tuteur_lien,
                ]);
            }

            return $eleve;
        });

        return response()->json($eleve->load('inscriptionActive.classe', 'filiations'), 201);
    }

    public function update(Request $request, $id)
    {
        $request->validate([
            'nom' => 'sometimes|string',
            'prenom' => 'sometimes|string',
            'date_naissance' => 'sometimes|date',
            'lieu_naissance' => 'nullable|string',
            'classe_id' => 'sometimes|exists:classes,id',
            'correction' => 'sometimes|boolean',
            'motif' => 'sometimes|string',
            // Filiation : un nom vide retire le responsable correspondant.
            'pere_nom' => 'nullable|string|max:150',
            'pere_telephone' => 'nullable|string|max:40',
            'mere_nom' => 'nullable|string|max:150',
            'mere_telephone' => 'nullable|string|max:40',
            'tuteur_nom' => 'nullable|string|max:150',
            'tuteur_telephone' => 'nullable|string|max:40',
            'tuteur_lien' => 'nullable|string|max:60',
        ]);

        $etablissementId = $request->user()->etablissement_id;
        $eleve = Eleve::where('etablissement_id', $etablissementId)->findOrFail($id);

        $champsIdentitaires = $request->only(['nom', 'prenom', 'date_naissance', 'lieu_naissance']);

        return DB::transaction(function () use ($request, $eleve, $champsIdentitaires, $etablissementId) {
            $message = null;

            if ($request->filled('classe_id')) {
                $inscriptionActive = $eleve->inscriptionActive;
                $nouvelleClasse = \App\Models\Classe::where('etablissement_id', $etablissementId)->findOrFail($request->classe_id);
                $service = new InscriptionService();

                if ($request->boolean('correction')) {
                    $service->corrigerClasse($eleve, $nouvelleClasse);
                    $message = 'Correction administrative effectuée';
                } elseif (! $inscriptionActive || $nouvelleClasse->session_scolaire_id !== $inscriptionActive->session_scolaire_id) {
                    $service->reinscrire($eleve, $nouvelleClasse);
                    $message = 'Réinscription effectuée';
                } else {
                    if (! $request->filled('motif')) {
                        return response()->json(['message' => 'Le motif est obligatoire pour un changement de classe'], 422);
                    }
                    $service->changerClasse($eleve, $nouvelleClasse, $request->motif);
                    $message = 'Changement de classe effectué';
                }

                // Frais : remplaces par ceux de la grille de la nouvelle classe (sauf deja payes).
                $r = $service->fraisRealignes;
                if ($r['remplaces'] > 0) {
                    $message .= " · frais mis à jour selon la grille de {$nouvelleClasse->nom}";
                }
                if ($r['conserves']) {
                    $message .= ' · à ajuster dans Frais de scolarité : ' . implode(', ', $r['conserves']);
                }
            }

            if (! empty($champsIdentitaires)) {
                $eleve->update($champsIdentitaires);
            }

            // Filiation : mise a jour seulement des responsables envoyes par le formulaire.
            foreach (['pere', 'mere', 'tuteur'] as $type) {
                if (! $request->has("{$type}_nom")) {
                    continue;
                }
                $nom = trim((string) $request->input("{$type}_nom"));
                if ($nom === '') {
                    $eleve->filiations()->where('type_lien', $type)->delete();
                    continue;
                }
                $eleve->filiations()->updateOrCreate(
                    ['type_lien' => $type],
                    [
                        'nom_complet' => $nom,
                        'telephone' => $request->input("{$type}_telephone") ?: null,
                        'lien_avec_eleve' => $type === 'tuteur' ? ($request->input('tuteur_lien') ?: null) : null,
                    ]
                );
            }

            $eleve = $eleve->fresh(['inscriptionActive.classe', 'filiations']);

            return $message
                ? response()->json(['message' => $message, 'eleve' => $eleve])
                : response()->json($eleve);
        });
    }

    /**
     * Suppression d'un eleve saisi par erreur. Refusee des qu'il a un historique (paiement, note,
     * bulletin) : la caisse et les resultats doivent rester justes. Sinon ses frais sans paiement
     * sont retires, son inscription annulee (il ne compte plus dans les effectifs) et la fiche
     * archivee (suppression douce, restaurable en base).
     */
    public function destroy(Request $request, $id)
    {
        $etablissementId = $request->user()->etablissement_id;
        $eleve = Eleve::where('etablissement_id', $etablissementId)->findOrFail($id);

        // Seuls les paiements NON annules bloquent : un doublon dont les paiements ont ete annules au
        // journal peut etre supprime (ses paiements annules restent visibles au journal).
        if (\App\Models\Paiement::valides()->where('eleve_id', $eleve->id)->exists()) {
            return response()->json([
                'message' => 'Cet élève a des paiements actifs. Annulez-les d\'abord dans le Journal de caisse pour pouvoir le supprimer.',
            ], 422);
        }
        $historique = array_filter([
            \App\Models\Note::where('eleve_id', $eleve->id)->whereNotNull('valeur')->exists() ? 'des notes' : null,
            \App\Models\Bulletin::where('eleve_id', $eleve->id)->exists() ? 'des bulletins' : null,
        ]);
        if ($historique) {
            return response()->json([
                'message' => 'Suppression impossible : cet élève a ' . implode(', ', $historique) . ' enregistrés. '
                    . 'Pour qu\'il ne soit plus compté, changez plutôt sa classe ou son inscription.',
            ], 422);
        }

        DB::transaction(function () use ($eleve) {
            \App\Models\FraisEleve::where('eleve_id', $eleve->id)->delete();
            \App\Models\Note::where('eleve_id', $eleve->id)->delete();
            \App\Models\Inscription::where('eleve_id', $eleve->id)->update(['statut' => 'annulee']);
            $eleve->delete();
        });

        return response()->json(['message' => "Élève {$eleve->nom} {$eleve->prenom} supprimé."]);
    }
}



