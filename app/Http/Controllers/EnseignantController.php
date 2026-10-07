<?php

namespace App\Http\Controllers;

use App\Services\Numerotation;
use App\Models\Enseignant;
use App\Models\Contrat;
use Illuminate\Http\Request;

class EnseignantController extends Controller
{
    public function index(Request $request)
    {
        $etablissementId = $request->user()->etablissement_id;

        $query = Enseignant::where('etablissement_id', $etablissementId)
            ->with(['contratActif', 'affectations.matiere', 'affectations.classe']);

        if ($request->filled('recherche')) {
            $recherche = $request->recherche;
            $query->where(function ($q) use ($recherche) {
                $q->where('nom', 'like', "%{$recherche}%")
                    ->orWhere('prenom', 'like', "%{$recherche}%")
                    ->orWhere('matricule', 'like', "%{$recherche}%");
            });
        }

        $enseignants = $query->orderBy('nom')->orderBy('prenom')->get()->map(function ($e) {
            $contrat = $e->contratActif;

            return [
                'id' => $e->id,
                'nom' => $e->nom,
                'prenom' => $e->prenom,
                'matricule' => $e->matricule,
                'telephone' => $e->telephone,
                'email' => $e->email,
                'diplome' => $e->diplome,
                'matieres' => $e->affectations->pluck('matiere.nom')->unique()->filter()->values(),
                // Classes enseignees (une fois chacune), avec indicateur de classe d'examen.
                'classes' => $e->affectations
                    ->filter(fn ($a) => $a->classe)
                    ->groupBy('classe_id')
                    ->map(fn ($groupe) => [
                        'nom' => $groupe->first()->classe->nom,
                        'examen' => (bool) $groupe->contains('est_classe_examen', true),
                    ])
                    ->values(),
                'volume_horaire' => (float) $e->affectations->sum('volume_horaire_hebdomadaire'),
                'type_contrat' => $contrat?->type,
                'statut_contrat' => $contrat?->statut ?? 'aucun',
                'date_debut_contrat' => $contrat?->date_debut,
                'date_fin_contrat' => $contrat?->date_fin,
                'salaire_base' => $contrat ? (float) $contrat->salaire_base : null,
                'taux_horaire_heures_sup' => $contrat?->taux_horaire_heures_sup !== null ? (float) $contrat->taux_horaire_heures_sup : null,
                'a_un_compte' => $e->user_id !== null,
            ];
        });

        return response()->json([
            'enseignants' => $enseignants,
            'stats' => [
                'total' => $enseignants->count(),
                'actifs' => $enseignants->where('statut_contrat', 'actif')->count(),
                'heures_hebdo' => (float) $enseignants->sum('volume_horaire'),
                // Somme des salaires de base des contrats actifs (hors heures sup et vacations).
                'masse_salariale' => (float) $enseignants->where('statut_contrat', 'actif')->sum('salaire_base'),
            ],
        ]);
    }

    public function show(Request $request, $id)
    {
        $etablissementId = $request->user()->etablissement_id;

        $enseignant = Enseignant::where('etablissement_id', $etablissementId)
            ->with(['contrats', 'affectations.classe', 'affectations.matiere', 'affectations.creneaux'])
            ->findOrFail($id);

        // Statistiques pedagogiques reelles : evaluations creees sur ses affectations et moyenne
        // des notes (ramenee sur 20 selon le bareme), eleves presents uniquement.
        $affectationIds = $enseignant->affectations->pluck('id');
        $evaluations = \App\Models\Evaluation::whereIn('affectation_id', $affectationIds)
            ->where('statut', '!=', 'annulee')
            ->get(['id', 'statut', 'bareme']);
        $moyenne = \App\Models\Note::whereIn('evaluation_id', $evaluations->pluck('id'))
            ->where('statut_presence', 'present')
            ->whereNotNull('valeur')
            ->join('evaluations', 'evaluations.id', '=', 'notes.evaluation_id')
            ->where('evaluations.bareme', '>', 0)
            ->selectRaw('AVG(notes.valeur * 20.0 / evaluations.bareme) as moyenne, COUNT(*) as nombre')
            ->first();

        $donnees = $enseignant->toArray();
        $donnees['statistiques'] = [
            'evaluations' => $evaluations->count(),
            'evaluations_validees' => $evaluations->whereIn('statut', ['valide', 'publie', 'archive'])->count(),
            'notes' => (int) ($moyenne->nombre ?? 0),
            'moyenne' => $moyenne && $moyenne->moyenne !== null ? round((float) $moyenne->moyenne, 2) : null,
        ];

        return response()->json($donnees);
    }

