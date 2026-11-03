<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Fiche tarifaire : un seul formulaire pour un groupe de classes (inscription, reinscription,
// scolarite et ses echeances, autres frais). Elle ne remplace pas les grilles : elle les cree ou
// les met a jour, et les grilles restent la source utilisee par l'inscription, la caisse et l'import.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fiches_tarifaires', function (Blueprint $table) {
            $table->id();
            $table->foreignId('etablissement_id')->constrained()->cascadeOnDelete();
            $table->foreignId('session_scolaire_id')->constrained('sessions_scolaires')->cascadeOnDelete();
            $table->string('nom', 100);
            $table->string('mode_paiement', 20)->default('trimestre'); // trimestre, mois, personnalise
            $table->string('mode_saisie', 20)->default('echeance'); // echeance, annuel
            $table->decimal('montant_saisi', 12, 2)->nullable();
            $table->json('mois')->nullable(); // mois factures en mode "mois"
            $table->json('autres_frais')->nullable(); // [{type_frais_id, obligatoire, periodicite}]
            $table->timestamps();
            $table->unique(['session_scolaire_id', 'nom']);
        });

        // Une classe appartient a une seule fiche (les classes sont propres a une session).
        Schema::create('fiche_tarifaire_classe', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fiche_tarifaire_id')->constrained('fiches_tarifaires')->cascadeOnDelete();
            $table->foreignId('classe_id')->unique()->constrained('classes')->cascadeOnDelete();
            $table->timestamps();
        });

        Schema::table('grilles_tarifaires', function (Blueprint $table) {
            $table->foreignId('fiche_tarifaire_id')->nullable()->constrained('fiches_tarifaires')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('grilles_tarifaires', function (Blueprint $table) {
            $table->dropConstrainedForeignId('fiche_tarifaire_id');
        });
        Schema::dropIfExists('fiche_tarifaire_classe');
        Schema::dropIfExists('fiches_tarifaires');
    }
};
