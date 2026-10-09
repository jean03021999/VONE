<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Decoupage des frais de scolarite de l'ecole, modele des nouvelles grilles tarifaires :
// {"mode": "trimestriel" | "mensuel" | "libre", "mois": [5, 6, 10, ...] (ordre de paiement),
//  "jour_limite": 10}. Vide = trimestriel (comportement d'avant).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('etablissements', function (Blueprint $table) {
            $table->json('decoupage_frais')->nullable()->after('cycles');
        });
    }

    public function down(): void
    {
        Schema::table('etablissements', function (Blueprint $table) {
            $table->dropColumn('decoupage_frais');
        });
    }
};
