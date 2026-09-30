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
                'photo_url' => $u->photo_url,
                'roles' => $u->roles->pluck('nom'),
                'role_id' => $u->roles->first()?->id,
                'derniere_activite' => $u->derniere_activite ? \Illuminate\Support\Carbon::parse($u->derniere_activite)->toIso8601String() : null,
            ]);

        return response()->json($utilisateurs);
    }


    private const ROLES_ADMINISTRATION = ['FONDATEUR', 'DIRECTEUR', 'PROVISEUR'];

    private function exigerAdministration(Request $request): void
    {
        $user = $request->user();
        $role = $user->roles()->where('roles.etablissement_id', $user->etablissement_id)->first();
        if (! in_array(strtoupper((string) $role?->nom), self::ROLES_ADMINISTRATION, true)) {
            abort(403, 'Seule la direction peut gérer les comptes utilisateurs.');
        }
    }

    private function roleDeLEtablissement(Request $request, $roleId): \App\Models\Role
    {
        return \App\Models\Role::where('etablissement_id', $request->user()->etablissement_id)->findOrFail($roleId);
    }

    /** Mot de passe provisoire lisible (a transmettre a l'utilisateur, qui le changera). */
    private function motDePasseProvisoire(): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghjkmnpqrstuvwxyz23456789';
        $mdp = '';
        for ($i = 0; $i < 10; $i++) {
            $mdp .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }

        return $mdp;
    }

    public function store(Request $request)
    {
        $this->exigerAdministration($request);
        $request->validate([
            'name' => 'required|string|max:150',
            'email' => 'required|email|max:150|unique:users,email',
            'telephone' => 'nullable|string|max:40|unique:users,telephone',
            'role_id' => 'required|integer',
        ], [
            'email.unique' => 'Cette adresse e-mail est déjà utilisée par un autre compte.',
            'telephone.unique' => 'Ce numéro est déjà utilisé par un autre compte.',
        ]);
        $role = $this->roleDeLEtablissement($request, $request->role_id);

        $motDePasse = $this->motDePasseProvisoire();
        $utilisateur = \Illuminate\Support\Facades\DB::transaction(function () use ($request, $role, $motDePasse) {
            $u = User::create([
                'etablissement_id' => $request->user()->etablissement_id,
                'name' => trim($request->name),
                'email' => trim($request->email),
                'telephone' => $request->telephone ?: null,
                'password' => \Illuminate\Support\Facades\Hash::make($motDePasse),
                'statut' => 'actif',
                'created_by' => $request->user()->id,
            ]);
            $u->roles()->sync([$role->id]);

            return $u;
        });

        return response()->json([
            'message' => "Compte créé pour {$utilisateur->name}.",
            'utilisateur' => ['id' => $utilisateur->id, 'name' => $utilisateur->name, 'email' => $utilisateur->email],
            'mot_de_passe_provisoire' => $motDePasse,
        ], 201);
    }

    public function update(Request $request, $id)
    {
        $this->exigerAdministration($request);
        $utilisateur = User::where('etablissement_id', $request->user()->etablissement_id)->findOrFail($id);
        $request->validate([
            'name' => 'required|string|max:150',
            'email' => ['required', 'email', 'max:150', \Illuminate\Validation\Rule::unique('users', 'email')->ignore($utilisateur->id)],
            'telephone' => ['nullable', 'string', 'max:40', \Illuminate\Validation\Rule::unique('users', 'telephone')->ignore($utilisateur->id)],
            'role_id' => 'required|integer',
        ], [
            'email.unique' => 'Cette adresse e-mail est déjà utilisée par un autre compte.',
            'telephone.unique' => 'Ce numéro est déjà utilisé par un autre compte.',
        ]);
        $role = $this->roleDeLEtablissement($request, $request->role_id);

        $roleActuel = $utilisateur->roles()->where('roles.etablissement_id', $utilisateur->etablissement_id)->first();
        if ($utilisateur->id === $request->user()->id && $roleActuel?->id !== $role->id) {
            return response()->json(['message' => 'Vous ne pouvez pas changer votre propre rôle.'], 422);
        }

        $utilisateur->update([
            'name' => trim($request->name),
            'email' => trim($request->email),
            'telephone' => $request->telephone ?: null,
            'updated_by' => $request->user()->id,
        ]);
        $utilisateur->roles()->sync([$role->id]);

        return response()->json(['message' => "Compte de {$utilisateur->name} mis à jour."]);
    }

    /** Suspend (connexion refusee, sessions fermees) ou reactive un compte. */
    public function basculerStatut(Request $request, $id)
    {
        $this->exigerAdministration($request);
        $utilisateur = User::where('etablissement_id', $request->user()->etablissement_id)->findOrFail($id);
        if ($utilisateur->id === $request->user()->id) {
            return response()->json(['message' => 'Vous ne pouvez pas suspendre votre propre compte.'], 422);
        }

        $suspendre = $utilisateur->statut !== 'suspendu';
        $utilisateur->update(['statut' => $suspendre ? 'suspendu' : 'actif', 'updated_by' => $request->user()->id]);
        if ($suspendre) {
            $utilisateur->tokens()->delete();
        }

        return response()->json([
            'statut' => $utilisateur->statut,
            'message' => $suspendre ? "Compte de {$utilisateur->name} suspendu : il ne peut plus se connecter." : "Compte de {$utilisateur->name} réactivé.",
        ]);
    }

    /** Nouveau mot de passe provisoire (mot de passe oublie par l'utilisateur). */
    public function reinitialiserMotDePasse(Request $request, $id)
    {
        $this->exigerAdministration($request);
        $utilisateur = User::where('etablissement_id', $request->user()->etablissement_id)->findOrFail($id);

        $motDePasse = $this->motDePasseProvisoire();
        $utilisateur->update(['password' => \Illuminate\Support\Facades\Hash::make($motDePasse), 'updated_by' => $request->user()->id]);
        $utilisateur->tokens()->delete();

        return response()->json([
            'message' => "Nouveau mot de passe provisoire pour {$utilisateur->name}.",
            'mot_de_passe_provisoire' => $motDePasse,
        ]);
    }
}
