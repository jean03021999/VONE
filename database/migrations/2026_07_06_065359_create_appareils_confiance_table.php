<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('appareils_confiance', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('token_appareil')->unique();
            $table->string('nom_appareil')->nullable();
            $table->timestamp('date_expiration');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('appareils_confiance');
    }
};
