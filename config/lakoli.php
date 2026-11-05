<?php

return [
    // Code de verification (OTP) apres le mot de passe. Actif par defaut. OTP_ACTIF=false n'est
    // admis que pour une installation sur un seul poste, servie uniquement en local (APP_URL en
    // 127.0.0.1 ou localhost, acces depuis ce meme PC) : voir App\Services\VerificationConnexion.
    'otp_actif' => (bool) env('OTP_ACTIF', true),

    // Acces distant par Tailscale, desactive par defaut (activer-acces-distant.ps1 le met a true) :
    // connexions de 100.64.0.0/10 sans OTP. Voir App\Services\VerificationConnexion.
    'tailscale_actif' => (bool) env('TAILSCALE_ACTIF', false),
];
