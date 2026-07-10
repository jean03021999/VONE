<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('affectations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('enseignant_id')->constrained('enseignants')->cascadeOnDelete();
            $table->foreignId('classe_id')->constrained('classes')->cascadeOnDelete();
            $table->foreignId('matiere_id')->constrained('matieres')->cascadeOnDelete();
            $table->integer('volume_horaire_hebdomadaire');
            $table->boolean('est_classe_examen')->default(false);
            $table->timestamps();
            $table->unique(['enseignant_id', 'classe_id', 'matiere_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('affectations');
    }
};
