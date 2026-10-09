<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Remise par eleve (FraisService::appliquerRemise) : en pourcentage (demi-tarif = 50) ou en montant,
// avec un motif (frais_eleves.motif_personnalisation). Chaque echeance garde son montant d'avant
// remise (montant_initial), pour recalculer ou annuler la remise sans retoucher la grille.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('echeances_eleves', function (Blueprint $table) {
            $table->decimal('montant_initial', 15, 2)->nullable()->after('montant');
        });
        DB::table('echeances_eleves')->whereNull('montant_initial')->update(['montant_initial' => DB::raw('montant')]);

        Schema::table('frais_eleves', function (Blueprint $table) {
            $table->string('remise_type', 20)->nullable()->after('motif_personnalisation');
            $table->decimal('remise_valeur', 15, 2)->nullable()->after('remise_type');
        });
    }

    public function down(): void
    {
        Schema::table('frais_eleves', function (Blueprint $table) {
            $table->dropColumn(['remise_type', 'remise_valeur']);
        });
        Schema::table('echeances_eleves', function (Blueprint $table) {
            $table->dropColumn('montant_initial');
        });
    }
};
