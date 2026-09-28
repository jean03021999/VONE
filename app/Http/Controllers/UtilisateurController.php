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
            // Derniere activite reelle : jeton de connexion le plus recemment utilise.
            ->addSelect(['derniere_activite' => \Laravel\Sanctum\PersonalAccessToken::selectRaw('MAX(COALESCE(last_used_at, created_at))')
                ->whereColumn('tokenable_id', 'users.id')
                ->where('tokenable_type', User::class)])
            ->orderBy('name')
            ->get()
            ->map(fn($u) => [
                'id' => $u->id,
                'name' => $u->name,
                'email' => $u->email,
                'telephone' => $u->telephone,
                'statut' => $u->statut,
                'roles' => $u->roles->pluck('nom'),
                'derniere_activite' => $u->derniere_activite ? \Illuminate\Support\Carbon::parse($u->derniere_activite)->toIso8601String() : null,
            ]);

        return response()->json($utilisateurs);
    }
}
