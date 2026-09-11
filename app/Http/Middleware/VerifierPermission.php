<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class VerifierPermission
{
    public function handle(Request $request, Closure $next, string $permission): Response
    {
        $user = $request->user();

        if (!$user) {
            return response()->json(['message' => 'Non authentifie.'], 401);
        }

        if (!$user->etablissement_id) {
            return response()->json(['message' => 'Utilisateur non rattache a un etablissement.'], 403);
        }

        $possedePermission = $user->roles()
            ->where('roles.etablissement_id', $user->etablissement_id)
            ->whereHas('permissions', function ($query) use ($permission) {
                $query->where('nom', $permission);
            })
            ->exists();

        if (!$possedePermission) {
            return response()->json([
                'message' => "Acces refuse. Permission requise : {$permission}",
            ], 403);
        }

        return $next($request);
    }
}
