<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Droits d'administration attribuables a n'importe quel role (ils etaient reserves en dur au
// Fondateur, au Directeur et au Proviseur) : fiche et logo de l'etablissement, annees scolaires,
// comptes utilisateurs, roles. Ces trois roles les recoivent : rien ne change pour les comptes actuels.
return new class extends Migration
{
    private const PERMISSIONS = [
        ['nom' => 'etablissement.gerer', 'description' => "Modifier la fiche de l'établissement et son logo"],
        ['nom' => 'sessions.gerer', 'description' => 'Créer et activer les années scolaires'],
        ['nom' => 'utilisateurs.gerer', 'description' => 'Créer, modifier et suspendre les comptes utilisateurs'],
        ['nom' => 'roles.gerer', 'description' => 'Créer des rôles et choisir leurs droits'],
    ];

    public function up(): void
    {
        $maintenant = now();
        $ids = [];
        foreach (self::PERMISSIONS as $p) {
            $existante = DB::table('permissions')->where('nom', $p['nom'])->value('id');
            $ids[] = $existante ?: DB::table('permissions')->insertGetId($p + [
                'module' => 'administration', 'created_at' => $maintenant, 'updated_at' => $maintenant,
            ]);
        }

        $roles = DB::table('roles')->whereRaw("LOWER(nom) IN ('fondateur', 'directeur', 'proviseur')")->pluck('id');
        foreach ($roles as $roleId) {
            foreach ($ids as $permissionId) {
                DB::table('role_permission')->insertOrIgnore(['role_id' => $roleId, 'permission_id' => $permissionId, 'created_at' => $maintenant, 'updated_at' => $maintenant]);
            }
        }
    }

    public function down(): void
    {
        $ids = DB::table('permissions')->whereIn('nom', array_column(self::PERMISSIONS, 'nom'))->pluck('id');
        DB::table('role_permission')->whereIn('permission_id', $ids)->delete();
        DB::table('permissions')->whereIn('id', $ids)->delete();
    }
};
