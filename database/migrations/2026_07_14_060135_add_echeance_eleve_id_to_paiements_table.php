<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('paiements', function (Blueprint $table) {
            $table->foreignId('echeance_eleve_id')->nullable()->after('eleve_id')->constrained('echeances_eleves')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('paiements', function (Blueprint $table) {
            $table->dropForeign(['echeance_eleve_id']);
            $table->dropColumn('echeance_eleve_id');
        });
    }
};
