<?php

namespace App\Services;

use App\Models\Eleve;
use App\Models\Enseignant;
use App\Models\Etablissement;
use App\Models\Paiement;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Matricules et references prefixes par le sigle de l'ecole (Parametres > Etablissement) :
 *   eleve      GSSE-2026-001          enseignant GSSE-ENS-2026-001
 *   versement  GSSE-PAY-2026-000001   (INS : frais d'inscription, REP : reprise a l'import)
 *   depense    GSSE-DEP-2026-37       salaire    GSSE-SAL-2026-12
 * Les numeros d'eleve, d'enseignant et de versement se suivent par sigle et par annee ; ceux des
 * depenses et salaires reprennent leur identifiant. Les matricules sont uniques pour toute la base
 * (deux ecoles de meme sigle se partagent la suite), les eleves supprimes comptent.
 */
class Numerotation
{
    /** Mots ignores pour les initiales : « Ecole de la Reussite » -> ER. */
    private const MOTS_VIDES = ['de', 'du', 'des', 'la', 'le', 'les', 'l', 'd', 'et', 'a', 'au', 'aux', 'en'];

    /** Sigle tire du nom : initiales des mots significatifs, 8 caracteres au plus. */
    public static function initiales(?string $nom): string
    {
        $mots = preg_split('/[^A-Za-z0-9]+/', Str::ascii((string) $nom), -1, PREG_SPLIT_NO_EMPTY);
        $initiales = collect($mots)
            ->reject(fn ($mot) => in_array(strtolower($mot), self::MOTS_VIDES, true))
            ->map(fn ($mot) => strtoupper($mot[0]))
            ->implode('');

        return substr($initiales, 0, 8) ?: 'ECOLE';
    }

    /** Sigle de l'ecole : celui saisi dans les parametres, sinon les initiales de son nom. */
    public static function sigle(int $etablissementId): string
    {
        $etablissement = Etablissement::withTrashed()->find($etablissementId, ['id', 'nom', 'sigle']);

        return $etablissement?->sigle ?: self::initiales($etablissement?->nom);
    }

    public static function matriculeEleve(int $etablissementId): string
    {
        $prefixe = self::prefixeMatriculeEleve($etablissementId);
        $numero = self::dernierNumero(Eleve::class, 'matricule', $prefixe);
        do {
            $matricule = $prefixe . str_pad((string) ++$numero, 3, '0', STR_PAD_LEFT);
        } while (Eleve::withTrashed()->where('matricule', $matricule)->exists());

        return $matricule;
    }

    /** « GSSE-2026- » : pour l'import, qui numerote lui-meme une serie d'eleves. */
    public static function prefixeMatriculeEleve(int $etablissementId): string
    {
        return self::sigle($etablissementId) . '-' . date('Y') . '-';
    }

    public static function matriculeEnseignant(int $etablissementId): string
    {
        $prefixe = self::sigle($etablissementId) . '-ENS-' . date('Y') . '-';
        $numero = self::dernierNumero(Enseignant::class, 'matricule', $prefixe);
        do {
            $matricule = $prefixe . str_pad((string) ++$numero, 3, '0', STR_PAD_LEFT);
        } while (Enseignant::withTrashed()->where('matricule', $matricule)->exists());

        return $matricule;
    }

    /**
     * Reference d'un versement (plusieurs paiements, un par echeance, la partagent) : $type PAY
     * (caisse), INS (frais d'inscription) ou REP (reprise de l'existant). Verrou PostgreSQL jusqu'a
     * la fin de la transaction en cours : deux caissiers simultanes n'obtiennent pas le meme numero.
     */
    public static function referenceVersement(int $etablissementId, string $type): string
    {
        $prefixe = self::sigle($etablissementId) . '-' . $type . '-' . date('Y') . '-';
        if (DB::getDriverName() === 'pgsql' && DB::transactionLevel() > 0) {
            DB::select('SELECT pg_advisory_xact_lock(?)', [crc32($prefixe)]);
        }
        $numero = self::dernierNumero(Paiement::class, 'reference', $prefixe);

        return $prefixe . str_pad((string) ($numero + 1), 6, '0', STR_PAD_LEFT);
    }

    /** Depense ou salaire : « GSSE-DEP-2026-37 ». */
    public static function referenceOperation(int $etablissementId, string $type, int|string $annee, int $id): string
    {
        return self::sigle($etablissementId) . "-{$type}-{$annee}-{$id}";
    }

    /** Plus grand numero deja attribue apres $prefixe dans $colonne (0 si aucun). */
    private static function dernierNumero(string $modele, string $colonne, string $prefixe): int
    {
        $requete = in_array(\Illuminate\Database\Eloquent\SoftDeletes::class, class_uses_recursive($modele), true)
            ? $modele::withTrashed()
            : $modele::query();

        // Le sigle ne contient que lettres et chiffres : pas de % ni de _ a echapper.
        return (int) $requete->where($colonne, 'like', $prefixe . '%')
            ->distinct()
            ->pluck($colonne)
            ->map(fn ($valeur) => substr($valeur, strlen($prefixe)))
            ->filter(fn ($suite) => ctype_digit($suite))
            ->map(fn ($suite) => (int) $suite)
            ->max();
    }
}
