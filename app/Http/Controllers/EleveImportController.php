<?php

namespace App\Http\Controllers;

use App\Models\Eleve;
use App\Models\Classe;
use App\Models\GrilleTarifaire;
use App\Models\Inscription;
use App\Models\Paiement;
use App\Models\SessionScolaire;
use App\Services\CorrespondanceClasses;
use App\Services\FraisService;
use App\Services\InscriptionService;
use App\Services\Numerotation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as DateExcel;
use Carbon\Carbon;

class EleveImportController extends Controller
{
    private array $variantesNom = ['nom', 'nomdefamille', 'nomfamille', 'lastname'];
    private array $variantesPrenom = ['prenom', 'prenoms', 'premierprenom', 'firstname'];

    private array $synonymesParMotif = [
        // Inscription ou reinscription (ex. "Type d'inscription", "Nouveau/Ancien", "Ancien eleve")
        'typedinscription' => 'type_inscription',
        'typedeinscription' => 'type_inscription',
        'typeinscription' => 'type_inscription',
        'inscriptionreinscription' => 'type_inscription',
        'nouveauancien' => 'type_inscription',
        'ancieneleve' => 'type_inscription',
        // Frais d'inscription / reinscription deja encaisses par l'ecole (montant en GNF)
        'fraisdinscription' => 'frais_inscription',
        'fraisdeinscription' => 'frais_inscription',
        'fraisinscription' => 'frais_inscription',
        'fraisdereinscription' => 'frais_inscription',
        'fraisreinscription' => 'frais_inscription',
        'montantinscription' => 'frais_inscription',
        // Scolarite deja payee avant LAKOLI (total), apres les frais d'inscription : « Frais
        // d'inscription payés » reste un frais d'inscription.
        'scolaritepayee' => 'scolarite_payee',
        'scolaritepaye' => 'scolarite_payee',
        'scolariteversee' => 'scolarite_payee',
        'scolaritedejapayee' => 'scolarite_payee',
        'totalpaye' => 'scolarite_payee',
        'totalverse' => 'scolarite_payee',
        'montantpaye' => 'scolarite_payee',
        'montantverse' => 'scolarite_payee',
        'dejapaye' => 'scolarite_payee',
        'sommepayee' => 'scolarite_payee',
        'sommeversee' => 'scolarite_payee',
        'matricule' => 'matricule',
        'datedenaissance' => 'date_naissance',
        'datenaissance' => 'date_naissance',
        'ddn' => 'date_naissance',
        'lieudenaissance' => 'lieu_naissance',
        'lieunaissance' => 'lieu_naissance',
        'nomdupere' => 'pere_nom',
        'perenom' => 'pere_nom',
        'nompere' => 'pere_nom',
        'telephonedupere' => 'pere_telephone',
        'peretelephone' => 'pere_telephone',
        'telephonepere' => 'pere_telephone',
        'telpere' => 'pere_telephone',
        'nomdelamere' => 'mere_nom',
        'merenom' => 'mere_nom',
        'nommere' => 'mere_nom',
        'telephonedelamere' => 'mere_telephone',
        'meretelephone' => 'mere_telephone',
        'telephonemere' => 'mere_telephone',
        'telmere' => 'mere_telephone',
        'nomdututeur' => 'tuteur_nom',
        'tuteurnom' => 'tuteur_nom',
        'nomtuteur' => 'tuteur_nom',
        'telephonedututeur' => 'tuteur_telephone',
        'tuteurtelephone' => 'tuteur_telephone',
        'telephonetuteur' => 'tuteur_telephone',
        'teltuteur' => 'tuteur_telephone',
        'liendututeur' => 'tuteur_lien',
        'tuteurlien' => 'tuteur_lien',
        'lientuteur' => 'tuteur_lien',
        'lienavecleleve' => 'tuteur_lien',
        'classe' => 'classe',
    ];

    /**
     * En-tetes reconnus seulement s'ils sont ecrits tels quels : « Telephone du pere » ou
     * « Profession du pere » contiennent aussi « pere » et ne doivent pas devenir le nom du pere.
     */
    private array $synonymesExacts = [
        'pere' => 'pere_nom',
        'mere' => 'mere_nom',
        // Identifiant interne de la classe (anciens exports LAKOLI) : « 7 » y vaut la classe n°7.
        'classeid' => 'classe_id',
        'idclasse' => 'classe_id',
        'idelaclasse' => 'classe_id',
    ];

    /** En-tetes d'une colonne unique contenant nom et prenoms (« Élève », « Nom complet »). */
    private array $variantesNomComplet = ['nomcomplet', 'eleve', 'eleves', 'nomdeleleve', 'nomdeleleves', 'nomsdeseleves', 'nomdeseleves', 'identite', 'identitedeleleve'];

    /** Nombre de lignes parcourues pour trouver la ligne d'en-tetes (titre eventuel au-dessus). */
    private const LIGNES_RECHERCHE_ENTETES = 10;

    /** Colonnes de paiement par mois (« Octobre », « Janv », « Mars 2027 »). */
    private const MOIS = 'janvier|janv|fevrier|fevr|fev|mars|avril|avr|mai|juin|juillet|juil|aout|septembre|sept|sep|octobre|oct|novembre|nov|decembre|dec';

    /** Grille de scolarite par « classe|type d'inscription », le temps d'une analyse. */
    private array $grillesScolarite = [];

