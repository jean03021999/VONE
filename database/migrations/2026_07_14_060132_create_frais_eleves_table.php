<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('frais_eleves', function (Blueprint $table) {
            $table->id();
            $table->foreignId('eleve_id')->constrained('eleves')->cascadeOnDelete();
            $table->foreignId('type_frais_id')->constrained('types_frais')->cascadeOnDelete();
            $table->foreignId('session_scolaire_id')->constrained('sessions_scolaires')->cascadeOnDelete();
            $table->decimal('montant_total', 12, 2);
            $table->decimal('montant_original', 12, 2)->nullable();
            $table->string('motif_personnalisation')->nullable();
            $table->timestamps();
            $table->unique(['eleve_id', 'type_frais_id', 'session_scolaire_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('frais_eleves');
    }
};
