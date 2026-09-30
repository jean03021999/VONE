<?php

namespace App\Http\Controllers;

use App\Models\Matiere;
use App\Models\Filiere;
use App\Models\MatiereCoefficient;
use Illuminate\Http\Request;

class MatiereController extends Controller
{
    public function index(Request $request)
    {
        $etablissementId = $request->user()->etablissement_id;

        $matieres = Matiere::where('etablissement_id', $etablissementId)
            ->with('coefficients.filiere')
            ->get();

        $filieres = Filiere::where('etablissement_id', $etablissementId)->get();

        return response()->json([
            'matieres' => $matieres,
            'filieres' => $filieres,
        ]);
    }

    public function store(Request $request)
    {
        $request->validate(['nom' => 'required|string']);
        $etablissementId = $request->user()->etablissement_id;

        $matiere = Matiere::firstOrCreate([
            'etablissement_id' => $etablissementId,
            'nom' => $request->nom,
        ]);

        if ($request->filled('coefficient')) {
            MatiereCoefficient::create([
                'matiere_id' => $matiere->id,
                'filiere_id' => $request->filiere_id,
                'niveau' => $request->niveau,
                'coefficient' => $request->coefficient,
                'compte_dans_moyenne' => $request->boolean('compte_dans_moyenne', true),
            ]);
        }

        return response()->json($matiere->load('coefficients'), 201);
    }

    public function storeFiliere(Request $request)
    {
        $request->validate(['nom' => 'required|string', 'niveau_a_partir_de' => 'required|string']);
        $etablissementId = $request->user()->etablissement_id;

        $filiere = Filiere::firstOrCreate([
            'etablissement_id' => $etablissementId,
            'nom' => $request->nom,
        ], [
            'niveau_a_partir_de' => $request->niveau_a_partir_de,
        ]);

        return response()->json($filiere, 201);
    }


    public function update(Request $request, $id)
    {
        $etablissementId = $request->user()->etablissement_id;
        $matiere = Matiere::where('etablissement_id', $etablissementId)->findOrFail($id);
        $request->validate([
            'nom' => ['required', 'string', 'max:100', \Illuminate\Validation\Rule::unique('matieres', 'nom')->where('etablissement_id', $etablissementId)->ignore($matiere->id)],
        ], ['nom.unique' => 'Une matière porte déjà ce nom.']);

        $matiere->update(['nom' => trim($request->nom)]);

        return response()->json($matiere->load('coefficients.filiere'));
    }

    /** Supprime une matiere jamais enseignee ni notee (affectations et bulletins la referencent). */
    public function destroy(Request $request, $id)
    {
        $matiere = Matiere::where('etablissement_id', $request->user()->etablissement_id)->findOrFail($id);

        $liens = array_filter([
            ($n = $matiere->affectations()->count()) ? "{$n} affectation(s) d'enseignant" : null,
            ($n = \App\Models\LigneBulletin::where('matiere_id', $matiere->id)->count()) ? "{$n} ligne(s) de bulletin" : null,
        ]);
        if ($liens) {
            return response()->json([
                'message' => "Suppression impossible : {$matiere->nom} est utilisée par " . implode(' et ', $liens) . '.',
            ], 422);
        }

        $matiere->delete();

        return response()->json(['message' => "Matière {$matiere->nom} supprimée."]);
    }

    public function updateCoefficient(Request $request, $id)
    {
        $coefficient = MatiereCoefficient::whereHas('matiere', fn ($q) => $q->where('etablissement_id', $request->user()->etablissement_id))->findOrFail($id);
        $request->validate([
            'coefficient' => 'required|numeric|min:0|max:20',
            'compte_dans_moyenne' => 'boolean',
        ]);

        $coefficient->update([
            'coefficient' => $request->coefficient,
            'compte_dans_moyenne' => $request->boolean('compte_dans_moyenne', true),
        ]);

        return response()->json($coefficient->load('filiere'));
    }

    public function destroyCoefficient(Request $request, $id)
    {
        $coefficient = MatiereCoefficient::whereHas('matiere', fn ($q) => $q->where('etablissement_id', $request->user()->etablissement_id))->findOrFail($id);
        $coefficient->delete();

        return response()->json(['message' => 'Coefficient supprimé.']);
    }

    public function updateFiliere(Request $request, $id)
    {
        $etablissementId = $request->user()->etablissement_id;
        $filiere = Filiere::where('etablissement_id', $etablissementId)->findOrFail($id);
        $request->validate([
            'nom' => ['required', 'string', 'max:100', \Illuminate\Validation\Rule::unique('filieres', 'nom')->where('etablissement_id', $etablissementId)->ignore($filiere->id)],
        ], ['nom.unique' => 'Une filière porte déjà ce nom.']);

        $filiere->update(['nom' => trim($request->nom)]);

        return response()->json($filiere);
    }

    /** Supprime une filiere sans classe ni coefficient (la base supprimerait ses coefficients). */
    public function destroyFiliere(Request $request, $id)
    {
        $filiere = Filiere::where('etablissement_id', $request->user()->etablissement_id)->findOrFail($id);

        $liens = array_filter([
            ($n = $filiere->classes()->count()) ? "{$n} classe(s)" : null,
            ($n = $filiere->coefficients()->count()) ? "{$n} coefficient(s)" : null,
        ]);
        if ($liens) {
            return response()->json([
                'message' => "Suppression impossible : la filière {$filiere->nom} est utilisée par " . implode(' et ', $liens) . '.',
            ], 422);
        }

        $filiere->delete();

        return response()->json(['message' => "Filière {$filiere->nom} supprimée."]);
    }
}
