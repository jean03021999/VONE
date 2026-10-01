<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Historique des operations sensibles sur un eleve (ex. annulation d'inscription) : qui, quand,
    // motif, montant concerne.
    public function up(): void
    {
        Schema::create('historique_eleves', function (Blueprint $table) {
            $table->id();
            $table->foreignId('eleve_id')->constrained('eleves')->cascadeOnDelete();
            $table->string('action', 40);
            $table->string('description');
            $table->decimal('montant', 15, 2)->nullable();
            $table->text('motif')->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['eleve_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('historique_eleves');
    }
};
