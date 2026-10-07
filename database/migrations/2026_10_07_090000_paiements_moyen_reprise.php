<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Moyen de paiement « reprise » : paiements encaisses avant LAKOLI, repris a l'import Excel. Ils
// soldent les echeances des eleves mais ne comptent ni dans la caisse ni dans l'arrete de caisse.
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }
        DB::statement('ALTER TABLE paiements DROP CONSTRAINT IF EXISTS paiements_moyen_paiement_check');
        DB::statement("ALTER TABLE paiements ADD CONSTRAINT paiements_moyen_paiement_check CHECK (moyen_paiement::text = ANY (ARRAY['especes', 'mobile_money', 'virement', 'cheque', 'reprise']::text[]))");
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }
        DB::statement("DELETE FROM paiements WHERE moyen_paiement = 'reprise'");
        DB::statement('ALTER TABLE paiements DROP CONSTRAINT IF EXISTS paiements_moyen_paiement_check');
        DB::statement("ALTER TABLE paiements ADD CONSTRAINT paiements_moyen_paiement_check CHECK (moyen_paiement::text = ANY (ARRAY['especes', 'mobile_money', 'virement', 'cheque']::text[]))");
    }
};
