<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('salaires', function (Blueprint $table) {
            $table->id();
            $table->foreignId('enseignant_id')->constrained('enseignants')->cascadeOnDelete();
            $table->foreignId('etablissement_id')->constrained('etablissements')->cascadeOnDelete();
            $table->tinyInteger('mois');
            $table->year('annee');
            $table->enum('type_remuneration', ['fixe', 'horaire'])->default('fixe');
            $table->decimal('salaire_base', 12, 2)->nullable();
            $table->decimal('nb_heures', 5, 2)->nullable();
            $table->decimal('taux_horaire', 12, 2)->nullable();
            $table->decimal('nb_heures_supp', 5, 2)->nullable()->default(0);
            $table->decimal('taux_heure_supp', 12, 2)->nullable();
            $table->decimal('montant_net', 12, 2);
            $table->enum('moyen_paiement', ['especes', 'mobile_money', 'virement', 'cheque'])->default('especes');
            $table->date('date_paiement')->nullable();
            $table->enum('statut', ['en_attente', 'paye'])->default('en_attente');
            $table->string('reference')->nullable();
            $table->text('observation')->nullable();
            $table->timestamps();

            $table->unique(['enseignant_id', 'mois', 'annee']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('salaires');
    }
};
