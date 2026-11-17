<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Ecoles creees avant la periode d'essai : statut « essai » sans date de fin, donc essai illimite
// (rien n'etait jamais bloque). A la mise a jour, elles recoivent un mois d'essai a partir du jour
// de la mise a jour, dernier jour compris. Prolonger ou activer : php artisan lakoli:essai.
return new class extends Migration
{
    public function up(): void
    {
        DB::table('etablissements')
            ->where('statut', 'essai')
            ->whereNull('date_fin_essai')
            ->update(['date_fin_essai' => today()->addDays(29)->toDateString(), 'updated_at' => now()]);
    }

    public function down(): void
    {
        // Rien a annuler : remettre un essai illimite n'a pas de sens.
    }
};
