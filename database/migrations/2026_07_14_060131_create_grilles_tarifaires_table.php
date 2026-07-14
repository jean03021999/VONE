<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('grilles_tarifaires', function (Blueprint $table) {
            $table->id();
            $table->foreignId('etablissement_id')->constrained()->cascadeOnDelete();
            $table->foreignId('session_scolaire_id')->constrained('sessions_scolaires')->cascadeOnDelete();
            $table->foreignId('classe_id')->constrained('classes')->cascadeOnDelete();
            $table->foreignId('type_frais_id')->constrained('types_frais')->cascadeOnDelete();
            $table->decimal('montant', 12, 2);
            $table->timestamps();
            $table->unique(['classe_id', 'type_frais_id', 'session_scolaire_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('grilles_tarifaires');
    }
};
