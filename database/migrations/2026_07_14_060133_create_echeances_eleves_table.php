<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('echeances_eleves', function (Blueprint $table) {
            $table->id();
            $table->foreignId('frais_eleve_id')->constrained('frais_eleves')->cascadeOnDelete();
            $table->string('libelle');
            $table->decimal('montant', 12, 2);
            $table->date('date_limite');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('echeances_eleves');
    }
};
