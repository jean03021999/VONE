<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Relances des familles : qui a ete relance, quand, comment (lettre, WhatsApp, SMS, appel) et
    // pour quel montant, afin de suivre les impayes et de ne pas relancer deux fois le meme jour.
    public function up(): void
    {
        Schema::create('relances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('etablissement_id')->constrained('etablissements')->cascadeOnDelete();
            $table->foreignId('eleve_id')->constrained('eleves')->cascadeOnDelete();
            $table->string('canal', 20);            // lettre | whatsapp | sms | appel
            $table->string('motif', 20);            // retard | rappel (echeance proche)
            $table->decimal('montant_du', 15, 2);
            $table->text('note')->nullable();
            $table->foreignId('fait_par')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['eleve_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('relances');
    }
};
