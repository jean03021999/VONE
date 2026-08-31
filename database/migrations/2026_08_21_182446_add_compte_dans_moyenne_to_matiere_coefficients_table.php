<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('matiere_coefficients', function (Blueprint $table) {
            $table->boolean('compte_dans_moyenne')->default(true)->after('coefficient');
        });
    }

    public function down(): void
    {
        Schema::table('matiere_coefficients', function (Blueprint $table) {
            $table->dropColumn('compte_dans_moyenne');
        });
    }
};
