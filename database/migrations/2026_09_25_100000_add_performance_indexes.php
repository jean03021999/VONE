<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PostgreSQL n'indexe pas automatiquement les cles etrangeres (constrained() n'ajoute que la
 * contrainte) : index sur les colonnes filtrees ou jointes en permanence.
 */
return new class extends Migration
{
    private array $index = [
        'eleves' => [['etablissement_id']],
        'inscriptions' => [['classe_id', 'statut']],
        'echeances_eleves' => [['frais_eleve_id']],
        'paiements' => [['echeance_eleve_id'], ['eleve_id', 'date_paiement']],
        'bulletins' => [['periode_id', 'statut', 'eleve_id']],
        'lignes_bulletin' => [['bulletin_id']],
        'notes' => [['eleve_id']],
        'evaluations' => [['affectation_id', 'periode_id']],
        'affectations' => [['classe_id']],
        'matiere_coefficients' => [['matiere_id']],
    ];

    public function up(): void
    {
        foreach ($this->index as $table => $listes) {
            Schema::table($table, function (Blueprint $t) use ($listes) {
                foreach ($listes as $colonnes) {
                    $t->index($colonnes);
                }
            });
        }
    }

    public function down(): void
    {
        foreach ($this->index as $table => $listes) {
            Schema::table($table, function (Blueprint $t) use ($listes) {
                foreach ($listes as $colonnes) {
                    $t->dropIndex($colonnes);
                }
            });
        }
    }
};
