<?php

namespace App\Http\Controllers;

use App\Models\Periode;
use App\Models\SessionScolaire;
use Illuminate\Http\Request;

class PeriodeController extends Controller
{
    public function index(Request $request)
    {
        $etablissementId = $request->user()->etablissement_id;
        return response()->json(Periode::where('etablissement_id', $etablissementId)->get());
    }

    public function store(Request $request)
    {
        $request->validate(['libelle' => 'required|string', 'date_debut' => 'required|date', 'date_fin' => 'required|date']);
        $etablissementId = $request->user()->etablissement_id;
        $session = SessionScolaire::where('etablissement_id', $etablissementId)->where('est_active', true)->first();

        $periode = Periode::create([
            'etablissement_id' => $etablissementId,
            'session_scolaire_id' => $session->id,
            'libelle' => $request->libelle,
            'date_debut' => $request->date_debut,
            'date_fin' => $request->date_fin,
            'statut' => 'ouverte',
        ]);

        return response()->json($periode, 201);
    }


    public function update(Request $request, $id)
    {
        $periode = Periode::where('etablissement_id', $request->user()->etablissement_id)->findOrFail($id);
        $request->validate([
            'libelle' => 'required|string|max:100',
            'date_debut' => 'required|date',
            'date_fin' => 'required|date|after_or_equal:date_debut',
            'statut' => 'required|in:ouverte,cloturee',
        ], ['date_fin.after_or_equal' => 'La date de fin doit être après la date de début.']);

        $periode->update($request->only(['libelle', 'date_debut', 'date_fin', 'statut']));

        return response()->json($periode);
    }

    /** Supprime une periode sans evaluation ni bulletin (la base les effacerait en cascade). */
    public function destroy(Request $request, $id)
    {
        $periode = Periode::where('etablissement_id', $request->user()->etablissement_id)->findOrFail($id);

        $liens = array_filter([
            ($n = $periode->evaluations()->count()) ? "{$n} évaluation(s)" : null,
            ($n = \App\Models\Bulletin::where('periode_id', $periode->id)->count()) ? "{$n} bulletin(s)" : null,
        ]);
        if ($liens) {
            return response()->json([
                'message' => "Suppression impossible : la période {$periode->libelle} a " . implode(' et ', $liens) . '. Clôturez-la plutôt.',
            ], 422);
        }

        $periode->delete();

        return response()->json(['message' => "Période {$periode->libelle} supprimée."]);
    }
}
