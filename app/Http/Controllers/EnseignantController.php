<?php

namespace App\Http\Controllers;

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
        do {
            $dernier = Enseignant::withTrashed()->where('matricule', 'like', 'ENS-' . date('Y') . '-%')->count();
            $matricule = 'ENS-' . date('Y') . '-' . str_pad($dernier + 1, 3, '0', STR_PAD_LEFT);
            $dernier++;
        } while (Enseignant::withTrashed()->where('matricule', $matricule)->exists());

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

        $enseignant->update($request->only([
            'nom', 'prenom', 'date_naissance', 'lieu_naissance', 'diplome', 'telephone', 'email',
        ]));

        return response()->json($enseignant);
    }
}

