<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Limitation des tentatives sur les routes d'authentification (mot de passe, code, mot de
        // passe oublie) : 5 par minute pour un meme identifiant depuis une meme adresse, 20 par
        // minute pour une meme adresse tous identifiants confondus.
        RateLimiter::for('connexion', function (Request $request) {
            $trop = fn () => response()->json([
                'message' => 'Trop de tentatives. Patientez une minute avant de réessayer.',
            ], 429);
            $identifiant = strtolower(trim((string) ($request->input('identifiant') ?? $request->input('email'))));

            return [
                Limit::perMinute(5)->by('id|' . $identifiant . '|' . $request->ip())->response($trop),
                Limit::perMinute(20)->by('ip|' . $request->ip())->response($trop),
            ];
        });
    }
}
