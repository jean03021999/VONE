<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('eleve_id')->constrained('eleves')->cascadeOnDelete();
            $table->foreignId('session_scolaire_id')->constrained('sessions_scolaires')->cascadeOnDelete();
            $table->foreignId('classe_id')->constrained('classes')->restrictOnDelete();
            $table->enum('type_inscription', ['nouvelle', 'reinscription', 'a_determiner'])->nullable();
            $table->enum('statut', ['active', 'annulee', 'en_attente'])->default('active');
            $table->date('date_inscription');
            $table->timestamps();
            $table->unique(['eleve_id', 'session_scolaire_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inscriptions');
    }
};
