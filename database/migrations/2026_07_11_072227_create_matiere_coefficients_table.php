<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('matiere_coefficients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('matiere_id')->constrained('matieres')->cascadeOnDelete();
            $table->foreignId('filiere_id')->nullable()->constrained('filieres')->cascadeOnDelete();
            $table->string('niveau')->nullable();
            $table->integer('coefficient');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('matiere_coefficients');
    }
};
