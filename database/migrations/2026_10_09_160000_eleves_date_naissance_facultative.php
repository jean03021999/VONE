<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Date de naissance facultative : beaucoup de registres d'ecoles n'en ont pas. Un eleve sans date
// est une « fiche incomplete », a completer depuis sa fiche (liste dans Gestion des eleves).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('eleves', function ($table) {
            $table->date('date_naissance')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Impossible de revenir en arriere tant que des eleves n'ont pas de date.
        if (DB::table('eleves')->whereNull('date_naissance')->exists()) {
            return;
        }
        Schema::table('eleves', function ($table) {
            $table->date('date_naissance')->nullable(false)->change();
        });
    }
};
