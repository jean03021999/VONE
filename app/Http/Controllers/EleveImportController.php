<?php

namespace App\Http\Controllers;

use App\Models\Eleve;
use App\Models\Classe;
use Illuminate\Http\Request;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as DateExcel;
use Carbon\Carbon;

class EleveImportController extends Controller
{
    private array $variantesNom = ['nom', 'nomdefamille', 'nomfamille', 'lastname'];
    private array $variantesPrenom = ['prenom', 'prenoms', 'premierprenom', 'firstname'];

    private array $synonymesParMotif = [
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

    private function verifierClasse(string $nomClasse, $classesNormalisees): ?Classe
    {
        $cle = $this->normaliserTexte($nomClasse);
        return $classesNormalisees->get($cle);
    }

    private function verifierDoublon(array $donnee, int $etablissementId): bool
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
            })->exists();
    }

    private function cleDoublon(array $donnee): string
    {
        if (!empty($donnee['matricule'])) {
            return 'matricule:' . mb_strtolower($donnee['matricule']);
        }
        return 'identite:' . mb_strtolower($donnee['nom']) . '|' . mb_strtolower($donnee['prenom']) . '|' . $donnee['date_naissance'];
    }

    private function analyserLigne(array $ligne, array $mapping, $classesNormalisees, int $etablissementId): ?array
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

        if ($this->verifierDoublon($donnee, $etablissementId)) {
            $donnee['statut'] = 'doublon';
            $donnee['message'] = 'Eleve deja existant (matricule ou identite en double).';
            return $donnee;
        }

        $donnee['statut'] = 'ok';
        $donnee['message'] = '';
        return $donnee;
    }

    public function analyser(Request $request)
    {
        $request->validate(['fichier' => 'required|file|mimes:xlsx,xls,csv']);

        $etablissementId = $request->user()->etablissement_id;

        $spreadsheet = IOFactory::load($request->file('fichier')->getPathname());
        $lignesBrutes = $spreadsheet->getActiveSheet()->toArray();
        $entetes = array_shift($lignesBrutes);
        $mapping = $this->detecterColonnes($entetes ?? []);

        $classesNormalisees = Classe::where('etablissement_id', $etablissementId)
            ->get()
            ->keyBy(fn($c) => $this->normaliserTexte($c->nom));

        $resultats = [];
        $clesVuesDansLeFichier = [];
        foreach ($lignesBrutes as $ligne) {
            $donnee = $this->analyserLigne($ligne, $mapping, $classesNormalisees, $etablissementId);
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

        return response()->json([
            'colonnes_detectees' => array_keys($mapping),
            'lignes' => $resultats,
            'stats' => [
                'total' => count($resultats),
                'valides' => collect($resultats)->where('statut', 'ok')->count(),
                'doublons' => collect($resultats)->where('statut', 'doublon')->count(),
                'erreurs' => collect($resultats)->where('statut', 'erreur')->count(),
            ],
        ]);
    }

    public function executer(Request $request)
    {
        $etablissementId = $request->user()->etablissement_id;
        $lignes = $request->input('lignes', []);
        $importes = 0;

        foreach ($lignes as $donnee) {
            $classe = Classe::find($donnee['classe_id'] ?? null);
            if (!$classe) {
                continue;
            }

            $matricule = !empty($donnee['matricule'])
                ? $donnee['matricule']
                : 'LAK-' . date('Y') . '-' . str_pad(Eleve::where('etablissement_id', $etablissementId)->count() + 1, 3, '0', STR_PAD_LEFT);

            $eleve = Eleve::create([
                'etablissement_id' => $etablissementId,
                'classe_id' => $classe->id,
                'session_scolaire_id' => $classe->session_scolaire_id,
                'nom' => $donnee['nom'],
                'prenom' => $donnee['prenom'],
                'matricule' => $matricule,
                'date_naissance' => $donnee['date_naissance'],
                'lieu_naissance' => $donnee['lieu_naissance'] ?? null,
                'statut_dossier' => 'photo_manquante',
            ]);

            if (!empty($donnee['pere_nom'])) {
                $eleve->filiations()->create([
                    'type_lien' => 'pere',
                    'nom_complet' => $donnee['pere_nom'],
                    'telephone' => $donnee['pere_telephone'] ?? null,
                ]);
            }
            if (!empty($donnee['mere_nom'])) {
                $eleve->filiations()->create([
                    'type_lien' => 'mere',
                    'nom_complet' => $donnee['mere_nom'],
                    'telephone' => $donnee['mere_telephone'] ?? null,
                ]);
            }

            $importes++;
        }

        return response()->json(['importes' => $importes]);
    }
}

