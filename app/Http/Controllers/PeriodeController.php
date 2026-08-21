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
}
