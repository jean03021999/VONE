<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Un paiement (meme annule) ne doit jamais disparaitre du journal de caisse quand ses frais sont
    // retires (annulation d'inscription, suppression d'un doublon) : le lien vers l'echeance passe a
    // NULL au lieu de supprimer le paiement en cascade. Il garde son eleve, montant, libelle, reference.
    public function up(): void
    {
        Schema::table('paiements', function (Blueprint $table) {
            $table->dropForeign(['echeance_eleve_id']);
            $table->foreign('echeance_eleve_id')->references('id')->on('echeances_eleves')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('paiements', function (Blueprint $table) {
            $table->dropForeign(['echeance_eleve_id']);
            $table->foreign('echeance_eleve_id')->references('id')->on('echeances_eleves')->cascadeOnDelete();
        });
    }
};
