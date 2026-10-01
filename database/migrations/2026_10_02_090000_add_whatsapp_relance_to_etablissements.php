<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Numero WhatsApp de la comptabilite, donne aux familles dans les messages et lettres de relance.
    public function up(): void
    {
        Schema::table('etablissements', function (Blueprint $table) {
            $table->string('whatsapp_relance', 50)->nullable()->after('telephone_secondaire');
        });
    }

    public function down(): void
    {
        Schema::table('etablissements', function (Blueprint $table) {
            $table->dropColumn('whatsapp_relance');
        });
    }
};
