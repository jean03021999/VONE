<?php

namespace App\Services;

use Illuminate\Support\Collection;

/**
 * Reconnait une classe ecrite librement (fichier Excel d'import) parmi les classes de l'annee :
 * « 7e », « 7ème », « 7EME ANNEE », « 6eme A », « Tle SS », « TSM », « 11e S », « PS »...
 *
 * Chaque nom est ramene a trois elements : niveau (CR = Creche, PS, MS, GS, 1 a 13, T), serie (SCI, LIT, SM,
 * SE, SS) et groupe (A, B, 1, 2...). Une ecriture est rattachee a une classe quand un seul
 * candidat reste possible ; sinon elle est refusee avec la liste des classes envisageables.
 */
class CorrespondanceClasses
{
    /** Mots sans valeur pour la reconnaissance. */
    private const MOTS_VIDES = ['annee', 'annees', 'an', 'ans', 'serie', 'series', 'classe', 'sciences', 'science',
        'de', 'des', 'du', 'la', 'le', 'les', 'et', 'en', 'section', 'eme', 'e', 'er', 'ere', 're', 'groupe', 'gr', 'grp'];

    /** Series : mot -> code. « s » et « l » ne valent serie qu'en 11e et 12e (ailleurs : groupe). */
    private const SERIES = [
        'sm' => 'SM', 'math' => 'SM', 'maths' => 'SM', 'mathematique' => 'SM', 'mathematiques' => 'SM',
        'se' => 'SE', 'exp' => 'SE', 'experimentale' => 'SE', 'experimentales' => 'SE',
        'ss' => 'SS', 'soc' => 'SS', 'sociale' => 'SS', 'sociales' => 'SS',
        'sci' => 'SCI', 'sc' => 'SCI', 'scient' => 'SCI', 'scientifique' => 'SCI', 'scientifiques' => 'SCI',
        'lit' => 'LIT', 'litt' => 'LIT', 'litteraire' => 'LIT', 'litteraires' => 'LIT', 'lettres' => 'LIT',
    ];

    private const PRIMAIRE_FRANCAIS = ['cp1' => '1', 'cp2' => '2', 'ce1' => '3', 'ce2' => '4', 'cm1' => '5', 'cm2' => '6'];

    private array $analyses = [];

    public function __construct(private Collection $classes)
    {
        foreach ($classes as $classe) {
            $this->analyses[$classe->id] = self::analyser($classe->nom);
        }
    }

    /**
     * $parIdentifiant : la saisie est l'identifiant interne de la classe (colonne « classe_id »).
     * Sinon « 7 » designe la 7e annee, jamais la classe d'identifiant 7.
     *
     * @return array{0: ?object, 1: ?string} [classe reconnue ou null, message d'erreur]
     */
    public function trouver(?string $saisie, bool $parIdentifiant = false): array
    {
        $saisie = trim((string) $saisie);
        if ($saisie === '') {
            return [null, 'Classe non renseignée.'];
        }

        if ($parIdentifiant) {
            $parId = ctype_digit($saisie) ? $this->classes->firstWhere('id', (int) $saisie) : null;
            return $parId ? [$parId, null] : [null, "Classe non reconnue : identifiant « {$saisie} » absent de l'année active."];
        }

        // 1. Nom identique (sans accents, majuscules ni ponctuation).
        $cle = self::compacter($saisie);
        $identique = $this->classes->first(fn ($c) => self::compacter($c->nom) === $cle);
        if ($identique) {
            return [$identique, null];
        }

        // 2. Niveau, serie et groupe.
        $lue = self::analyser($saisie);
        if ($lue['niveau'] === null) {
            return [null, "Classe non reconnue : « {$saisie} » (niveau introuvable)."];
        }
        $memeNiveau = $this->classes->filter(fn ($c) => $this->analyses[$c->id]['niveau'] === $lue['niveau']);
        if ($memeNiveau->isEmpty()) {
            return [null, "Classe non reconnue : « {$saisie} » (aucune classe de ce niveau cette année)."];
        }

        $candidats = $memeNiveau;
        if ($lue['serie'] !== null) {
            $candidats = $candidats->filter(fn ($c) => $this->analyses[$c->id]['serie'] === $lue['serie']);
        }
        if ($lue['groupe'] !== null) {
            $avecGroupe = $candidats->filter(fn ($c) => $this->analyses[$c->id]['groupe'] === $lue['groupe']);
            // Groupe indique alors que l'ecole n'a qu'une classe sans groupe a ce niveau : on l'accepte.
            $candidats = $avecGroupe->isNotEmpty() || $candidats->contains(fn ($c) => $this->analyses[$c->id]['groupe'] !== null)
                ? $avecGroupe
                : $candidats;
        }

        if ($candidats->count() === 1) {
            return [$candidats->first(), null];
        }

        $liste = ($candidats->isEmpty() ? $memeNiveau : $candidats)->pluck('nom')->implode(', ');
        return [null, $candidats->isEmpty()
            ? "Classe non reconnue : « {$saisie} ». Classes de ce niveau : {$liste}."
            : "Classe ambiguë : « {$saisie} ». Précisez parmi : {$liste}."];
    }

