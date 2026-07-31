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
            ->select('id', 'nom', 'niveau')
            ->get();

        return response()->json($classes);
    }
}