    public function store(Request $request)
    {
        $request->validate([
            'nom' => 'required|string',
            'prenom' => 'required|string',
            'date_naissance' => 'required|date',
            'diplome' => 'nullable|string',
            'telephone' => 'nullable|string',
            'email' => 'nullable|email',
            'type_contrat' => 'required|in:cdi,cdd,vacataire',
            'salaire_base' => 'required|numeric',
            'date_debut_contrat' => 'required|date',
            'date_fin_contrat' => 'nullable|date|after_or_equal:date_debut_contrat',
            'taux_horaire_heures_sup' => 'nullable|numeric|min:0',
        ]);

        $etablissementId = $request->user()->etablissement_id;
        $matricule = Numerotation::matriculeEnseignant($etablissementId);

        $enseignant = Enseignant::create([
            'etablissement_id' => $etablissementId,
            'nom' => $request->nom,
            'prenom' => $request->prenom,
            'matricule' => $matricule,
            'date_naissance' => $request->date_naissance,
            'lieu_naissance' => $request->lieu_naissance,
            'diplome' => $request->diplome,
            'telephone' => $request->telephone,
            'email' => $request->email,
        ]);

        Contrat::create([
            'enseignant_id' => $enseignant->id,
            'type' => $request->type_contrat,
            'date_debut' => $request->date_debut_contrat,
            'date_fin' => $request->date_fin_contrat,
            'salaire_base' => $request->salaire_base,
            'taux_horaire_heures_sup' => $request->taux_horaire_heures_sup,
            'statut' => 'actif',
        ]);

        return response()->json($enseignant->load('contrats'), 201);
    }

    public function update(Request $request, $id)
    {
        $etablissementId = $request->user()->etablissement_id;
        $enseignant = Enseignant::where('etablissement_id', $etablissementId)->findOrFail($id);

        $request->validate([
            'nom' => 'sometimes|required|string|max:100',
            'prenom' => 'sometimes|required|string|max:100',
            'date_naissance' => 'sometimes|required|date',
            'lieu_naissance' => 'nullable|string|max:150',
            'diplome' => 'nullable|string|max:150',
            'telephone' => 'nullable|string|max:40',
            'email' => 'nullable|email|max:150',
        ], ['email.email' => 'Adresse e-mail invalide.', 'date_naissance.date' => 'Date de naissance invalide.']);

        $enseignant->update($request->only([
            'nom', 'prenom', 'date_naissance', 'lieu_naissance', 'diplome', 'telephone', 'email',
        ]));

        return response()->json($enseignant);
    }


    /**
     * Modifie le contrat actif de l'enseignant (ou en cree un s'il n'en a pas). Le salaire de base
     * sert de proposition pour les prochains salaires ; les salaires deja saisis ne changent pas.
     */
    public function updateContrat(Request $request, $id)
    {
        $enseignant = Enseignant::where('etablissement_id', $request->user()->etablissement_id)->findOrFail($id);
        $donnees = $request->validate([
            'type' => 'required|in:cdi,cdd,vacataire',
            'date_debut' => 'required|date',
            'date_fin' => 'nullable|date|after_or_equal:date_debut',
            'salaire_base' => 'required|numeric|min:0',
            'taux_horaire_heures_sup' => 'nullable|numeric|min:0',
        ], ['date_fin.after_or_equal' => 'La date de fin doit être après la date de début.']);

        $contrat = $enseignant->contratActif;
        if ($contrat) {
            $contrat->update($donnees);
        } else {
            $contrat = Contrat::create($donnees + ['enseignant_id' => $enseignant->id, 'statut' => 'actif']);
        }

        return response()->json($contrat->fresh());
    }

    /**
     * Supprime un enseignant saisi par erreur. Refus s'il a des affectations (classes, emplois du
     * temps, notes en dependent) ou des salaires. Sinon fiche archivee (suppression douce) et son
     * eventuel compte de connexion suspendu.
     */
    public function destroy(Request $request, $id)
    {
        $enseignant = Enseignant::where('etablissement_id', $request->user()->etablissement_id)->findOrFail($id);

        $liens = array_filter([
            ($n = $enseignant->affectations()->count()) ? "{$n} affectation(s)" : null,
            ($n = \App\Models\Salaire::where('enseignant_id', $enseignant->id)->count()) ? "{$n} salaire(s)" : null,
        ]);
        if ($liens) {
            return response()->json([
                'message' => "Suppression impossible : {$enseignant->prenom} {$enseignant->nom} a " . implode(' et ', $liens) . '. Retirez d\'abord ses affectations ; un enseignant payé reste dans l\'historique.',
            ], 422);
        }

        \Illuminate\Support\Facades\DB::transaction(function () use ($enseignant) {
            $enseignant->contrats()->where('statut', 'actif')->update(['statut' => 'termine']);
            if ($enseignant->user_id) {
                $compte = \App\Models\User::find($enseignant->user_id);
                $compte?->update(['statut' => 'suspendu']);
                $compte?->tokens()->delete();
            }
            $enseignant->delete();
        });

        return response()->json(['message' => "Enseignant {$enseignant->prenom} {$enseignant->nom} supprimé."]);
    }
}
