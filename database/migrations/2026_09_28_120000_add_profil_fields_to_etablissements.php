<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Fiche complete de l'etablissement (module Parametres) : localisation administrative guineenne,
    // informations academiques et identite (slogan distinct de `devise`, qui est la monnaie).
    public function up(): void
    {
        Schema::table('etablissements', function (Blueprint $table) {
            $table->string('quartier')->nullable()->after('ville');
            $table->string('region')->nullable()->after('quartier');
            $table->string('prefecture')->nullable()->after('region');
            $table->string('coordonnees_gps')->nullable()->after('prefecture');
            $table->string('telephone_secondaire')->nullable()->after('telephone');
            $table->json('cycles')->nullable();
            $table->unsignedInteger('capacite_accueil')->nullable();
            $table->string('agrement')->nullable();
            $table->string('slogan')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('etablissements', function (Blueprint $table) {
            $table->dropColumn([
                'quartier', 'region', 'prefecture', 'coordonnees_gps', 'telephone_secondaire',
                'cycles', 'capacite_accueil', 'agrement', 'slogan',
            ]);
        });
    }
};
