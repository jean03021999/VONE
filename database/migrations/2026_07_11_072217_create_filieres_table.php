<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('filieres', function (Blueprint $table) {
            $table->id();
            $table->foreignId('etablissement_id')->constrained()->cascadeOnDelete();
            $table->string('nom');
            $table->string('niveau_a_partir_de');
            $table->timestamps();
            $table->unique(['etablissement_id', 'nom']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('filieres');
    }
};
