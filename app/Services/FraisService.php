<?php

namespace App\Services;

use App\Models\FraisEleve;
use App\Models\GrilleTarifaire;
use App\Models\Inscription;
use App\Models\Paiement;
use App\Models\TypeFrais;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class FraisService
{
    /**
     * Les frais d'inscription / reinscription se gerent eleve par eleve (appliquerInscription) :
     * ils ne doivent jamais etre appliques automatiquement par une grille.
     */
    public static function estFraisParEleve(GrilleTarifaire $grille): bool
    {
        $nom = Str::of($grille->typeFrais?->nom ?? '')->ascii()->lower()->toString();

        return in_array($nom, ['inscription', 'reinscription'], true);
    }

    /**
     * Cree le FraisEleve et ses echeances d'apres la grille, sauf si l'eleve a deja un frais de ce
     * type sur cette session. Retourne true si un frais a ete cree.
     */
    public function creerFraisDepuisGrille(int $eleveId, GrilleTarifaire $grille, ?int $inscriptionId = null): bool
    {
        $grille->loadMissing('echeances');

        $existe = FraisEleve::where('eleve_id', $eleveId)
            ->where('type_frais_id', $grille->type_frais_id)
            ->where('session_scolaire_id', $grille->session_scolaire_id)
            ->exists();
        if ($existe) {
            return false;
        }

        $fraisEleve = FraisEleve::create([
            'eleve_id' => $eleveId,
            'type_frais_id' => $grille->type_frais_id,
            'session_scolaire_id' => $grille->session_scolaire_id,
            'montant_total' => $grille->montant,
            'montant_original' => $grille->montant,
            'inscription_id' => $inscriptionId,
            'grille_tarifaire_id' => $grille->id,
        ]);

        foreach ($grille->echeances as $ech) {
            $fraisEleve->echeances()->create([
                'libelle' => $ech->libelle,
                'montant' => $ech->montant,
                'date_limite' => $ech->date_limite,
            ]);
        }

        return true;
    }

    /**
     * Applique a un eleve qui vient d'etre inscrit les grilles actives de sa classe pour la session
     * (scolarite, etc. — hors inscription/reinscription). La grille propre a son public (nouveau ou
     * ancien eleve) passe avant la grille "tous" du meme type. Retourne le nombre de frais crees.
     */
    public function appliquerGrillesAInscription(Inscription $inscription): int
    {
        $public = $inscription->type_inscription === 'reinscription' ? 'ancien' : 'nouveau';

        $grilles = GrilleTarifaire::where('classe_id', $inscription->classe_id)
            ->where('session_scolaire_id', $inscription->session_scolaire_id)
            ->where('actif', true)
            ->whereIn('applicable_a', [$public, 'tous'])
            ->with('echeances', 'typeFrais')
            ->get()
            ->sortBy(fn($g) => $g->applicable_a === 'tous' ? 1 : 0);

        $crees = 0;
        foreach ($grilles as $grille) {
            if (self::estFraisParEleve($grille)) {
                continue;
            }
            if ($this->creerFraisDepuisGrille($inscription->eleve_id, $grille, $inscription->id)) {
                $crees++;
            }
        }

        return $crees;
    }

    /**
     * Apres un changement (ou une correction) de classe : les frais de l'eleve qui ne viennent pas
     * de la grille de sa nouvelle classe (y compris les anciens frais sans lien de grille) sont
     * remplaces par ceux de cette grille. Par prudence, un frais est conserve et signale s'il est
     * deja paye (meme en partie, ou paiement annule), personnalise (remise) ou si la nouvelle classe
     * n'a pas de grille active de ce type. Retourne ['remplaces', 'retires', 'conserves' => [libelles]].
     */
    public function realignerFraisSurClasse(Inscription $inscription): array
    {
        $public = $inscription->type_inscription === 'reinscription' ? 'ancien' : 'nouveau';
        $grillesClasse = GrilleTarifaire::where('classe_id', $inscription->classe_id)
            ->where('session_scolaire_id', $inscription->session_scolaire_id)
            ->where('actif', true)
            ->whereIn('applicable_a', [$public, 'tous'])
            ->with('typeFrais')
            ->get();
        $idsGrillesClasse = $grillesClasse->pluck('id')->all();
        // Types compares par nom normalise : « Scolarité » et « Scolarite » sont le meme frais.
        $normaliser = fn ($nom) => Str::of($nom ?? '')->ascii()->lower()->trim()->toString();
        $typesCouverts = $grillesClasse->map(fn ($g) => $normaliser($g->typeFrais?->nom))->unique()->all();

        $frais = FraisEleve::where('eleve_id', $inscription->eleve_id)
            ->where('session_scolaire_id', $inscription->session_scolaire_id)
            ->with('typeFrais')
            ->get();

        $retires = 0;
        $conserves = [];
        foreach ($frais as $f) {
            if (in_array($f->grille_tarifaire_id, $idsGrillesClasse, true)) {
                continue; // deja aligne sur la nouvelle classe
            }
            $nomType = $normaliser($f->typeFrais?->nom);
            if (in_array($nomType, ['inscription', 'reinscription'], true)) {
                continue; // payes une fois, independants de la classe
            }
            $libelle = $f->typeFrais?->nom ?? 'Frais';
            if (! in_array($nomType, $typesCouverts, true)) {
                $conserves[] = "{$libelle} (pas de grille pour la nouvelle classe)";
                continue;
            }
            if (Paiement::whereIn('echeance_eleve_id', $f->echeances()->pluck('id'))->exists()) {
                $conserves[] = "{$libelle} (déjà payé en partie)";
                continue;
            }
            $personnalise = ($f->montant_original !== null && (float) $f->montant_total !== (float) $f->montant_original)
                || filled($f->motif_personnalisation);
            if ($personnalise) {
                $conserves[] = "{$libelle} (montant personnalisé)";
                continue;
            }
            $f->echeances()->delete();
            $f->delete();
            $retires++;
        }

        $crees = $retires > 0 ? $this->appliquerGrillesAInscription($inscription) : 0;

        return ['remplaces' => $crees, 'retires' => $retires, 'conserves' => $conserves];
    }

    /**
     * Type de frais "Inscription" ou "Reinscription" de l'etablissement (nom compare sans accent ni
     * casse), selon le type d'inscription ('nouvelle' / 'inscription' ou 'reinscription').
     */
    public function typeFraisInscription(int $etablissementId, string $typeInscription): ?TypeFrais
    {
        $cherche = $typeInscription === 'reinscription' ? 'reinscription' : 'inscription';

        return TypeFrais::where('etablissement_id', $etablissementId)
            ->get()
            ->first(fn ($t) => Str::of($t->nom)->ascii()->lower()->toString() === $cherche);
    }

    /** Grille active de ce type de frais pour la classe et la session de l'inscription. */
    public function grilleInscription(Inscription $inscription, TypeFrais $typeFrais): ?GrilleTarifaire
    {
        return GrilleTarifaire::where('etablissement_id', $typeFrais->etablissement_id)
            ->where('classe_id', $inscription->classe_id)
            ->where('session_scolaire_id', $inscription->session_scolaire_id)
            ->where('type_frais_id', $typeFrais->id)
            ->where('actif', true)
            ->first();
    }

    /**
     * Enregistre les frais d'inscription / reinscription d'un eleve et leur paiement (echeance
     * unique du montant de la grille, paiement de $montant). Utilise par la caisse
     * (FraisController::appliquerInscription) et par l'import Excel. Retourne null si ces frais
     * ont deja ete appliques a l'eleve sur la session.
     */
    public function encaisserFraisInscription(
        Inscription $inscription,
        TypeFrais $typeFrais,
        GrilleTarifaire $grille,
        float $montant,
        string $moyenPaiement,
        ?int $caissierId
    ): ?array {
        return DB::transaction(function () use ($inscription, $typeFrais, $grille, $montant, $moyenPaiement, $caissierId) {
            $frais = FraisEleve::firstOrCreate(
                [
                    'eleve_id' => $inscription->eleve_id,
                    'type_frais_id' => $typeFrais->id,
                    'session_scolaire_id' => $inscription->session_scolaire_id,
                ],
                [
                    'montant_total' => $grille->montant,
                    'montant_original' => $grille->montant,
                    'inscription_id' => $inscription->id,
                    'grille_tarifaire_id' => $grille->id,
                ]
            );

            if (! $frais->wasRecentlyCreated) {
                return null;
            }

            $echeance = $frais->echeances()->create([
                'libelle' => $typeFrais->nom,
                'montant' => $grille->montant,
                'date_limite' => today()->toDateString(),
            ]);

            $paiement = Paiement::create([
                'eleve_id' => $inscription->eleve_id,
                'echeance_eleve_id' => $echeance->id,
                'libelle' => $echeance->libelle,
                'montant' => $montant,
                'moyen_paiement' => $moyenPaiement,
                'date_paiement' => today()->toDateString(),
                'reference' => 'INS-' . now()->year . '-' . $inscription->eleve_id . '-' . now()->timestamp,
                'caissier_id' => $caissierId,
            ]);

            return compact('frais', 'paiement');
        });
    }
}
