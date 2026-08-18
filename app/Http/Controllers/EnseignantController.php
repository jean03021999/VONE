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
            ->with(['contratActif', 'affectations.matiere']);

        if ($request->filled('recherche')) {
            $recherche = $request->recherche;
            $query->where(function ($q) use ($recherche) {
                $q->where('nom', 'like', "%{$recherche}%")
                    ->orWhere('prenom', 'like', "%{$recherche}%")
                    ->orWhere('matricule', 'like', "%{$recherche}%");
            });
        }

        $enseignants = $query->get()->map(function ($e) {
            return [
                'id' => $e->id,
                'nom' => $e->nom,
                'prenom' => $e->prenom,
                'matricule' => $e->matricule,
                'matieres' => $e->affectations->pluck('matiere.nom')->unique()->filter()->values(),
                'type_contrat' => $e->contratActif?->type,
                'statut_contrat' => $e->contratActif?->statut ?? 'aucun',
            ];
        });

        return response()->json([
            'enseignants' => $enseignants,
            'stats' => [
                'total' => $enseignants->count(),
                'actifs' => $enseignants->where('statut_contrat', 'actif')->count(),
            ],
        ]);
    }

    public function show(Request $request, $id)
    {
        $etablissementId = $request->user()->etablissement_id;

        $enseignant = Enseignant::where('etablissement_id', $etablissementId)
            ->with(['contrats', 'affectations.classe', 'affectations.matiere'])
            ->findOrFail($id);

        return response()->json($enseignant);
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