    /**
     * Normalise un texte : minuscule, sans accent, sans espace/ponctuation.
     * Utilise iconv pour une vraie translitteration Unicode plutot que
     * des remplacements de caracteres manuels.
     */
    private function normaliserTexte(?string $texte): string
    {
        if ($texte === null) {
            return '';
        }
        $texte = trim($texte);
        $translitere = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $texte);
        if ($translitere === false) {
            $translitere = $texte;
        }
        $texte = mb_strtolower($translitere);
        return preg_replace('/[^a-z0-9]/', '', $texte);
    }

    /**
     * Convertit une date de naissance vers Y-m-d ; null si elle est invalide ou invraisemblable.
     *
     * $valeur est la valeur BRUTE de la cellule : un numero de serie Excel pour une cellule date
     * (le texte affiche par PhpSpreadsheet suit le format americain m/d/yy et inverserait jour et
     * mois), sinon le texte saisi : jour/mois/annee (separateurs / - . et annee sur 2 ou 4
     * chiffres) ou annee-mois-jour. Un jour ou un mois impossible (31/02, 13e mois) est refuse au
     * lieu d'etre reporte sur le mois suivant.
     */
    private function normaliserDate($valeur): ?string
    {
        if ($valeur === null || $valeur === '') {
            return null;
        }

        if (is_int($valeur) || is_float($valeur)) {
            try {
                $date = Carbon::instance(DateExcel::excelToDateTimeObject((float) $valeur));
            } catch (\Throwable $e) {
                return null;
            }
            return $this->dateVraisemblable($date);
        }

        $valeur = trim((string) $valeur);
        if (preg_match('/^(\d{4})[\/.-](\d{1,2})[\/.-](\d{1,2})$/', $valeur, $m)) {
            [$annee, $mois, $jour] = [(int) $m[1], (int) $m[2], (int) $m[3]];
        } elseif (preg_match('/^(\d{1,2})[\/.-](\d{1,2})[\/.-](\d{2}|\d{4})$/', $valeur, $m)) {
            [$jour, $mois, $annee] = [(int) $m[1], (int) $m[2], (int) $m[3]];
            if (strlen($m[3]) === 2) {
                // 14 -> 2014, 98 -> 1998 : une annee a deux chiffres posterieure a l'annee en cours
                // appartient au siecle precedent.
                $annee += $annee <= (int) date('y') ? 2000 : 1900;
            }
        } else {
            return null;
        }

        if (!checkdate($mois, $jour, $annee)) {
            return null;
        }
        return $this->dateVraisemblable(Carbon::create($annee, $mois, $jour));
    }

    /** Date de naissance acceptee : ni dans le futur, ni avant 1900. */
    private function dateVraisemblable(Carbon $date): ?string
    {
        if ($date->year < 1900 || $date->isAfter(Carbon::today())) {
            return null;
        }
        return $date->format('Y-m-d');
    }

    /**
     * Construit la correspondance colonne -> champ, quel que soit
     * l'ordre des colonnes dans le fichier source.
     */
    private function detecterColonnes(array $entetes): array
    {
        $mapping = [];

        foreach ($entetes as $index => $entete) {
            $normalise = $this->normaliserTexte($entete ?? '');
            if ($normalise === '') {
                continue;
            }

            $nomComplet = $this->lireEnteteNomComplet($normalise);
            if ($nomComplet !== null) {
                if (!isset($mapping['nom_complet'])) {
                    $mapping['nom_complet'] = $index;
                    $mapping['ordre_nom_complet'] = $nomComplet;
                }
                continue;
            }

            if (in_array($normalise, $this->variantesNom, true) && !isset($mapping['nom'])) {
                $mapping['nom'] = $index;
                continue;
            }
            if (in_array($normalise, $this->variantesPrenom, true) && !isset($mapping['prenom'])) {
                $mapping['prenom'] = $index;
                continue;
            }

            // Paiements de scolarite par tranche (« Tranche 1 », « T2 », « 3ème trimestre ») ou
            // par mois (« Octobre ») ; jamais une colonne de date (« Date tranche 1 »).
            if (!str_contains($normalise, 'date')) {
                if (preg_match('/^(?:tranche|trimestre|versement|echeance|t)(\d{1,2})(?!\d)/', $normalise, $m)
                    || preg_match('/^(\d{1,2})(?:er|ere|iere|eme|e)?(?:tranche|trimestre|versement|echeance)/', $normalise, $m)) {
                    $mapping['tranches'][(int) $m[1]] ??= $index;
                    continue;
                }
                if (preg_match('/^(' . self::MOIS . ')(\d{2}|\d{4})?$/', $normalise)) {
                    $mapping['mois'][trim((string) $entete)] = $index;
                    continue;
                }
            }

            $champExact = $this->synonymesExacts[$normalise] ?? null;
            if ($champExact !== null) {
                if (!isset($mapping[$champExact])) {
                    $mapping[$champExact] = $index;
                }
                continue;
            }

            foreach ($this->synonymesParMotif as $motif => $champ) {
                if (str_contains($normalise, $motif) && !isset($mapping[$champ])) {
                    $mapping[$champ] = $index;
                    break;
                }
            }
        }

        return $mapping;
    }

    /**
     * Colonne unique « Nom et prénoms » : 'nom_prenom' (nom en tete), 'prenom_nom' (prenoms en
     * tete) ou null si l'en-tete n'en est pas une. « Prénom du père » n'en est pas une.
     */
    private function lireEnteteNomComplet(string $normalise): ?string
    {
        if (preg_match('/pere|mere|tuteur/', $normalise)) {
            return null;
        }
        if (in_array($normalise, $this->variantesNomComplet, true) || preg_match('/^noms?(et)?prenoms?/', $normalise)) {
            return 'nom_prenom';
        }
        if (preg_match('/^prenoms?(et)?noms?($|de)/', $normalise)) {
            return 'prenom_nom';
        }
        return null;
    }

    /**
     * Ligne d'en-tetes d'une feuille : la premiere, parmi les premieres lignes, ou l'on reconnait
     * les colonnes Nom et Prenom, ou une colonne « Nom et prénoms » (un titre « Liste des eleves
     * 2026-2027 » peut la preceder ; un titre tient dans une cellule, pas une ligne d'en-tetes).
     * Retourne [index de la ligne, correspondance colonne -> champ] ou null.
     */
    private function trouverEntetes(array $lignes): ?array
    {
        foreach (array_slice($lignes, 0, self::LIGNES_RECHERCHE_ENTETES, true) as $index => $ligne) {
            if (count(array_filter($ligne, fn ($v) => trim((string) $v) !== '')) < 2) {
                continue;
            }
            $mapping = $this->detecterColonnes($ligne);
            if (isset($mapping['nom'], $mapping['prenom']) || isset($mapping['nom_complet'])) {
                return [$index, $mapping];
            }
        }
        return null;
    }

    /**
     * Separe « DIALLO Mamadou Saliou » en [nom, prenoms]. Les mots en MAJUSCULES forment le nom
     * quand le reste ne l'est pas (quel que soit l'ordre) ; sinon le premier mot est le nom
     * (dernier mot pour une colonne « Prénoms et nom »). Un seul mot : prenom vide (erreur).
     */
    private function decouperNomComplet(string $valeur, string $ordre): array
    {
        $mots = preg_split('/\s+/u', trim($valeur), -1, PREG_SPLIT_NO_EMPTY);
        if (count($mots) < 2) {
            return [trim($valeur), ''];
        }

        $majuscule = fn (string $mot) => preg_match('/\p{L}/u', $mot) && mb_strtoupper($mot) === $mot && mb_strtolower($mot) !== $mot;
        $enMajuscules = array_map($majuscule, $mots);
        $nbMajuscules = count(array_filter($enMajuscules));
        if ($nbMajuscules > 0 && $nbMajuscules < count($mots)) {
            // Mots en majuscules groupes en debut ou en fin : c'est le nom de famille.
            $enTete = array_slice($enMajuscules, 0, $nbMajuscules) === array_fill(0, $nbMajuscules, true);
            $enFin = array_slice($enMajuscules, -$nbMajuscules) === array_fill(0, $nbMajuscules, true);
            if ($enTete) {
                return [implode(' ', array_slice($mots, 0, $nbMajuscules)), implode(' ', array_slice($mots, $nbMajuscules))];
            }
            if ($enFin) {
                return [implode(' ', array_slice($mots, -$nbMajuscules)), implode(' ', array_slice($mots, 0, -$nbMajuscules))];
            }
        }

        return $ordre === 'prenom_nom'
            ? [end($mots), implode(' ', array_slice($mots, 0, -1))]
            : [$mots[0], implode(' ', array_slice($mots, 1))];
    }

    private function extraireValeur(array $ligne, array $mapping, string $champ): string
    {
        if (!isset($mapping[$champ])) {
            return '';
        }
        return trim((string) ($ligne[$mapping[$champ]] ?? ''));
    }

    /** Valeur brute de la cellule (nombre pour une date ou un montant saisis comme tels). */
    private function extraireBrut(array $ligneBrute, array $mapping, string $champ): mixed
    {
        if (!isset($mapping[$champ])) {
            return null;
        }
        $valeur = $ligneBrute[$mapping[$champ]] ?? null;
        return is_string($valeur) ? trim($valeur) : $valeur;
    }

    /**
     * Type d'inscription lu dans le fichier : 'inscription', 'reinscription', null (cellule vide :
     * inscription par defaut) ou false (valeur non reconnue).
     */
    private function lireTypeInscription(string $valeur): string|null|false
    {
        $v = $this->normaliserTexte($valeur);
        if ($v === '') {
            return null;
        }
        if (in_array($v, ['r', 're', 'a', 'ancien', 'ancienne', 'ancieneleve', 'oui', 'o'], true)
            || str_starts_with($v, 'reinscri') || str_starts_with($v, 'ancien')) {
            return 'reinscription';
        }
        if (in_array($v, ['n', 'i', 'nouveau', 'nouvelle', 'nouveaueleve', 'non'], true)
            || str_starts_with($v, 'inscri') || str_starts_with($v, 'nouv')) {
            return 'inscription';
        }
        return false;
    }

    /** Montant en GNF ("200 000", "200000 GNF", "200.000") ; null si vide, false si illisible. */
    private function lireMontant(string $valeur): float|null|false
    {
        $v = preg_replace('/(gnf|fg|\s|\x{00A0}|\x{202F})/iu', '', $valeur);
        // « - », « — », « néant » : rien paye (les registres marquent ainsi les mois non payes).
        if ($v === '' || preg_match('/^(0+|[-–—_.]+|neant|néant|rien|nul)$/iu', $v)) {
            return null;
        }
        $v = str_replace([',', '.'], '', $v);
        return ctype_digit($v) ? (float) $v : false;
    }

    /**
     * Montant de la cellule de la colonne $index : valeur brute si la cellule est numerique (le
     * texte affiche « 200,000.00 » serait lu 20 000 000), sinon le texte. null si vide, false si illisible.
     */
    private function lireMontantCellule(array $ligne, array $ligneBrute, ?int $index): float|null|false
    {
        if ($index === null) {
            return null;
        }
        $brut = $ligneBrute[$index] ?? null;
        if (is_int($brut) || is_float($brut)) {
            return $brut > 0 ? (float) $brut : ($brut == 0 ? null : false);
        }
        return $this->lireMontant(trim((string) ($ligne[$index] ?? '')));
    }

    /**
     * Scolarite deja payee, lue dans l'une des trois formes : colonnes par tranche (« Tranche 1 »,
     * « 2ème trimestre »), colonnes par mois (« Octobre »...) additionnees, ou total (« Scolarité
     * payée »). Une colonne de total presente a cote des tranches ou des mois doit leur etre egale.
     * Retourne ['total' => float|null, 'par_tranche' => [numero => montant]|null] ou un message d'erreur.
     */
    private function lireScolarite(array $ligne, array $ligneBrute, array $mapping): array|string
    {
        $parTranche = [];
        foreach ($mapping['tranches'] ?? [] as $numero => $index) {
            $montant = $this->lireMontantCellule($ligne, $ligneBrute, $index);
            if ($montant === false) {
                return "Montant de la tranche {$numero} illisible : " . trim((string) ($ligne[$index] ?? ''));
            }
            if ($montant !== null) {
                $parTranche[$numero] = $montant;
            }
        }

        $sommeMois = null;
        $parMois = [];
        foreach ($mapping['mois'] ?? [] as $libelle => $index) {
            $montant = $this->lireMontantCellule($ligne, $ligneBrute, $index);
            if ($montant === false) {
                return "Montant du mois « {$libelle} » illisible : " . trim((string) ($ligne[$index] ?? ''));
            }
            if ($montant !== null) {
                $sommeMois = ($sommeMois ?? 0) + $montant;
                $numero = self::numeroMois((string) $libelle);
                $parMois[$numero] = ($parMois[$numero] ?? 0) + $montant;
            }
        }

        $total = $this->lireMontantCellule($ligne, $ligneBrute, $mapping['scolarite_payee'] ?? null);
        if ($total === false) {
            return 'Scolarité payée illisible : ' . $this->extraireValeur($ligne, $mapping, 'scolarite_payee');
        }

        $detail = $parTranche !== [] ? array_sum($parTranche) : $sommeMois;
        if ($detail !== null && $total !== null && abs($detail - $total) >= 1) {
            $source = $parTranche !== [] ? 'des tranches' : 'des mois';
            return 'Total payé (' . (int) $total . " GNF) différent de la somme {$source} (" . (int) $detail . ' GNF) : corrigez le fichier.';
        }

        return [
            'total' => $detail ?? $total,
            'par_tranche' => $parTranche !== [] ? $parTranche : null,
            // Colonnes de mois : [numero du mois => montant], rapprochees des mensualites de la grille.
            'par_mois' => $parTranche === [] && $parMois !== [] ? $parMois : null,
        ];
    }

    /** Numero (1 a 12) du mois d'un en-tete ou d'un libelle d'echeance (« Octobre », « Janv », « Mars 2027 ») ; 0 sinon. */
    private static function numeroMois(string $libelle): int
    {
        $n = preg_replace('/[^a-z]/', '', strtolower((string) @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $libelle)));
        $prefixes = ['janv' => 1, 'fev' => 2, 'mars' => 3, 'avr' => 4, 'mai' => 5, 'juin' => 6, 'juil' => 7,
            'aout' => 8, 'sep' => 9, 'oct' => 10, 'nov' => 11, 'dec' => 12];
        foreach ($prefixes as $prefixe => $numero) {
            if (str_starts_with($n, $prefixe)) {
                return $numero;
            }
        }

        return 0;
    }

    private function verifierChampsObligatoires(array $donnee): ?string
    {
        if ($donnee['nom'] === '') {
            return 'Nom manquant.';
        }
        if ($donnee['prenom'] === '') {
            return 'Prénom manquant.';
        }
        if ($donnee['date_naissance_brute'] === '') {
            return 'Date de naissance manquante.';
        }
        return null;
    }

    /**
     * Classes de l'annee scolaire active de l'etablissement, dans l'ordre pedagogique. Seules ces
     * classes sont proposees et reconnues a l'import : une classe homonyme d'une annee precedente
     * (« 7ème Année » 2026-2027 et 2027-2028) ne doit jamais recevoir les nouveaux eleves.
     */
    private function classesAnneeActive(int $etablissementId)
    {
        $sessionId = SessionScolaire::where('etablissement_id', $etablissementId)->where('est_active', true)->value('id');

        return Classe::where('etablissement_id', $etablissementId)
            ->where('session_scolaire_id', $sessionId)
            ->ordonneesPedagogiquement()
            ->get();
    }

    /**
     * Eleve deja enregistre correspondant a la ligne : [Eleve|null, message d'erreur|null].
     *
     * Un matricule du fichier deja porte par un AUTRE eleve (nom, prenom ou date differents) est
     * une erreur : sinon cet autre eleve serait reinscrit a la place du nouveau. L'identite est
     * comparee sans tenir compte des majuscules (« DIALLO » = « Diallo »).
     */
    private function trouverEleveExistant(array $donnee, int $etablissementId): array
    {
        $memeIdentite = fn (Eleve $e) => mb_strtolower($e->nom) === mb_strtolower($donnee['nom'])
            && mb_strtolower($e->prenom) === mb_strtolower($donnee['prenom'])
            && Carbon::parse($e->date_naissance)->format('Y-m-d') === $donnee['date_naissance'];

        if (!empty($donnee['matricule'])) {
            // Le matricule est unique pour toute la base, eleves supprimes compris.
            $parMatricule = Eleve::withTrashed()->where('matricule', $donnee['matricule'])->first();
            if ($parMatricule) {
                if ((int) $parMatricule->etablissement_id !== $etablissementId || $parMatricule->trashed() || !$memeIdentite($parMatricule)) {
                    $porteur = (int) $parMatricule->etablissement_id === $etablissementId && !$parMatricule->trashed()
                        ? " à {$parMatricule->prenom} {$parMatricule->nom}"
                        : '';
                    return [null, "Matricule {$donnee['matricule']} déjà attribué{$porteur} : corrigez-le ou laissez la cellule vide."];
                }
                return [$parMatricule, null];
            }
        }

        $eleve = Eleve::where('etablissement_id', $etablissementId)
            ->whereDate('date_naissance', $donnee['date_naissance'])
            ->whereRaw('LOWER(nom) = ?', [mb_strtolower($donnee['nom'])])
            ->whereRaw('LOWER(prenom) = ?', [mb_strtolower($donnee['prenom'])])
            ->first();
        return [$eleve, null];
    }

    private function estInscritSurSession(int $eleveId, int $sessionId): bool
    {
        return Inscription::where('eleve_id', $eleveId)
            ->where('session_scolaire_id', $sessionId)
            ->where('statut', 'active')
            ->exists();
    }

    /**
     * Grilles actives d'inscription / reinscription de l'etablissement, par classe :
     * [classe_id => ['inscription' => GrilleTarifaire, 'reinscription' => GrilleTarifaire]].
     */
    private function grillesInscription(int $etablissementId): array
    {
        $grilles = [];
        GrilleTarifaire::where('etablissement_id', $etablissementId)
            ->where('actif', true)
            ->with('typeFrais')
            ->get()
            ->each(function ($g) use (&$grilles) {
                $type = $this->normaliserTexte($g->typeFrais?->nom);
                if (in_array($type, ['inscription', 'reinscription'], true)) {
                    $grilles[$g->classe_id][$type] = $g;
                }
            });
        return $grilles;
    }

    private function cleDoublon(array $donnee): string
    {
        if (!empty($donnee['matricule'])) {
            return 'matricule:' . mb_strtolower($donnee['matricule']);
        }
        return 'identite:' . mb_strtolower($donnee['nom']) . '|' . mb_strtolower($donnee['prenom']) . '|' . $donnee['date_naissance'];
    }

    /**
     * $nomFeuille : classe retenue quand la ligne n'en donne pas (classeur avec une feuille par
     * classe, nommee « 7e », « 6ème A »...).
     */
    private function analyserLigne(array $ligne, array $ligneBrute, array $mapping, string $nomFeuille, CorrespondanceClasses $correspondance, int $etablissementId, array $grillesInscription): ?array
    {
        if (empty(array_filter($ligne, fn($v) => trim((string) $v) !== ''))) {
            return null;
        }

        $nom = $this->extraireValeur($ligne, $mapping, 'nom');
        $prenom = $this->extraireValeur($ligne, $mapping, 'prenom');
        // Colonne « Nom et prénoms » : utilisee seulement si Nom ou Prenom manque en colonne separee.
        if (($nom === '' || $prenom === '') && isset($mapping['nom_complet'])) {
            [$nom, $prenom] = $this->decouperNomComplet($this->extraireValeur($ligne, $mapping, 'nom_complet'), $mapping['ordre_nom_complet']);
        }

        $classeParIdentifiant = !isset($mapping['classe']) && isset($mapping['classe_id']);
        $donnee = [
            'nom' => $nom,
            'prenom' => $prenom,
            'matricule' => $this->extraireValeur($ligne, $mapping, 'matricule'),
            'classe_nom' => $this->extraireValeur($ligne, $mapping, $classeParIdentifiant ? 'classe_id' : 'classe'),
            'date_naissance_brute' => $this->extraireValeur($ligne, $mapping, 'date_naissance'),
            'lieu_naissance' => $this->extraireValeur($ligne, $mapping, 'lieu_naissance'),
            'pere_nom' => $this->extraireValeur($ligne, $mapping, 'pere_nom'),
            'pere_telephone' => $this->extraireValeur($ligne, $mapping, 'pere_telephone'),
            'mere_nom' => $this->extraireValeur($ligne, $mapping, 'mere_nom'),
            'mere_telephone' => $this->extraireValeur($ligne, $mapping, 'mere_telephone'),
            'tuteur_nom' => $this->extraireValeur($ligne, $mapping, 'tuteur_nom'),
            'tuteur_telephone' => $this->extraireValeur($ligne, $mapping, 'tuteur_telephone'),
            'tuteur_lien' => $this->extraireValeur($ligne, $mapping, 'tuteur_lien'),
            'type_inscription_brut' => $this->extraireValeur($ligne, $mapping, 'type_inscription'),
            'frais_inscription_brut' => $this->extraireValeur($ligne, $mapping, 'frais_inscription'),
            'type_inscription' => null,
            'frais_inscription' => null,
            'scolarite_payee' => null,
            'scolarite_par_tranche' => null,
            'eleve_existant_id' => null,
        ];

        $messageObligatoire = $this->verifierChampsObligatoires($donnee);
        if ($messageObligatoire !== null) {
            $donnee['statut'] = 'erreur';
            $donnee['message'] = $messageObligatoire;
            $donnee['classe_id'] = null;
            $donnee['date_naissance'] = null;
            return $donnee;
        }

        $dateConvertie = $this->normaliserDate($this->extraireBrut($ligneBrute, $mapping, 'date_naissance'));
        if ($dateConvertie === null) {
            $donnee['statut'] = 'erreur';
            $donnee['message'] = 'Date de naissance invalide : ' . $donnee['date_naissance_brute'] . ' (attendu : jour/mois/année, ex. 12/03/2014).';
            $donnee['classe_id'] = null;
            $donnee['date_naissance'] = null;
            return $donnee;
        }
        $donnee['date_naissance'] = $dateConvertie;

        // Classe ecrite librement (« 7e », « Tle SS », « 6eme A »...) : rattachee a la classe de
        // l'annee active quand un seul candidat est possible (App\Services\CorrespondanceClasses).
        if ($donnee['classe_nom'] !== '') {
            [$classe, $raison] = $correspondance->trouver($donnee['classe_nom'], $classeParIdentifiant);
        } else {
            // Pas de classe sur la ligne : celle que designe le nom de la feuille.
            [$classe] = $correspondance->trouver($nomFeuille);
            $raison = "Classe non renseignée, et le nom de la feuille « {$nomFeuille} » n'est pas une classe reconnue : "
                . 'ajoutez une colonne « Classe » ou renommez la feuille (ex. « 7e », « 6ème A »).';
            $donnee['classe_nom'] = $nomFeuille;
        }
        if (!$classe) {
            $donnee['statut'] = 'erreur';
            $donnee['message'] = $raison;
            $donnee['classe_id'] = null;
            return $donnee;
        }
        $donnee['classe_id'] = $classe->id;
        // Nom saisi conserve pour l'affichage « saisi -> retenu » ; classe_nom = classe retenue.
        $donnee['classe_saisie'] = $donnee['classe_nom'];
        $donnee['classe_nom'] = $classe->nom;

        $typeLu = $this->lireTypeInscription($donnee['type_inscription_brut']);
        if ($typeLu === false) {
            $donnee['statut'] = 'erreur';
            $donnee['message'] = "Type d'inscription non reconnu : " . $donnee['type_inscription_brut'] . ' (attendu : Inscription ou Réinscription).';
            return $donnee;
        }

        $message = '';
        [$existant, $erreurMatricule] = $this->trouverEleveExistant($donnee, $etablissementId);
        if ($erreurMatricule !== null) {
            $donnee['statut'] = 'erreur';
            $donnee['message'] = $erreurMatricule;
            return $donnee;
        }
        if ($existant) {
            if ($this->estInscritSurSession($existant->id, $classe->session_scolaire_id)) {
                $donnee['statut'] = 'doublon';
                $donnee['message'] = 'Élève déjà inscrit sur cette session (matricule ou identité déjà présents).';
                return $donnee;
            }
            // Deja connu de LAKOLI (annee precedente) : reinscription automatique.
            $donnee['eleve_existant_id'] = $existant->id;
            $donnee['type_inscription'] = 'reinscription';
            $message = 'Ancien élève déjà enregistré dans LAKOLI : sera réinscrit.';
        } else {
            $donnee['type_inscription'] = $typeLu ?? 'inscription';
        }

        $montant = $this->lireMontantCellule($ligne, $ligneBrute, $mapping['frais_inscription'] ?? null);
        if ($montant === false) {
            $donnee['statut'] = 'erreur';
            $donnee['message'] = "Frais d'inscription illisibles : " . $donnee['frais_inscription_brut'];
            return $donnee;
        }
        if ($montant !== null) {
            $libelleFrais = $donnee['type_inscription'] === 'reinscription' ? 'Réinscription' : 'Inscription';
            $grille = $grillesInscription[$classe->id][$donnee['type_inscription']] ?? null;
            if (! $grille) {
                $donnee['statut'] = 'erreur';
                $donnee['message'] = "Aucune grille « {$libelleFrais} » pour la classe {$classe->nom} : impossible d'enregistrer les frais payés.";
                return $donnee;
            }
            if ($montant > (float) $grille->montant) {
                $donnee['statut'] = 'erreur';
                $donnee['message'] = "Frais payés supérieurs aux frais de {$libelleFrais} de la classe (" . (int) $grille->montant . ' GNF).';
                return $donnee;
            }
            $donnee['frais_inscription'] = $montant;
        }

        // Scolarite deja payee avant LAKOLI : reprise sur les echeances de la grille de la classe.
        $scolarite = $this->lireScolarite($ligne, $ligneBrute, $mapping);
        if (is_string($scolarite)) {
            $donnee['statut'] = 'erreur';
            $donnee['message'] = $scolarite;
            return $donnee;
        }
        if ($scolarite['total'] !== null) {
            $cle = $classe->id . '|' . $donnee['type_inscription'];
            $grille = $this->grillesScolarite[$cle] ??= (new FraisService())->grilleScolarite($classe->id, $classe->session_scolaire_id, $donnee['type_inscription']);
            if (! $grille) {
                $donnee['statut'] = 'erreur';
                $donnee['message'] = "Aucune grille « Scolarité » active pour la classe {$classe->nom} : impossible d'enregistrer la scolarité payée.";
                return $donnee;
            }
            if ($scolarite['total'] > (float) $grille->montant) {
                $donnee['statut'] = 'erreur';
                $donnee['message'] = 'Scolarité payée (' . (int) $scolarite['total'] . ' GNF) supérieure à la scolarité de la classe (' . (int) $grille->montant . ' GNF).';
                return $donnee;
            }
            $echeancesGrille = $grille->echeances->sortBy([['date_limite', 'asc'], ['id', 'asc']])->values();
            // Mensualites : la colonne « Mars » paie l'echeance « Mars » (et non la plus ancienne). Si un
            // mois paye n'a pas d'echeance du meme nom, le total est reparti de la plus ancienne a la
            // plus recente, comme avant.
            if ($scolarite['par_mois']) {
                $indexParMois = [];
                foreach ($echeancesGrille as $i => $ech) {
                    $numero = self::numeroMois((string) $ech->libelle);
                    if ($numero && !isset($indexParMois[$numero])) {
                        $indexParMois[$numero] = $i + 1;
                    }
                }
                if (array_diff_key($scolarite['par_mois'], $indexParMois) === []) {
                    $scolarite['par_tranche'] = [];
                    foreach ($scolarite['par_mois'] as $numero => $montantMois) {
                        $scolarite['par_tranche'][$indexParMois[$numero]] = $montantMois;
                    }
                    ksort($scolarite['par_tranche']);
                }
            }
            foreach ($scolarite['par_tranche'] ?? [] as $numero => $montantTranche) {
                $echeance = $echeancesGrille->get($numero - 1);
                if (! $echeance) {
                    $donnee['statut'] = 'erreur';
                    $donnee['message'] = "Tranche {$numero} payée, mais la scolarité de {$classe->nom} n'a que {$echeancesGrille->count()} échéance(s).";
                    return $donnee;
                }
                if ($montantTranche > (float) $echeance->montant) {
                    $donnee['statut'] = 'erreur';
                    $donnee['message'] = ($scolarite['par_mois'] ? "{$echeance->libelle} : " : "Tranche {$numero} : ") . (int) $montantTranche . ' GNF payés pour ' . (int) $echeance->montant . " GNF dus ({$echeance->libelle}).";
                    return $donnee;
                }
            }
            $donnee['scolarite_payee'] = $scolarite['total'];
            $donnee['scolarite_par_tranche'] = $scolarite['par_tranche'];
        }

        $donnee['statut'] = 'ok';
        $donnee['message'] = $message;
        return $donnee;
    }

    public function analyser(Request $request)
    {
        // upload_max_filesize et post_max_size sont deja fixes a 10M dans php.ini
        // (PHP_INI_PERDIR : ne peuvent pas etre changes ici, evalues avant que ce
        // code ne s'execute). Seul memory_limit est modifiable a l'execution.
        ini_set('memory_limit', '256M');

        $request->validate(['fichier' => 'required|file|mimes:xlsx,xls,csv']);

        $etablissementId = $request->user()->etablissement_id;

        $spreadsheet = IOFactory::load($request->file('fichier')->getPathname());

        // Toutes les feuilles dont on reconnait les en-tetes (une feuille par classe, par exemple) ;
        // les autres (onglet « Classes » du modele, notes...) sont ignorees.
        $feuilles = [];
        foreach ($spreadsheet->getWorksheetIterator() as $feuille) {
            // Texte affiche pour les noms, telephones, classes ; valeurs brutes pour les dates et
            // les montants (voir normaliserDate).
            $affichees = $feuille->toArray();
            $entetes = $this->trouverEntetes($affichees);
            if ($entetes === null) {
                continue;
            }
            [$ligneEntetes, $mapping] = $entetes;
            $feuilles[] = [
                'nom' => $feuille->getTitle(),
                'mapping' => $mapping,
                'affichees' => array_slice($affichees, $ligneEntetes + 1),
                'brutes' => array_slice($feuille->toArray(null, true, false), $ligneEntetes + 1),
            ];
        }
        if ($feuilles === []) {
            return response()->json([
                'message' => 'Colonnes « Nom » et « Prénom » (ou « Nom et prénoms ») introuvables dans les ' . self::LIGNES_RECHERCHE_ENTETES
                    . " premières lignes du fichier : vérifiez la ligne d'en-têtes ou partez du modèle à télécharger.",
            ], 422);
        }

        $classesActives = $this->classesAnneeActive($etablissementId);
        if ($classesActives->isEmpty()) {
            return response()->json([
                'message' => "Aucune classe dans l'année scolaire active : la direction doit d'abord créer les classes (Gestion des Classes).",
            ], 422);
        }

        $correspondance = new CorrespondanceClasses($classesActives);
        $grillesInscription = $this->grillesInscription($etablissementId);

        $resultats = [];
        $clesVuesDansLeFichier = [];
        foreach ($feuilles as $feuille) {
            foreach ($feuille['affichees'] as $i => $ligne) {
                $donnee = $this->analyserLigne($ligne, $feuille['brutes'][$i] ?? [], $feuille['mapping'], $feuille['nom'], $correspondance, $etablissementId, $grillesInscription);
                if ($donnee === null) {
                    continue;
                }

                if ($donnee['statut'] === 'ok') {
                    $cle = $this->cleDoublon($donnee);
                    if (isset($clesVuesDansLeFichier[$cle])) {
                        $donnee['statut'] = 'doublon';
                        $donnee['message'] = 'Cet élève apparaît plusieurs fois dans le fichier importé.';
                    } else {
                        $clesVuesDansLeFichier[$cle] = true;
                    }
                }

                $resultats[] = $donnee;
            }
        }

        $valides = collect($resultats)->where('statut', 'ok');

        return response()->json([
            // Colonnes reconnues dans au moins une feuille.
            'colonnes_detectees' => array_values(array_diff(
                array_unique(array_merge(...array_map(fn ($f) => array_keys($f['mapping']), $feuilles))),
                ['ordre_nom_complet']
            )),
            'feuilles' => array_column($feuilles, 'nom'),
            // Nom et prénoms lus dans une seule colonne puis séparés : à vérifier dans l'aperçu.
            'nom_complet' => collect($feuilles)->contains(fn ($f) => isset($f['mapping']['nom_complet']) && !isset($f['mapping']['nom'], $f['mapping']['prenom'])),
            'lignes' => $resultats,
            'stats' => [
                'total' => count($resultats),
                'valides' => $valides->count(),
                'doublons' => collect($resultats)->where('statut', 'doublon')->count(),
                'erreurs' => collect($resultats)->where('statut', 'erreur')->count(),
                'inscriptions' => $valides->where('type_inscription', 'inscription')->count(),
                'reinscriptions' => $valides->where('type_inscription', 'reinscription')->count(),
                'anciens_lakoli' => $valides->whereNotNull('eleve_existant_id')->count(),
                'frais_payes' => $valides->whereNotNull('frais_inscription')->count(),
                'montant_frais_payes' => $valides->sum('frais_inscription'),
                'scolarites_payees' => $valides->whereNotNull('scolarite_payee')->count(),
                'montant_scolarite_payee' => $valides->sum('scolarite_payee'),
            ],
        ]);
    }

    public function telechargerModele(Request $request)
    {
        $etablissementId = $request->user()->etablissement_id;
        $classes = $this->classesAnneeActive($etablissementId)->pluck('nom')->values();
        $annee = SessionScolaire::where('etablissement_id', $etablissementId)->where('est_active', true)->value('libelle');

        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $feuille = $spreadsheet->getActiveSheet();
        $feuille->setTitle('Eleves');

        $entetes = ['Nom', 'Prenom', 'Matricule', 'Classe', 'Date de naissance', 'Lieu de naissance', 'Nom du pere', 'Telephone du pere', 'Nom de la mere', 'Telephone de la mere', 'Nom du tuteur', 'Telephone du tuteur', "Lien avec l'eleve", "Type d'inscription", "Frais d'inscription payes", 'Scolarite payee'];
        $feuille->fromArray($entetes, null, 'A1');

        // Exemples avec de vraies classes de l'ecole (nom exact, puis forme abregee acceptee).
        $classeA = $classes->first() ?? '7ème Année';
        $classeB = $classes->get(intdiv($classes->count(), 2)) ?? $classeA;
        $exemples = [
            ['Diallo', 'Aminata', '', $classeA, '12/03/2014', 'Conakry', 'Mamadou Diallo', '+224601020304', 'Fatoumata Bah', '+224601020305', '', '', '', 'Inscription', '', ''],
            ['Camara', 'Ibrahima', '', $classeB, '05/09/2013', 'Kindia', 'Sekou Camara', '+224622000111', '', '', '', '', '', 'Réinscription', '', '1500000'],
        ];
        $feuille->fromArray($exemples, null, 'A2');
        foreach (range('A', 'P') as $colonne) {
            $feuille->getColumnDimension($colonne)->setAutoSize(true);
        }

        // Onglet « Classes » : noms exacts de l'annee active, et ecritures abregees reconnues.
        $onglet = $spreadsheet->createSheet();
        $onglet->setTitle('Classes');
        $onglet->setCellValue('A1', 'Classes ' . ($annee ? "de l'année {$annee}" : "de l'école") . ' : à recopier dans la colonne « Classe » de l\'onglet Eleves');
        $onglet->getStyle('A1')->getFont()->setBold(true);
        $onglet->fromArray($classes->map(fn ($nom) => [$nom])->all() ?: [['(aucune classe : la direction doit les créer dans Gestion des Classes)']], null, 'A3');
        $ligne = $classes->count() + 5;
        $onglet->setCellValue("A{$ligne}", 'Écritures abrégées aussi acceptées, par exemple :');
        $onglet->getStyle("A{$ligne}")->getFont()->setBold(true);
        $onglet->fromArray([
            ['7e, 7eme, 7ÈME ANNÉE, 7eA  →  7ème Année'],
            ['1ère, CP1 … CM2  →  1ère Année … 6ème Année'],
            ['CRECHE  →  Crèche ; PS, MS, GS, P Section, M Section, G Section  →  Petite, Moyenne, Grande Section'],
            ['11eSM, 11 SE, 12e SS, 12e Sociales  →  11ème / 12ème Année, série correspondante'],
            ['Tle SM, TSE, Term Sociales  →  Terminale de la série correspondante'],
            ['6e A, 6ème B, 7eA  →  la classe de ce groupe (si l\'école a plusieurs groupes)'],
            ['Une écriture qui correspond à plusieurs classes (« 12e », « Tle S ») est refusée : précisez la série ou le groupe.'],
        ], null, 'A' . ($ligne + 1));

        // Scolarite deja payee : les trois formes reconnues.
        $ligne += 10;
        $onglet->setCellValue("A{$ligne}", 'Scolarité déjà payée (montants en GNF, enregistrés comme « Reprise », hors caisse) :');
        $onglet->getStyle("A{$ligne}")->getFont()->setBold(true);
        $onglet->fromArray([
            ['« Scolarite payee » : total payé, réparti sur les tranches de la plus ancienne à la plus récente'],
            ['ou « Tranche 1 », « Tranche 2 », « Tranche 3 » : montant payé sur chaque tranche'],
            ['ou une colonne par mois (« Octobre », « Novembre »...) : les mois sont additionnés'],
        ], null, 'A' . ($ligne + 1));
        $onglet->getColumnDimension('A')->setAutoSize(true);
        $spreadsheet->setActiveSheetIndex(0);

        $chemin = tempnam(sys_get_temp_dir(), 'lakoli-modele-');
        (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet))->save($chemin);

        return response()->download($chemin, 'modele_import_eleves_lakoli.xlsx')->deleteFileAfterSend(true);
    }

    public function executer(Request $request)
    {
        $etablissementId = $request->user()->etablissement_id;
        $lignes = $request->input('lignes', []);
        $importes = 0;
        $reinscrits = 0;
        $fraisEnregistres = 0;
        $scolaritesReprises = 0;
        $montantScolariteRepris = 0.0;
        $erreurs = [];

        // Calcule le numero de depart une seule fois (MAX existant, pas COUNT) pour
        // eviter tout risque de collision de matricule sur un import de masse : avec
        // COUNT()+1, un trou dans la sequence (eleve supprime, import partiel anterieur)
        // fait retomber sur un matricule deja pris et provoque une violation de
        // contrainte unique qui interrompt tout l'import en cours de route.
        $prefixeMatricule = Numerotation::prefixeMatriculeEleve($etablissementId);
        $dernierNumero = Eleve::withTrashed()
            ->where('matricule', 'like', $prefixeMatricule . '%')
            ->get(['matricule'])
            ->max(fn($e) => (int) substr($e->matricule, strlen($prefixeMatricule))) ?? 0;

        $inscriptionService = new InscriptionService();
        $fraisService = new FraisService();

        $classesActives = $this->classesAnneeActive($etablissementId)->keyBy('id');

        foreach ($lignes as $donnee) {
            // Les lignes viennent du navigateur : la classe doit appartenir a l'annee active.
            $classe = $classesActives->get((int) ($donnee['classe_id'] ?? 0));
            if (!$classe) {
                $erreurs[] = [
                    'nom' => trim(($donnee['nom'] ?? '') . ' ' . ($donnee['prenom'] ?? '')),
                    'message' => "Classe absente de l'année scolaire active : relancez l'analyse du fichier.",
                ];
                continue;
            }

            // Les lignes viennent du navigateur : type et montant sont reverifies ici.
            $type = ($donnee['type_inscription'] ?? null) === 'reinscription' ? 'reinscription' : 'inscription';
            $montantFrais = isset($donnee['frais_inscription']) && is_numeric($donnee['frais_inscription'])
                ? (float) $donnee['frais_inscription']
                : 0.0;
            $montantScolarite = isset($donnee['scolarite_payee']) && is_numeric($donnee['scolarite_payee'])
                ? (float) $donnee['scolarite_payee']
                : 0.0;
            // Tranches : numero >= 1 => montant > 0, dont la somme doit faire le total annonce.
            $parTranche = null;
            if (is_array($donnee['scolarite_par_tranche'] ?? null)) {
                $parTranche = [];
                foreach ($donnee['scolarite_par_tranche'] as $numero => $montant) {
                    if (ctype_digit((string) $numero) && (int) $numero >= 1 && is_numeric($montant) && (float) $montant > 0) {
                        $parTranche[(int) $numero] = (float) $montant;
                    }
                }
                if ($parTranche === [] || abs(array_sum($parTranche) - $montantScolarite) >= 1) {
                    $erreurs[] = [
                        'nom' => trim(($donnee['nom'] ?? '') . ' ' . ($donnee['prenom'] ?? '')),
                        'message' => 'Détail des tranches payées incohérent : relancez l\'analyse du fichier.',
                    ];
                    continue;
                }
            }

            try {
                $resultat = DB::transaction(function () use (
                    $donnee, $classe, $etablissementId, $type, $montantFrais, $montantScolarite, $parTranche, $inscriptionService, $fraisService,
                    $request, $prefixeMatricule, &$dernierNumero
                ) {
                    if (!empty($donnee['eleve_existant_id'])) {
                        // Ancien eleve deja connu de LAKOLI : reinscription dans la nouvelle classe.
                        $eleve = Eleve::where('etablissement_id', $etablissementId)->find($donnee['eleve_existant_id']);
                        if (!$eleve) {
                            throw new \RuntimeException('Élève existant introuvable.');
                        }
                        if ($this->estInscritSurSession($eleve->id, $classe->session_scolaire_id)) {
                            throw new \RuntimeException('Élève déjà inscrit sur cette session.');
                        }
                        $aUnHistorique = Inscription::where('eleve_id', $eleve->id)->exists();
                        $inscription = $aUnHistorique
                            ? $inscriptionService->reinscrire($eleve, $classe)
                            : $inscriptionService->inscrire($eleve, $classe, null, 'reinscription');
                        $nouveau = false;
                    } else {
                        // Import relance (delai depasse, deuxieme onglet) : ne pas creer l'eleve deux fois.
                        [$deja, $erreurMatricule] = $this->trouverEleveExistant($donnee, $etablissementId);
                        if ($erreurMatricule !== null) {
                            throw new \RuntimeException($erreurMatricule);
                        }
                        if ($deja) {
                            throw new \RuntimeException('Élève déjà enregistré dans LAKOLI (import déjà effectué ?) : relancez l\'analyse du fichier.');
                        }

                        if (!empty($donnee['matricule'])) {
                            $matricule = $donnee['matricule'];
                        } else {
                            do {
                                $dernierNumero++;
                                $matricule = $prefixeMatricule . str_pad($dernierNumero, 3, '0', STR_PAD_LEFT);
                            } while (Eleve::withTrashed()->where('matricule', $matricule)->exists());
                        }

                        $eleve = Eleve::create([
                            'etablissement_id' => $etablissementId,
                            'nom' => $donnee['nom'],
                            'prenom' => $donnee['prenom'],
                            'matricule' => $matricule,
                            'date_naissance' => $donnee['date_naissance'],
                            'lieu_naissance' => $donnee['lieu_naissance'] ?? null,
                            'statut_dossier' => 'photo_manquante',
                        ]);

                        // Type lu dans le fichier : un ancien eleve de l'ecole pilote, sans historique
                        // LAKOLI, est enregistre directement en reinscription.
                        $inscription = $inscriptionService->inscrire(
                            $eleve, $classe, null, $type === 'reinscription' ? 'reinscription' : 'nouvelle'
                        );

                        foreach ([['pere', 'pere'], ['mere', 'mere'], ['tuteur', 'tuteur']] as [$prefixe, $lien]) {
                            if (!empty($donnee[$prefixe . '_nom'])) {
                                $eleve->filiations()->create([
                                    'type_lien' => $lien,
                                    'nom_complet' => $donnee[$prefixe . '_nom'],
                                    'telephone' => $donnee[$prefixe . '_telephone'] ?? null,
                                    'lien_avec_eleve' => $prefixe === 'tuteur' ? ($donnee['tuteur_lien'] ?? null) : null,
                                ]);
                            }
                        }
                        $nouveau = true;
                    }

                    // Frais d'inscription / reinscription deja encaisses par l'ecole, avant LAKOLI :
                    // reprise, hors caisse du jour de l'import.
                    $fraisOk = false;
                    if ($montantFrais > 0) {
                        $typeFrais = $fraisService->typeFraisInscription($etablissementId, $type);
                        $grille = $typeFrais ? $fraisService->grilleInscription($inscription, $typeFrais) : null;
                        if (!$grille) {
                            throw new \RuntimeException('Aucune grille de frais de ' . ($type === 'reinscription' ? 'réinscription' : 'inscription') . " pour la classe {$classe->nom}.");
                        }
                        if ($montantFrais > (float) $grille->montant) {
                            throw new \RuntimeException('Frais payés supérieurs aux frais de la classe (' . (int) $grille->montant . ' GNF).');
                        }
                        $fraisOk = $fraisService->encaisserFraisInscription(
                            $inscription, $typeFrais, $grille, $montantFrais, Paiement::MOYEN_REPRISE, $request->user()->id
                        ) !== null;
                    }

                    // Scolarite deja payee : reverifiee ici (les lignes viennent du navigateur).
                    $scolariteReprise = 0.0;
                    if ($montantScolarite > 0) {
                        $scolariteReprise = $fraisService->reprendrePaiementsScolarite(
                            $inscription, $montantScolarite, $parTranche, $request->user()->id
                        );
                    }

                    return ['nouveau' => $nouveau, 'frais' => $fraisOk, 'scolarite' => $scolariteReprise];
                });
            } catch (\Throwable $e) {
                $erreurs[] = [
                    'nom' => trim(($donnee['nom'] ?? '') . ' ' . ($donnee['prenom'] ?? '')),
                    'message' => $e->getMessage(),
                ];
                continue;
            }

            $resultat['nouveau'] ? $importes++ : $reinscrits++;
            if ($resultat['frais']) {
                $fraisEnregistres++;
            }
            if ($resultat['scolarite'] > 0) {
                $scolaritesReprises++;
                $montantScolariteRepris += $resultat['scolarite'];
            }
        }

        return response()->json([
            'importes' => $importes,
            'reinscrits' => $reinscrits,
            'frais_enregistres' => $fraisEnregistres,
            'scolarites_reprises' => $scolaritesReprises,
            'montant_scolarite_repris' => $montantScolariteRepris,
            'erreurs' => $erreurs,
        ]);
    }
}
