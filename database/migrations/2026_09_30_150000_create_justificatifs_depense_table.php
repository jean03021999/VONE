<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Justification des depenses : numero de la piece (facture, recu, bon) et fichiers joints
    // (photo ou PDF), plusieurs par depense. Une depense sans fichier reste « a justifier ».
    public function up(): void
    {
        Schema::table('depenses', function (Blueprint $table) {
            $table->string('numero_piece')->nullable()->after('reference');
        });

        Schema::create('justificatifs_depense', function (Blueprint $table) {
            $table->id();
            $table->foreignId('depense_id')->constrained('depenses')->cascadeOnDelete();
            $table->string('fichier_path');
            $table->string('nom_original');
            $table->string('type_mime', 100);
            $table->unsignedInteger('taille');
            $table->foreignId('ajoute_par')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('justificatifs_depense');
        Schema::table('depenses', function (Blueprint $table) {
            $table->dropColumn('numero_piece');
        });
    }
};
