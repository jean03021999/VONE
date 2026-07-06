<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('eleves', function (Blueprint $table) {
            $table->id();
            $table->foreignId('etablissement_id')->constrained()->cascadeOnDelete();
            $table->foreignId('classe_id')->constrained('classes')->cascadeOnDelete();
            $table->foreignId('session_scolaire_id')->constrained('sessions_scolaires')->cascadeOnDelete();
            $table->string('nom');
            $table->string('prenom');
            $table->string('matricule')->unique();
            $table->date('date_naissance');
            $table->string('lieu_naissance');
            $table->string('photo_path')->nullable();
            $table->enum('statut_dossier', ['complet', 'photo_manquante'])->default('photo_manquante');
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('eleves');
    }
};
