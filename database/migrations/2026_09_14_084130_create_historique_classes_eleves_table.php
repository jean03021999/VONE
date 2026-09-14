<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('historique_classes_eleves', function (Blueprint $table) {
            $table->id();
            $table->foreignId('inscription_id')->constrained('inscriptions')->cascadeOnDelete();
            $table->foreignId('ancienne_classe_id')->nullable()->constrained('classes')->nullOnDelete();
            $table->foreignId('nouvelle_classe_id')->constrained('classes')->restrictOnDelete();
            $table->string('motif')->nullable();
            $table->date('date_changement');
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('historique_classes_eleves');
    }
};
