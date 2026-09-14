<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('grilles_tarifaires', function (Blueprint $table) {
            $table->enum('periodicite', ['annuel', 'semestriel', 'trimestriel', 'mensuel'])->nullable();
            $table->enum('applicable_a', ['nouveau', 'ancien', 'tous'])->default('tous');
            $table->boolean('actif')->default(true);
        });

        Schema::table('grilles_tarifaires', function (Blueprint $table) {
            $table->dropUnique('grilles_tarifaires_classe_id_type_frais_id_session_scolaire_id_');
            $table->unique(['classe_id', 'type_frais_id', 'session_scolaire_id', 'applicable_a']);
        });
    }

    public function down(): void
    {
        Schema::table('grilles_tarifaires', function (Blueprint $table) {
            $table->dropUnique(['classe_id', 'type_frais_id', 'session_scolaire_id', 'applicable_a']);
            $table->unique(['classe_id', 'type_frais_id', 'session_scolaire_id']);
            $table->dropColumn(['periodicite', 'applicable_a', 'actif']);
        });
    }
};
