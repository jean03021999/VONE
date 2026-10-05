<?php

namespace App\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Decide si la connexion exige le code de verification (OTP) apres le mot de passe.
 *
 * OTP_ACTIF=false (installation sur un seul poste) n'est respecte que si l'application est servie
 * uniquement en local : APP_URL en 127.0.0.1 / localhost ET requete venue de ce meme PC, sans
 * passage par un relais. Dans tout autre cas, l'OTP est reactive et l'anomalie journalisee.
 */
class VerificationConnexion
{
    private const HOTES_LOCAUX = ['127.0.0.1', 'localhost', '::1', '[::1]'];

    public static function otpRequis(Request $request): bool
    {
        // Poste de developpement : comportement historique, sans OTP.
        if (app()->environment('local')) {
            return false;
        }
        if (config('lakoli.otp_actif')) {
            return true;
        }

        if (! self::urlLocale()) {
            Log::critical('OTP_ACTIF=false refusé : APP_URL (' . config('app.url') . ') n\'est pas une adresse locale. '
                . 'Code de vérification réactivé. Pour un accès en réseau : OTP_ACTIF=true et envoi d\'e-mails configuré.');
            return true;
        }
        if (! self::requeteLocale($request)) {
            Log::critical('OTP_ACTIF=false refusé : connexion depuis ' . $request->ip() . ', hors de ce PC. '
                . 'Code de vérification réactivé. Le serveur ne doit écouter que sur 127.0.0.1.');
            return true;
        }

        return false;
    }

    public static function urlLocale(): bool
    {
        $hote = parse_url((string) config('app.url'), PHP_URL_HOST);

        return in_array(strtolower((string) $hote), self::HOTES_LOCAUX, true);
    }

    private static function requeteLocale(Request $request): bool
    {
        $adresse = $request->server('REMOTE_ADDR');

        return in_array($adresse, ['127.0.0.1', '::1'], true)
            && ! $request->headers->has('X-Forwarded-For')
            && in_array(strtolower($request->getHost()), self::HOTES_LOCAUX, true);
    }
}
