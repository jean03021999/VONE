<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Periode d'essai terminee (ou compte suspendu) : l'application passe en lecture seule. Les
 * consultations (GET) restent permises, et donc l'impression des recus, listes et releves ; toute
 * ecriture est refusee (403), sauf la connexion et la securite du compte (mot de passe, connexions,
 * appareils), qu'on doit toujours pouvoir proteger. Voir Etablissement::etatAbonnement.
 */
class LectureSeuleAbonnement
{
    /** Ecritures toujours permises (chemins sous /api). */
    private const PERMIS = [
        'api/auth/*',
        'api/parametres/mot-de-passe',
        'api/parametres/connexions/*',
        'api/parametres/appareils',
        'api/parametres/appareils/*',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->isMethodSafe() || $request->is(...self::PERMIS)) {
            return $next($request);
        }

        $etablissement = $request->user('sanctum')?->etablissement;
        if ($etablissement && $etablissement->etatAbonnement()['lecture_seule']) {
            $message = $etablissement->statut === 'suspendu'
                ? "Le compte de l'établissement est suspendu : LAKOLI est en lecture seule (consultation et impression uniquement). Contactez LAKOLI pour le réactiver."
                : "La période d'essai de LAKOLI est terminée : l'application est en lecture seule (consultation et impression uniquement). Contactez LAKOLI pour activer votre abonnement.";

            return response()->json(['message' => $message, 'code' => 'lecture_seule'], 403);
        }

        return $next($request);
    }
}
