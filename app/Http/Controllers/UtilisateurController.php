<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;

class UtilisateurController extends Controller
{
    public function index(Request $request)
    {
        $etablissementId = $request->user()->etablissement_id;

        $utilisateurs = User::where('etablissement_id', $etablissementId)
            ->with('roles')
            ->orderBy('name')
            ->get()
            ->map(fn($u) => [
                'id' => $u->id,
                'name' => $u->name,
                'email' => $u->email,
                'telephone' => $u->telephone,
                'statut' => $u->statut,
                'roles' => $u->roles->pluck('nom'),
            ]);

        return response()->json($utilisateurs);
    }
}
