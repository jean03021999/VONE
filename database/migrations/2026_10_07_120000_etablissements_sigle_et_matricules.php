<?php

use App\Services\Numerotation;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Sigle de l'ecole (Parametres > Etablissement), prefixe des matricules et references, pre-rempli
// avec les initiales du nom. Les matricules generes par LAKOLI (LAK-2026-001, ENS-2026-001) prennent
// le sigle de leur ecole (GSSE-2026-001, GSSE-ENS-2026-001) ; les matricules saisis ou importes et
// les references des paiements, depenses et salaires passes (deja sur des recus) ne changent pas.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('etablissements', function (Blueprint $table) {
            $table->string('sigle', 8)->nullable()->after('code');
        });

        foreach (DB::table('etablissements')->get(['id', 'nom']) as $etablissement) {
            $sigle = Numerotation::initiales($etablissement->nom);
            DB::table('etablissements')->where('id', $etablissement->id)->update(['sigle' => $sigle]);

            $this->renommer('eleves', $etablissement->id, '/^LAK-(\d{4}-\d+)$/', "{$sigle}-");
            $this->renommer('enseignants', $etablissement->id, '/^ENS-(\d{4}-\d+)$/', "{$sigle}-ENS-");
        }
    }

    /** Remplace le prefixe ; un matricule deja pris (autre ecole de meme sigle) reste inchange. */
    private function renommer(string $table, int $etablissementId, string $motif, string $prefixe): void
    {
        $lignes = DB::table($table)->where('etablissement_id', $etablissementId)->get(['id', 'matricule']);
        foreach ($lignes as $ligne) {
            if (!preg_match($motif, (string) $ligne->matricule, $m)) {
                continue;
            }
            $nouveau = $prefixe . $m[1];
            if (!DB::table($table)->where('matricule', $nouveau)->exists()) {
                DB::table($table)->where('id', $ligne->id)->update(['matricule' => $nouveau]);
            }
        }
    }

    public function down(): void
    {
        foreach (DB::table('etablissements')->get(['id', 'sigle']) as $etablissement) {
            if (!$etablissement->sigle) {
                continue;
            }
            $sigle = preg_quote($etablissement->sigle, '/');
            $this->renommer('enseignants', $etablissement->id, "/^{$sigle}-ENS-(\d{4}-\d+)$/", 'ENS-');
            $this->renommer('eleves', $etablissement->id, "/^{$sigle}-(\d{4}-\d+)$/", 'LAK-');
        }

        Schema::table('etablissements', function (Blueprint $table) {
            $table->dropColumn('sigle');
        });
    }
};
