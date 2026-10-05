<?php

return [
    // Code de verification (OTP) apres le mot de passe. Actif par defaut. OTP_ACTIF=false n'est
    // admis que pour une installation sur un seul poste, servie uniquement en local (APP_URL en
    // 127.0.0.1 ou localhost, acces depuis ce meme PC) : voir App\Services\VerificationConnexion.
    'otp_actif' => (bool) env('OTP_ACTIF', true),
];
