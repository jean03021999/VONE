<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Le lieu de naissance est facultatif (formulaire, validation, import Excel) : la colonne
    // NOT NULL faisait echouer l'enregistrement d'un eleve sans lieu de naissance.
    public function up(): void
    {
        Schema::table('eleves', function (Blueprint $table) {
            $table->string('lieu_naissance')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('eleves', function (Blueprint $table) {
            $table->string('lieu_naissance')->nullable(false)->change();
        });
    }
};
