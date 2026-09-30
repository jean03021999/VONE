<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Depenses payees avec l'argent de la caisse (hors salaires, suivis dans `salaires`) :
    // fournitures, electricite, entretien... Annulables avec trace, comme les paiements.
    public function up(): void
    {
        Schema::create('depenses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('etablissement_id')->constrained()->cascadeOnDelete();
            $table->date('date_depense');
            $table->string('categorie', 40);
            $table->string('libelle');
            $table->decimal('montant', 12, 2);
            $table->enum('moyen_paiement', ['especes', 'mobile_money', 'virement', 'cheque'])->default('especes');
            $table->string('beneficiaire')->nullable();
            $table->string('reference')->nullable();
            $table->text('observation')->nullable();
            $table->foreignId('enregistre_par')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('annule_le')->nullable();
            $table->foreignId('annule_par')->nullable()->constrained('users')->nullOnDelete();
            $table->string('motif_annulation')->nullable();
            $table->timestamps();
            $table->index(['etablissement_id', 'date_depense']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('depenses');
    }
};
