<?php

namespace App\Http\Controllers;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Roles de l'etablissement et leurs droits (Parametres > Utilisateurs & roles), permission
 * roles.gerer. Garde-fous :
 * - le role Fondateur garde toujours la gestion des roles et des comptes (l'ecole ne peut pas se
 *   retrouver sans personne pour les gerer) et ne se supprime pas ;
 * - les roles de base (Fondateur, Directeur, Comptable...) ne se renomment pas : le tableau de bord
 *   et l'affichage en dependent ; leurs droits, eux, se modifient ;
 * - un role encore attribue a des comptes ne se supprime pas.
 */
class RoleController extends Controller
{
    private const TOUJOURS_FONDATEUR = ['roles.gerer', 'utilisateurs.gerer'];

    /** Catalogue des droits, par module (pour cocher ceux d'un role). */
    public function permissions()
    {
        return response()->json(
            Permission::orderBy('module')->orderBy('id')->get(['nom', 'module', 'description'])
        );
    }

    public function store(Request $request)
    {
        $donnees = $this->valider($request);
        $etablissementId = $request->user()->etablissement_id;
        if ($this->nomPris($etablissementId, $donnees['nom'])) {
            return response()->json(['message' => "Un rôle « {$donnees['nom']} » existe déjà."], 422);
        }

        $role = DB::transaction(function () use ($donnees, $etablissementId) {
            $role = Role::create([
                'etablissement_id' => $etablissementId,
                'nom' => $donnees['nom'],
                'description' => $donnees['description'] ?? null,
                'est_modele' => false,
            ]);
            $role->permissions()->sync(Permission::whereIn('nom', $donnees['permissions'])->pluck('id'));

            return $role;
        });

        return response()->json(['message' => "Rôle « {$role->nom} » créé.", 'id' => $role->id], 201);
    }

    public function update(Request $request, $id)
    {
        $role = $this->roleDeLEtablissement($request, $id);
        $donnees = $this->valider($request);

        if ($donnees['nom'] !== $role->nom) {
            if ($this->estRoleDeBase($role)) {
                return response()->json(['message' => "Le rôle « {$role->nom} » est un rôle de base : son nom ne peut pas changer (ses droits, si)."], 422);
            }
            if ($this->nomPris($role->etablissement_id, $donnees['nom'], $role->id)) {
                return response()->json(['message' => "Un rôle « {$donnees['nom']} » existe déjà."], 422);
            }
        }

        $permissions = $donnees['permissions'];
        if ($this->estFondateur($role)) {
            $permissions = array_values(array_unique(array_merge($permissions, self::TOUJOURS_FONDATEUR)));
        }

        DB::transaction(function () use ($role, $donnees, $permissions) {
            $role->update(['nom' => $donnees['nom'], 'description' => $donnees['description'] ?? $role->description]);
            $role->permissions()->sync(Permission::whereIn('nom', $permissions)->pluck('id'));
        });

        return response()->json([
            'message' => "Droits du rôle « {$role->nom} » enregistrés."
                . ($this->estFondateur($role) ? ' Le Fondateur garde toujours la gestion des rôles et des comptes.' : ''),
        ]);
    }

    public function destroy(Request $request, $id)
    {
        $role = $this->roleDeLEtablissement($request, $id);
        if ($this->estFondateur($role)) {
            return response()->json(['message' => 'Le rôle Fondateur ne peut pas être supprimé.'], 422);
        }
        $comptes = $role->users()->count();
        if ($comptes > 0) {
            return response()->json(['message' => "Ce rôle est attribué à {$comptes} compte(s) : donnez-leur d'abord un autre rôle."], 422);
        }
        DB::transaction(function () use ($role) {
            $role->permissions()->detach();
            $role->delete();
        });

        return response()->json(['message' => "Rôle « {$role->nom} » supprimé."]);
    }

    private function valider(Request $request): array
    {
        $donnees = $request->validate([
            'nom' => 'required|string|min:2|max:60',
            'description' => 'nullable|string|max:255',
            'permissions' => 'present|array',
            'permissions.*' => 'string|exists:permissions,nom',
        ], [
            'nom.required' => 'Indiquez le nom du rôle (ex. Secrétaire).',
            'permissions.*.exists' => 'Droit inconnu.',
        ]);
        $donnees['nom'] = trim(preg_replace('/\s+/', ' ', $donnees['nom']));
        if (mb_strtolower($donnees['nom']) === mb_strtolower('Super Administrateur LAKOLI')) {
            abort(422, 'Ce nom est réservé.');
        }

        return $donnees;
    }

    private function roleDeLEtablissement(Request $request, $id): Role
    {
        return Role::where('etablissement_id', $request->user()->etablissement_id)->findOrFail($id);
    }

    private function nomPris(int $etablissementId, string $nom, ?int $sauf = null): bool
    {
        return Role::where('etablissement_id', $etablissementId)
            ->whereRaw('LOWER(nom) = ?', [mb_strtolower($nom)])
            ->when($sauf, fn ($q) => $q->where('id', '!=', $sauf))
            ->exists();
    }

    private function estFondateur(Role $role): bool
    {
        return mb_strtolower($role->nom) === 'fondateur';
    }

    /** Role copie d'un role modele (Fondateur, Directeur, Proviseur, Censeur, Comptable...). */
    private function estRoleDeBase(Role $role): bool
    {
        return Role::whereNull('etablissement_id')->where('est_modele', true)
            ->whereRaw('LOWER(nom) = ?', [mb_strtolower($role->nom)])
            ->exists();
    }
}
