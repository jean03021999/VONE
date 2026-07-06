<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('etablissements', function (Blueprint $table) {
            $table->id();
            $table->string('nom');
            $table->string('code')->unique();
            $table->enum('type', ['ecole_privee', 'ecole_publique', 'universite', 'centre_formation']);
            $table->string('pays')->default('Guinee');
            $table->string('ville')->nullable();
            $table->string('adresse')->nullable();
            $table->string('telephone')->nullable();
            $table->string('email')->nullable();
            $table->string('logo_path')->nullable();
            $table->string('langue_principale')->default('fr');
            $table->string('devise')->default('GNF');
            $table->enum('statut', ['actif', 'suspendu', 'essai'])->default('essai');
            $table->date('date_fin_essai')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('etablissements');
    }
};
