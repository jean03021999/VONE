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
}
