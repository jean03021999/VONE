<?php

namespace App\Http\Controllers;

use App\Models\Affectation;
use App\Models\Classe;
use Illuminate\Http\Request;

class AffectationController extends Controller
{
    public function index(Request $request)
    {
        $etablissementId = $request->user()->etablissement_id;

        $affectations = Affectation::whereHas('classe', fn($q) => $q->where('etablissement_id', $etablissementId))
            ->with(['classe', 'matiere', 'enseignant'])
            ->get()
            ->map(fn($a) => [
                'id' => $a->id,
                'classe' => $a->classe->nom,
                'classe_id' => $a->classe_id,
                'matiere' => $a->matiere->nom,
                'matiere_id' => $a->matiere_id,
                'enseignant' => $a->enseignant->nom . ' ' . $a->enseignant->prenom,
                'enseignant_id' => $a->enseignant_id,
                'volume_horaire_hebdomadaire' => $a->volume_horaire_hebdomadaire,
                'est_classe_examen' => $a->est_classe_examen,
            ]);

        return response()->json($affectations);
    }

    public function store(Request $request)
    {
        $request->validate([
            'enseignant_id' => 'required|exists:enseignants,id',
            'classe_id' => 'required|exists:classes,id',
            'matiere_id' => 'required|exists:matieres,id',
            'volume_horaire_hebdomadaire' => 'required|integer|min:1',
            'est_classe_examen' => 'boolean',
        ]);

        $existe = Affectation::where('enseignant_id', $request->enseignant_id)
            ->where('classe_id', $request->classe_id)
            ->where('matiere_id', $request->matiere_id)
            ->exists();

        if ($existe) {
            return response()->json(['message' => 'Cette affectation existe deja pour cet enseignant, cette classe et cette matiere.'], 422);
        }

        $affectation = Affectation::create([
            'enseignant_id' => $request->enseignant_id,
            'classe_id' => $request->classe_id,
            'matiere_id' => $request->matiere_id,
            'volume_horaire_hebdomadaire' => $request->volume_horaire_hebdomadaire,
            'est_classe_examen' => $request->boolean('est_classe_examen', false),
        ]);

        return response()->json($affectation->load(['classe', 'matiere', 'enseignant']), 201);
    }

    public function destroy($id)
    {
        $affectation = Affectation::findOrFail($id);

        $aDesEvaluations = \App\Models\Evaluation::where('affectation_id', $id)->exists();
        if ($aDesEvaluations) {
            return response()->json(['message' => 'Impossible de supprimer cette affectation : des evaluations y sont associees.'], 422);
        }

        $affectation->delete();
        return response()->json(['message' => 'Affectation supprimee.']);
    }
}
