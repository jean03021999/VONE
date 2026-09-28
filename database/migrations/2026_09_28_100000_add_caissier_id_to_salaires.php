<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('salaires', function (Blueprint $table) {
            // Utilisateur qui a enregistre le paiement (affiche sur la fiche de paie).
            $table->foreignId('caissier_id')->nullable()->after('statut')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('salaires', function (Blueprint $table) {
            $table->dropForeign(['caissier_id']);
            $table->dropColumn('caissier_id');
        });
    }
};
