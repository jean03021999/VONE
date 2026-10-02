<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Annulation d'un salaire paye par erreur (statut « annule », auteur, date, motif) : il reste
    // visible mais ne compte plus dans la caisse. Un seul salaire NON annule par enseignant et par
    // mois (index unique partiel) : on peut ressaisir le bon salaire apres une annulation.
    public function up(): void
    {
        Schema::table('salaires', function (Blueprint $table) {
            $table->timestamp('annule_le')->nullable();
            $table->foreignId('annule_par')->nullable()->constrained('users')->nullOnDelete();
            $table->text('motif_annulation')->nullable();
        });
        DB::statement('ALTER TABLE salaires DROP CONSTRAINT salaires_statut_check');
        DB::statement("ALTER TABLE salaires ADD CONSTRAINT salaires_statut_check CHECK (statut::text = ANY (ARRAY['en_attente', 'paye', 'annule']::text[]))");
        DB::statement('ALTER TABLE salaires DROP CONSTRAINT IF EXISTS salaires_enseignant_id_mois_annee_unique');
        DB::statement("CREATE UNIQUE INDEX salaires_enseignant_mois_annee_actif_unique ON salaires (enseignant_id, mois, annee) WHERE statut <> 'annule'");
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS salaires_enseignant_mois_annee_actif_unique');
        DB::statement("DELETE FROM salaires WHERE statut = 'annule'");
        DB::statement('ALTER TABLE salaires ADD CONSTRAINT salaires_enseignant_id_mois_annee_unique UNIQUE (enseignant_id, mois, annee)');
        DB::statement('ALTER TABLE salaires DROP CONSTRAINT salaires_statut_check');
        DB::statement("ALTER TABLE salaires ADD CONSTRAINT salaires_statut_check CHECK (statut::text = ANY (ARRAY['en_attente', 'paye']::text[]))");
        Schema::table('salaires', function (Blueprint $table) {
            $table->dropForeign(['annule_par']);
            $table->dropColumn(['annule_le', 'annule_par', 'motif_annulation']);
        });
    }
};
