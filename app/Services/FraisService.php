<?php

namespace App\Services;

use App\Models\FraisEleve;
use App\Models\GrilleTarifaire;
use App\Models\Inscription;
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
}
