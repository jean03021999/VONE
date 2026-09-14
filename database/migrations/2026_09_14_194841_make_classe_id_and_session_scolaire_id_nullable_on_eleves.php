<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('eleves', function (Blueprint $table) {
            $table->foreignId('classe_id')->nullable()->change();
            $table->foreignId('session_scolaire_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('eleves', function (Blueprint $table) {
            $table->foreignId('classe_id')->nullable(false)->change();
            $table->foreignId('session_scolaire_id')->nullable(false)->change();
        });
    }
};
