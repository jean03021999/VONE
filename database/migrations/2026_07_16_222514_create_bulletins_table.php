<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bulletins', function (Blueprint $table) {
            $table->id();
            $table->foreignId('etablissement_id')->constrained()->cascadeOnDelete();
            $table->foreignId('eleve_id')->constrained('eleves')->cascadeOnDelete();
            $table->foreignId('periode_id')->nullable()->constrained('periodes')->cascadeOnDelete();
            $table->boolean('est_annuel')->default(false);
            $table->decimal('moyenne', 5, 2);
            $table->integer('rang')->nullable();
            $table->integer('effectif_classe')->nullable();
            $table->integer('version')->default(1);
            $table->enum('statut', ['courante', 'remplacee'])->default('courante');
            $table->foreignId('remplacee_par_id')->nullable()->constrained('bulletins')->nullOnDelete();
            $table->timestamp('date_remplacement')->nullable();
            $table->foreignId('genere_par')->constrained('users')->cascadeOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bulletins');
    }
};
