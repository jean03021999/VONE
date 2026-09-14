<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class AbonnementController extends Controller
{
    public function show(Request $request)
    {
        $etablissement = $request->user()->etablissement;

        if (!$etablissement) {
            return response()->json(['message' => 'Utilisateur non rattaché à un établissement.'], 403);
        }

        return response()->json([
            'nom' => $etablissement->nom,
            'code' => $etablissement->code,
            'statut' => $etablissement->statut,
            'date_fin_essai' => $etablissement->date_fin_essai,
        ]);
    }
}
