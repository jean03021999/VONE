<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sessions_scolaires', function (Blueprint $table) {
            $table->id();
            $table->foreignId('etablissement_id')->constrained()->cascadeOnDelete();
            $table->string('libelle');
            $table->date('date_debut');
            $table->date('date_fin');
            $table->enum('statut', ['preparation', 'en_cours', 'cloturee', 'archivee'])->default('preparation');
            $table->boolean('est_active')->default(false);
            $table->timestamps();
            $table->unique(['etablissement_id', 'libelle']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sessions_scolaires');
    }
};