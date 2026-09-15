<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('eleves', function (Blueprint $table) {
            $table->dropForeign(['classe_id']);
            $table->dropForeign(['session_scolaire_id']);
            $table->dropColumn(['classe_id', 'session_scolaire_id']);
        });
    }

    public function down(): void
    {
        Schema::table('eleves', function (Blueprint $table) {
            $table->foreignId('classe_id')->nullable()->after('etablissement_id');
            $table->foreignId('session_scolaire_id')->nullable()->after('classe_id');
        });
    }
};
