<?php

namespace App\Http\Controllers;

use App\Models\Eleve;
use App\Models\EleveFiliation;
use Illuminate\Http\Request;

class EleveController extends Controller
{
    public function index(Request $request)
    {
        $etablissementId = $request->user()->etablissement_id;

        $query = Eleve::where('etablissement_id', $etablissementId)
            ->with('classe');

        if ($request->filled('classe_id')) {
            $query->where('classe_id', $request->classe_id);
        }

        if ($request->filled('recherche')) {
            $recherche = $request->recherche;
            $query->where(function ($q) use ($recherche) {
                $q->where('nom', 'like', "%{$recherche}%")
                    ->orWhere('prenom', 'like', "%{$recherche}%")
                    ->orWhere('matricule', 'like', "%{$recherche}%");
            });
        }

        $eleves = $query->get()->map(function ($eleve) {
            return [
                'id' => $eleve->id,
                'nom' => $eleve->nom,
                'prenom' => $eleve->prenom,
                'matricule' => $eleve->matricule,
                'classe' => $eleve->classe?->nom,
                'photo_path' => $eleve->photo_path,
                'statut_dossier' => $eleve->statut_dossier,
                'statut_paiement' => $eleve->statut_paiement,
            ];
        });

        $total = $eleves->count();
        $aJour = $eleves->where('statut_paiement', 'a_jour')->count();
        $enRetard = $eleves->where('statut_paiement', 'en_retard')->count();

        return response()->json([
            'eleves' => $eleves,
            'stats' => [
                'total' => $total,
                'a_jour' => $aJour,
                'en_retard' => $enRetard,
            ],
        ]);
    }

    public function show(Request $request, $id)
    {
        $etablissementId = $request->user()->etablissement_id;

        $eleve = Eleve::where('etablissement_id', $etablissementId)
            ->with(['classe', 'filiations', 'fraisEleves.echeances.paiements'])
            ->findOrFail($id);

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

        do {
            $dernier = Eleve::withTrashed()->where('matricule', 'like', 'LAK-' . date('Y') . '-%')->count();
            $matricule = 'LAK-' . date('Y') . '-' . str_pad($dernier + 1, 3, '0', STR_PAD_LEFT);
            $dernier++;
        } while (Eleve::withTrashed()->where('matricule', $matricule)->exists());

        $eleve = Eleve::create([
            'etablissement_id' => $etablissementId,
            'classe_id' => $request->classe_id,
            'session_scolaire_id' => $classe->session_scolaire_id,
            'nom' => $request->nom,
            'prenom' => $request->prenom,
            'matricule' => $matricule,
            'date_naissance' => $request->date_naissance,
            'lieu_naissance' => $request->lieu_naissance,
            'statut_dossier' => 'photo_manquante',
        ]);

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

        return response()->json($eleve->load('filiations'), 201);
    }

    public function update(Request $request, $id)
    {
        $etablissementId = $request->user()->etablissement_id;
        $eleve = Eleve::where('etablissement_id', $etablissementId)->findOrFail($id);

        $eleve->update($request->only([
            'nom', 'prenom', 'date_naissance', 'lieu_naissance', 'classe_id',
        ]));

        return response()->json($eleve);
    }
}



