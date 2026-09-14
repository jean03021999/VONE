<?php

namespace App\Http\Controllers;

use App\Models\Eleve;
use App\Models\Enseignant;
use App\Models\User;

class StatsPubliquesController extends Controller
{
    public function index()
    {
        return response()->json([
            'eleves' => Eleve::count(),
            'enseignants' => Enseignant::count(),
            'utilisateurs' => User::count(),
        ]);
    }
}
