<?php

namespace App\Http\Controllers;

use App\Models\Eleve;
use App\Models\Classe;
use App\Models\GrilleTarifaire;
use App\Models\Inscription;
use App\Services\FraisService;
use App\Services\InscriptionService;
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
        'matricule' => 'matricule',
        'datedenaissance' => 'date_naissance',
        'datenaissance' => 'date_naissance',
        'ddn' => 'date_naissance',
        'lieudenaissance' => 'lieu_naissance',
        'lieunaissance' => 'lieu_naissance',
        'nomdupere' => 'pere_nom',
        'perenom' => 'pere_nom',
        'nompere' => 'pere_nom',
        'pere' => 'pere_nom',
        'telephonedupere' => 'pere_telephone',
        'peretelephone' => 'pere_telephone',
        'telephonepere' => 'pere_telephone',
        'telpere' => 'pere_telephone',
        'nomdelamere' => 'mere_nom',
        'merenom' => 'mere_nom',
        'nommere' => 'mere_nom',
        'mere' => 'mere_nom',
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
     * Convertit une date, quel que soit son format d'origine, vers Y-m-d.
     * Retourne null si la date est invalide plutot que de lever une exception.
     */
    private function normaliserDate($valeur): ?string
    {
        if ($valeur === null || $valeur === '') {
            return null;
        }

        if (is_numeric($valeur)) {
            try {
                $date = DateExcel::excelToDateTimeObject((float) $valeur);
                return $date->format('Y-m-d');
            } catch (\Throwable $e) {
                return null;
            }
        }

        $valeur = trim((string) $valeur);
        $formats = ['Y-m-d', 'd/m/Y', 'd-m-Y', 'd.m.Y', 'Y/m/d'];

        foreach ($formats as $format) {
            try {
                $date = Carbon::createFromFormat($format, $valeur);
                if ($date !== false) {
                    return $date->format('Y-m-d');
                }
            } catch (\Throwable $e) {
                continue;
            }
        }

        return null;
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

            if (in_array($normalise, $this->variantesNom, true) && !isset($mapping['nom'])) {
                $mapping['nom'] = $index;
                continue;
            }
            if (in_array($normalise, $this->variantesPrenom, true) && !isset($mapping['prenom'])) {
                $mapping['prenom'] = $index;
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

    private function extraireValeur(array $ligne, array $mapping, string $champ): string
    {
        if (!isset($mapping[$champ])) {
            return '';
        }
        return trim((string) ($ligne[$mapping[$champ]] ?? ''));
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
        if ($v === '' || $v === '0') {
            return null;
        }
        $v = str_replace([',', '.'], '', $v);
        return ctype_digit($v) ? (float) $v : false;
    }

    private function verifierChampsObligatoires(array $donnee): ?string
    {
        if ($donnee['nom'] === '') {
            return 'Nom manquant.';
        }
        if ($donnee['prenom'] === '') {
            return 'Prenom manquant.';
        }
        if ($donnee['date_naissance_brute'] === '') {
            return 'Date de naissance manquante.';
        }
        return null;
    }

    /**
     * Le fichier peut contenir un ID de classe (ex: 3, 7, 11) ou son nom.
     * On cherche d'abord par ID, puis par nom normalise en fallback.
     * $classesNormalisees est deja limitee a l'etablissement et chargee une seule
     * fois : pas de requete par ligne.
     */
    private function verifierClasse(string $valeur, $classesNormalisees): ?Classe
    {
        if (ctype_digit($valeur)) {
            $parId = $classesNormalisees->firstWhere('id', (int) $valeur);
            if ($parId) {
                return $parId;
            }
        }

        return $classesNormalisees->get($this->normaliserTexte($valeur));
    }

    private function trouverEleveExistant(array $donnee, int $etablissementId): ?Eleve
    {
        return Eleve::where('etablissement_id', $etablissementId)
            ->where(function ($q) use ($donnee) {
                if (!empty($donnee['matricule'])) {
                    $q->where('matricule', $donnee['matricule']);
                }
                $q->orWhere(function ($q2) use ($donnee) {
                    $q2->where('nom', $donnee['nom'])
                        ->where('prenom', $donnee['prenom'])
                        ->where('date_naissance', $donnee['date_naissance']);
                });
            })->first();
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

    private function analyserLigne(array $ligne, array $mapping, $classesNormalisees, int $etablissementId, array $grillesInscription): ?array
    {
        if (empty(array_filter($ligne, fn($v) => trim((string) $v) !== ''))) {
            return null;
        }

        $donnee = [
            'nom' => $this->extraireValeur($ligne, $mapping, 'nom'),
            'prenom' => $this->extraireValeur($ligne, $mapping, 'prenom'),
            'matricule' => $this->extraireValeur($ligne, $mapping, 'matricule'),
            'classe_nom' => $this->extraireValeur($ligne, $mapping, 'classe'),
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

        $dateConvertie = $this->normaliserDate($donnee['date_naissance_brute']);
        if ($dateConvertie === null) {
            $donnee['statut'] = 'erreur';
            $donnee['message'] = 'Date de naissance invalide.';
            $donnee['classe_id'] = null;
            $donnee['date_naissance'] = null;
            return $donnee;
        }
        $donnee['date_naissance'] = $dateConvertie;

        $classe = $this->verifierClasse($donnee['classe_nom'], $classesNormalisees);
        if (!$classe) {
            $donnee['statut'] = 'erreur';
            $donnee['message'] = 'Classe introuvable : ' . $donnee['classe_nom'];
            $donnee['classe_id'] = null;
            return $donnee;
        }
        $donnee['classe_id'] = $classe->id;

        $typeLu = $this->lireTypeInscription($donnee['type_inscription_brut']);
        if ($typeLu === false) {
            $donnee['statut'] = 'erreur';
            $donnee['message'] = "Type d'inscription non reconnu : " . $donnee['type_inscription_brut'] . ' (attendu : Inscription ou Réinscription).';
            return $donnee;
        }

        $message = '';
        $existant = $this->trouverEleveExistant($donnee, $etablissementId);
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

        $montant = $this->lireMontant($donnee['frais_inscription_brut']);
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
        $lignesBrutes = $spreadsheet->getActiveSheet()->toArray();
        $entetes = array_shift($lignesBrutes);
        $mapping = $this->detecterColonnes($entetes ?? []);

        $classesNormalisees = Classe::where('etablissement_id', $etablissementId)
            ->get()
            ->keyBy(fn($c) => $this->normaliserTexte($c->nom));

        $grillesInscription = $this->grillesInscription($etablissementId);

        $resultats = [];
        $clesVuesDansLeFichier = [];
        foreach ($lignesBrutes as $ligne) {
            $donnee = $this->analyserLigne($ligne, $mapping, $classesNormalisees, $etablissementId, $grillesInscription);
            if ($donnee === null) {
                continue;
            }

            if ($donnee['statut'] === 'ok') {
                $cle = $this->cleDoublon($donnee);
                if (isset($clesVuesDansLeFichier[$cle])) {
                    $donnee['statut'] = 'doublon';
                    $donnee['message'] = 'Cet eleve apparait plusieurs fois dans le fichier importe.';
                } else {
                    $clesVuesDansLeFichier[$cle] = true;
                }
            }

            $resultats[] = $donnee;
        }

        $valides = collect($resultats)->where('statut', 'ok');

        return response()->json([
            'colonnes_detectees' => array_keys($mapping),
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
            ],
        ]);
    }

    public function telechargerModele()
    {
        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $feuille = $spreadsheet->getActiveSheet();

        $entetes = ['Nom', 'Prenom', 'Matricule', 'Classe', 'Date de naissance', 'Lieu de naissance', 'Nom du pere', 'Telephone du pere', 'Nom de la mere', 'Telephone de la mere', 'Nom du tuteur', 'Telephone du tuteur', "Lien avec l'eleve", "Type d'inscription", "Frais d'inscription payes"];
        $feuille->fromArray($entetes, null, 'A1');

        $exemples = [
            ['Diallo', 'Aminata', 'LAK-2026-001', '6eme A', '12/03/2014', 'Conakry', 'Mamadou Diallo', '+224601020304', 'Fatoumata Bah', '+224601020305', '', '', '', 'Inscription', '200000'],
            ['Camara', 'Ibrahima', '', '6eme A', '05/09/2013', 'Kindia', 'Sekou Camara', '+224622000111', '', '', '', '', '', 'Réinscription', ''],
        ];
        $feuille->fromArray($exemples, null, 'A2');

        foreach (range('A', 'O') as $colonne) {
            $feuille->getColumnDimension($colonne)->setAutoSize(true);
        }

        $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);

        $nomFichier = 'modele_import_eleves_lakoli.xlsx';
        $chemin = storage_path('app/' . $nomFichier);
        $writer->save($chemin);

        return response()->download($chemin)->deleteFileAfterSend(true);
    }

    public function executer(Request $request)
    {
        $etablissementId = $request->user()->etablissement_id;
        $lignes = $request->input('lignes', []);
        $importes = 0;
        $reinscrits = 0;
        $fraisEnregistres = 0;
        $erreurs = [];

        // Calcule le numero de depart une seule fois (MAX existant, pas COUNT) pour
        // eviter tout risque de collision de matricule sur un import de masse : avec
        // COUNT()+1, un trou dans la sequence (eleve supprime, import partiel anterieur)
        // fait retomber sur un matricule deja pris et provoque une violation de
        // contrainte unique qui interrompt tout l'import en cours de route.
        $prefixeMatricule = 'LAK-' . date('Y') . '-';
        $dernierNumero = Eleve::withTrashed()
            ->where('matricule', 'like', $prefixeMatricule . '%')
            ->get(['matricule'])
            ->max(fn($e) => (int) substr($e->matricule, strlen($prefixeMatricule))) ?? 0;

        $inscriptionService = new InscriptionService();
        $fraisService = new FraisService();

        foreach ($lignes as $donnee) {
            $classe = Classe::where('id', $donnee['classe_id'] ?? null)
                ->where('etablissement_id', $etablissementId)
                ->first();
            if (!$classe) {
                continue;
            }

            // Les lignes viennent du navigateur : type et montant sont reverifies ici.
            $type = ($donnee['type_inscription'] ?? null) === 'reinscription' ? 'reinscription' : 'inscription';
            $montantFrais = isset($donnee['frais_inscription']) && is_numeric($donnee['frais_inscription'])
                ? (float) $donnee['frais_inscription']
                : 0.0;

            try {
                $resultat = DB::transaction(function () use (
                    $donnee, $classe, $etablissementId, $type, $montantFrais, $inscriptionService, $fraisService,
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

                    // Frais d'inscription / reinscription deja encaisses par l'ecole.
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
                            $inscription, $typeFrais, $grille, $montantFrais, 'especes', $request->user()->id
                        ) !== null;
                    }

                    return ['nouveau' => $nouveau, 'frais' => $fraisOk];
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
        }

        return response()->json([
            'importes' => $importes,
            'reinscrits' => $reinscrits,
            'frais_enregistres' => $fraisEnregistres,
            'erreurs' => $erreurs,
        ]);
    }
}
