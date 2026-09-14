<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('frais_eleves', function (Blueprint $table) {
            $table->foreignId('inscription_id')->nullable()->constrained('inscriptions')->nullOnDelete();
            $table->foreignId('grille_tarifaire_id')->nullable()->constrained('grilles_tarifaires')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('frais_eleves', function (Blueprint $table) {
            $table->dropForeign(['inscription_id']);
            $table->dropForeign(['grille_tarifaire_id']);
            $table->dropColumn(['inscription_id', 'grille_tarifaire_id']);
        });
    }
};
