<?php

namespace App\Http\Controllers;

use App\Models\Evaluation;
use App\Models\Note;
use App\Models\Enseignant;
use App\Models\Affectation;
use App\Models\HistoriqueValidation;
use Illuminate\Http\Request;

class EvaluationController extends Controller
{
    private function enseignantConnecte(Request $request): ?Enseignant
    {
        return Enseignant::where('user_id', $request->user()->id)->first();
    }

    public function mesAffectations(Request $request)
    {
        $enseignant = $this->enseignantConnecte($request);
        if (!$enseignant) {
            return response()->json(['message' => 'Aucun dossier enseignant lie a ce compte.'], 403);
        }
        return response()->json($enseignant->affectations()->with(['classe', 'matiere'])->get());
    }

    public function index(Request $request)
    {
        $etablissementId = $request->user()->etablissement_id;
        $vue = $request->query('vue', 'enseignant');

        if ($vue === 'direction') {
            $evaluations = Evaluation::whereHas('affectation.enseignant', fn($q) => $q->where('etablissement_id', $etablissementId))
                ->whereIn('statut', ['soumis', 'valide'])
                ->with(['affectation.classe', 'affectation.matiere', 'affectation.enseignant'])
                ->get();
        } else {
            $enseignant = $this->enseignantConnecte($request);
            if (!$enseignant) {
                return response()->json(['message' => 'Aucun dossier enseignant lie a ce compte.'], 403);
            }
            $evaluations = Evaluation::whereHas('affectation', fn($q) => $q->where('enseignant_id', $enseignant->id))
                ->with(['affectation.classe', 'affectation.matiere'])
                ->get();
        }

        return response()->json($evaluations);
    }

    public function store(Request $request)
    {
        $request->validate([
            'affectation_id' => 'required|exists:affectations,id',
            'periode_id' => 'required|exists:periodes,id',
            'type' => 'required|in:devoir,interrogation,composition,examen_blanc,rattrapage,oral,projet,tp',
            'libelle' => 'required|string',
            'date_evaluation' => 'required|date',
            'bareme' => 'required|numeric',
        ]);

        $enseignant = $this->enseignantConnecte($request);
        if (!$enseignant) {
            return response()->json(['message' => 'Aucun dossier enseignant lie a ce compte.'], 403);
        }

        $affectation = Affectation::where('id', $request->affectation_id)->where('enseignant_id', $enseignant->id)->first();
        if (!$affectation) {
            return response()->json(['message' => 'Cette affectation ne vous appartient pas.'], 403);
        }

        $evaluation = Evaluation::create($request->only(['affectation_id', 'periode_id', 'type', 'libelle', 'date_evaluation', 'bareme']));

        $eleves = \App\Models\Eleve::where('classe_id', $affectation->classe_id)->get();
        foreach ($eleves as $eleve) {
            Note::create(['evaluation_id' => $evaluation->id, 'eleve_id' => $eleve->id, 'statut_presence' => 'present']);
        }

        return response()->json($evaluation, 201);
    }

    public function show(Request $request, $id)
    {
        $evaluation = Evaluation::with(['affectation.classe', 'affectation.matiere', 'notes.eleve'])->findOrFail($id);
        return response()->json($evaluation);
    }

    public function saisirNotes(Request $request, $id)
    {
        $evaluation = Evaluation::findOrFail($id);
        if ($evaluation->statut !== 'brouillon') {
            return response()->json(['message' => 'Impossible de modifier les notes : evaluation deja soumise.'], 422);
        }

        foreach ($request->input('notes', []) as $n) {
            Note::where('evaluation_id', $id)->where('eleve_id', $n['eleve_id'])
                ->update(['valeur' => $n['valeur'] ?? null, 'statut_presence' => $n['statut_presence'] ?? 'present']);
        }

        return response()->json(['message' => 'Notes enregistrees.']);
    }

    public function soumettre(Request $request, $id)
    {
        $evaluation = Evaluation::findOrFail($id);
        $ancien = $evaluation->statut;
        $evaluation->update(['statut' => 'soumis']);
        HistoriqueValidation::create(['evaluation_id' => $id, 'user_id' => $request->user()->id, 'statut_precedent' => $ancien, 'nouveau_statut' => 'soumis']);
        return response()->json($evaluation);
    }

    public function valider(Request $request, $id)
    {
        $evaluation = Evaluation::findOrFail($id);
        $ancien = $evaluation->statut;
        $evaluation->update(['statut' => 'valide']);
        HistoriqueValidation::create(['evaluation_id' => $id, 'user_id' => $request->user()->id, 'statut_precedent' => $ancien, 'nouveau_statut' => 'valide']);
        return response()->json($evaluation);
    }

    public function rejeter(Request $request, $id)
    {
        $request->validate(['commentaire' => 'required|string']);
        $evaluation = Evaluation::findOrFail($id);
        $ancien = $evaluation->statut;
        $evaluation->update(['statut' => 'rejete']);
        HistoriqueValidation::create(['evaluation_id' => $id, 'user_id' => $request->user()->id, 'statut_precedent' => $ancien, 'nouveau_statut' => 'rejete', 'commentaire' => $request->commentaire]);
        return response()->json($evaluation);
    }

    public function reprendre(Request $request, $id)
    {
        $evaluation = Evaluation::findOrFail($id);
        if ($evaluation->statut !== 'rejete') {
            return response()->json(['message' => 'Seule une evaluation rejetee peut etre reprise.'], 422);
        }
        $evaluation->update(['statut' => 'brouillon']);
        return response()->json($evaluation);
    }

    public function publier(Request $request, $id)
    {
        $evaluation = Evaluation::findOrFail($id);
        if ($evaluation->statut !== 'valide') {
            return response()->json(['message' => 'Seule une evaluation validee peut etre publiee.'], 422);
        }
        $ancien = $evaluation->statut;
        $evaluation->update(['statut' => 'publie']);
        HistoriqueValidation::create(['evaluation_id' => $id, 'user_id' => $request->user()->id, 'statut_precedent' => $ancien, 'nouveau_statut' => 'publie']);
        return response()->json($evaluation);
    }
}
