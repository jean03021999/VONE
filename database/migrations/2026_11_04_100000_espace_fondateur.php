<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Espace Fondateur : consultation de la caisse et des impayes (frais.voir), relances sans droit
// d'encaisser (frais.relances.enregistrer), controle des annulations (frais.controle.gerer), seuils
// d'alerte propres a chaque etablissement et trace de la verification d'une annulation.
return new class extends Migration
{
    private const NOUVELLES = [
        ['nom' => 'frais.relances.enregistrer', 'description' => "Enregistrer les relances des parents (sans droit d'encaisser)"],
        ['nom' => 'frais.controle.gerer', 'description' => "Contrôler les annulations et régler les seuils d'alerte"],
    ];

    public function up(): void
    {
        Schema::table('etablissements', function (Blueprint $table) {
            $table->unsignedBigInteger('seuil_annulation_montant')->default(500000);
            $table->unsignedInteger('seuil_annulation_delai_heures')->default(24);
        });

        Schema::table('paiements', function (Blueprint $table) {
            $table->timestamp('annulation_justifiee_le')->nullable();
            $table->foreignId('annulation_justifiee_par')->nullable()->constrained('users')->nullOnDelete();
        });

        $maintenant = now();
        $module = DB::table('permissions')->where('nom', 'frais.voir')->value('module') ?? 'frais';
        $ids = [];
        foreach (self::NOUVELLES as $p) {
            $ids[$p['nom']] = DB::table('permissions')->where('nom', $p['nom'])->value('id')
                ?: DB::table('permissions')->insertGetId($p + ['module' => $module, 'created_at' => $maintenant, 'updated_at' => $maintenant]);
        }
        $accorder = function ($roleIds, $permissionId) use ($maintenant) {
            foreach ($roleIds as $roleId) {
                DB::table('role_permission')->insertOrIgnore(['role_id' => $roleId, 'permission_id' => $permissionId, 'created_at' => $maintenant, 'updated_at' => $maintenant]);
            }
        };

        // Fondateur : lecture de la caisse, relances et controle, sans droit d'encaisser.
        $fondateurs = DB::table('roles')->whereRaw("LOWER(nom) = 'fondateur'")->pluck('id');
        $fraisVoir = DB::table('permissions')->where('nom', 'frais.voir')->value('id');
        if ($fraisVoir) {
            $accorder($fondateurs, $fraisVoir);
        }
        $accorder($fondateurs, $ids['frais.relances.enregistrer']);
        $accorder($fondateurs, $ids['frais.controle.gerer']);

        // Les roles qui relancaient deja (droit d'encaisser) gardent la main.
        $encaissement = DB::table('permissions')->where('nom', 'frais.paiement.enregistrer')->value('id');
        if ($encaissement) {
            $accorder(DB::table('role_permission')->where('permission_id', $encaissement)->pluck('role_id'), $ids['frais.relances.enregistrer']);
        }
    }

    public function down(): void
    {
        $ids = DB::table('permissions')->whereIn('nom', array_column(self::NOUVELLES, 'nom'))->pluck('id');
        DB::table('role_permission')->whereIn('permission_id', $ids)->delete();
        DB::table('permissions')->whereIn('id', $ids)->delete();

        $fraisVoir = DB::table('permissions')->where('nom', 'frais.voir')->value('id');
        if ($fraisVoir) {
            DB::table('role_permission')->where('permission_id', $fraisVoir)
                ->whereIn('role_id', DB::table('roles')->whereRaw("LOWER(nom) = 'fondateur'")->pluck('id'))->delete();
        }

        Schema::table('paiements', function (Blueprint $table) {
            $table->dropConstrainedForeignId('annulation_justifiee_par');
            $table->dropColumn('annulation_justifiee_le');
        });
        Schema::table('etablissements', function (Blueprint $table) {
            $table->dropColumn(['seuil_annulation_montant', 'seuil_annulation_delai_heures']);
        });
    }
};
