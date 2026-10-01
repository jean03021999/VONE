<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Arrete de caisse journalier : mouvements du jour figes, especes attendues selon le systeme,
    // especes comptees (billetage) et ecart. Un arrete par jour et par etablissement.
    public function up(): void
    {
        Schema::create('arretes_caisse', function (Blueprint $table) {
            $table->id();
            $table->foreignId('etablissement_id')->constrained('etablissements')->cascadeOnDelete();
            $table->date('date_arrete');
            $table->json('entrees');            // { especes, mobile_money, virement, cheque }
            $table->json('sorties');            // { especes: { salaires, depenses }, ... }
            $table->unsignedInteger('nombre_versements')->default(0);
            $table->decimal('especes_veille', 15, 2);
            $table->decimal('especes_theoriques', 15, 2);
            $table->decimal('especes_comptees', 15, 2);
            $table->decimal('ecart', 15, 2);
            $table->json('billetage');          // { "20000": 12, "10000": 3, ... }
            $table->text('observation')->nullable();
            $table->foreignId('arrete_par')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['etablissement_id', 'date_arrete']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('arretes_caisse');
    }
};
