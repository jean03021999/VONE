<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Annulation d'un paiement enregistre par erreur : il reste dans le journal (trace, auteur,
    // motif) mais ne compte plus dans les montants payes ni les soldes.
    public function up(): void
    {
        Schema::table('paiements', function (Blueprint $table) {
            $table->timestamp('annule_le')->nullable();
            $table->foreignId('annule_par')->nullable()->constrained('users')->nullOnDelete();
            $table->string('motif_annulation')->nullable();
            $table->index('annule_le');
        });
    }

    public function down(): void
    {
        Schema::table('paiements', function (Blueprint $table) {
            $table->dropForeign(['annule_par']);
            $table->dropIndex(['annule_le']);
            $table->dropColumn(['annule_le', 'annule_par', 'motif_annulation']);
        });
    }
};
