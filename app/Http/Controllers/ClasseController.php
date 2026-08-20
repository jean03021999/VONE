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
            ->get()
            ->map(fn($c) => [
                'id' => $c->id,
                'nom' => $c->nom,
                'niveau' => $c->niveau,
                'filiere' => $c->filiere?->nom,
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
}