    /** Nom sans accents, en minuscules, lettres et chiffres seulement. */
    public static function compacter(string $texte): string
    {
        return preg_replace('/[^a-z0-9]/', '', self::ascii($texte));
    }

    /**
     * @return array{niveau: ?string, serie: ?string, groupe: ?string}
     */
    public static function analyser(string $nom): array
    {
        $texte = preg_replace('/[^a-z0-9]+/', ' ', self::ascii($nom));
        // « 7eme », « 11e », « 1ere » -> « 7 », « 11 », « 1 » ; « tsm » -> « t sm ».
        $texte = preg_replace('/\b(\d{1,2})\s*(?:eme|em|e|ere|er|re|ieme)\b/', '$1', $texte);
        // Formes collees : « 7ea » -> « 7 a », « 11esm » -> « 11 sm », « 10a » -> « 10 a ».
        $texte = preg_replace('/\b(\d{1,2})(?:eme|e)?([a-z]{1,3})\b/', '$1 $2', $texte);
        $texte = preg_replace('/\bt(sm|se|ss)\b/', 't $1', $texte);
        $mots = array_values(array_filter(explode(' ', trim($texte)), 'strlen'));

        $niveau = null;
        $reste = [];
        foreach ($mots as $i => $mot) {
            if ($niveau === null) {
                if (in_array($mot, ['creche', 'creches', 'garderie', 'pouponniere'], true)) { $niveau = 'CR'; continue; }
                // « P Section », « M Section », « G Section ».
                $suivant = $mots[$i + 1] ?? '';
                if (in_array($mot, ['p', 'm', 'g'], true) && str_starts_with($suivant, 'sect')) {
                    $niveau = ['p' => 'PS', 'm' => 'MS', 'g' => 'GS'][$mot];
                    continue;
                }
                if (in_array($mot, ['ps', 'petite'], true)) { $niveau = 'PS'; continue; }
                if (in_array($mot, ['ms', 'moyenne'], true)) { $niveau = 'MS'; continue; }
                if (in_array($mot, ['gs', 'grande'], true)) { $niveau = 'GS'; continue; }
                if (in_array($mot, ['t', 'tle', 'tale', 'term', 'terminale', 'terminal'], true)) { $niveau = 'T'; continue; }
                // Appellations francaises du primaire : CP1, CP2, CE1, CE2, CM1, CM2 = 1re a 6e annee.
                if (isset(self::PRIMAIRE_FRANCAIS[$mot])) { $niveau = self::PRIMAIRE_FRANCAIS[$mot]; continue; }
                if (preg_match('/^\d{1,2}$/', $mot) && (int) $mot >= 1 && (int) $mot <= 13) { $niveau = (string) (int) $mot; continue; }
            }
            $reste[] = $mot;
        }

        $serie = null;
        $groupe = null;
        foreach ($reste as $mot) {
            if (in_array($mot, self::MOTS_VIDES, true)) {
                continue;
            }
            if ($serie === null && isset(self::SERIES[$mot])) {
                $serie = self::SERIES[$mot];
                continue;
            }
            if ($serie === null && in_array($niveau, ['11', '12'], true) && in_array($mot, ['s', 'l'], true)) {
                $serie = $mot === 's' ? 'SCI' : 'LIT';
                continue;
            }
            if ($groupe === null && preg_match('/^([a-z]|\d{1,2})$/', $mot)) {
                $groupe = strtoupper($mot);
            }
        }

        return ['niveau' => $niveau, 'serie' => $serie, 'groupe' => $groupe];
    }

    private static function ascii(string $texte): string
    {
        $texte = trim($texte);
        $translitere = class_exists(\Normalizer::class)
            ? preg_replace('/\p{Mn}/u', '', \Normalizer::normalize($texte, \Normalizer::FORM_D))
            : @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $texte);

        return mb_strtolower((string) ($translitere ?: $texte));
    }
}
