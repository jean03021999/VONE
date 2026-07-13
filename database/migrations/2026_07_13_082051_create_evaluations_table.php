<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('evaluations', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->foreignId('affectation_id')->constrained('affectations')->cascadeOnDelete();
            $table->foreignId('periode_id')->constrained('periodes')->cascadeOnDelete();
            $table->enum('type', ['devoir', 'interrogation', 'composition', 'examen_blanc', 'rattrapage', 'oral', 'projet', 'tp']);
            $table->string('libelle');
            $table->date('date_evaluation');
            $table->decimal('bareme', 5, 2)->default(20);
            $table->enum('statut', ['brouillon', 'soumis', 'valide', 'rejete', 'publie', 'archive', 'annulee'])->default('brouillon');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('evaluations');
    }
};
