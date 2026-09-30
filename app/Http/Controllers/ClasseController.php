<?php

namespace App\Http\Controllers;

use App\Models\Classe;
use Illuminate\Http\Request;

class ClasseController extends Controller
{
    public function index(Request $request)
    {
        $etablissementId = $request->user()->etablissement_id;

        $classes = Classe::where('etablissement_id', $etablissementId)
            ->with('filiere')
            ->withCount('eleves')
            ->ordonneesPedagogiquement()
            ->get()
            ->map(fn($c) => [
                'id' => $c->id,
                'nom' => $c->nom,
                'niveau' => $c->niveau,
                'filiere' => $c->filiere?->nom,
                'filiere_id' => $c->filiere_id,
                'nombre_eleves' => $c->eleves_count,
            ]);

        return response()->json($classes);
    }

    public function store(Request $request)
    {
        $request->validate([
            'nom' => 'required|string',
            'niveau' => 'required|string',
            'filiere_id' => 'nullable|exists:filieres,id',
        ]);

        $etablissementId = $request->user()->etablissement_id;

        $sessionActive = \App\Models\SessionScolaire::where('etablissement_id', $etablissementId)
            ->where('est_active', true)
            ->first();

        if (!$sessionActive) {
            return response()->json(['message' => 'Aucune session scolaire active. Contactez le support.'], 422);
        }

        $classe = Classe::create([
            'etablissement_id' => $etablissementId,
            'session_scolaire_id' => $sessionActive->id,
            'nom' => $request->nom,
            'niveau' => $request->niveau,
            'filiere_id' => $request->filiere_id,
        ]);

        return response()->json($classe, 201);
    }


    public function update(Request $request, $id)
    {
        $etablissementId = $request->user()->etablissement_id;
        $classe = Classe::where('etablissement_id', $etablissementId)->findOrFail($id);
        $request->validate([
            'nom' => 'required|string|max:100',
            'niveau' => 'required|string|max:100',
            'filiere_id' => ['nullable', \Illuminate\Validation\Rule::exists('filieres', 'id')->where('etablissement_id', $etablissementId)],
        ]);

        $classe->update($request->only(['nom', 'niveau', 'filiere_id']));

        return response()->json($classe->fresh('filiere'));
    }

    /**
     * Supprime une classe vide. Refus si elle a des eleves inscrits, des affectations d'enseignants
     * ou des grilles tarifaires : la base les effacerait en cascade (notes, emplois du temps, frais).
     */
    public function destroy(Request $request, $id)
    {
        $classe = Classe::where('etablissement_id', $request->user()->etablissement_id)->findOrFail($id);

        $liens = array_filter([
            ($n = \App\Models\Inscription::where('classe_id', $classe->id)->where('statut', 'active')->count()) ? "{$n} élève(s) inscrit(s)" : null,
            ($n = \App\Models\Affectation::where('classe_id', $classe->id)->count()) ? "{$n} affectation(s) d'enseignant" : null,
            ($n = \App\Models\GrilleTarifaire::where('classe_id', $classe->id)->count()) ? "{$n} grille(s) tarifaire(s)" : null,
        ]);
        if ($liens) {
            return response()->json([
                'message' => "Suppression impossible : la classe {$classe->nom} a " . implode(', ', $liens) . '. Changez d\'abord ces élèves de classe et retirez ses affectations et grilles.',
            ], 422);
        }

        $classe->delete();

        return response()->json(['message' => "Classe {$classe->nom} supprimée."]);
    }
}
