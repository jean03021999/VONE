<?php

namespace App\Console\Commands;

use App\Models\FraisEleve;
use App\Models\GrilleTarifaire;
use App\Models\Inscription;
use App\Services\FraisService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Les grilles importees par le seeder n'ont pas d'echeances (trimestres) : seuls les frais des eleves
 * deja presents en ont. Un eleve ajoute ensuite recevait donc un frais de scolarite sans aucune
 * echeance, impossible a payer. Cette commande :
 *  1. recopie sur chaque grille sans echeance le decoupage commun a tous ses eleves (s'il est unanime) ;
 *  2. cree les echeances des frais eleves qui n'en ont aucune, d'apres leur grille ;
 *  3. applique les grilles aux eleves inscrits qui n'ont pas encore leurs frais.
 */
class ReparerEcheancesFrais extends Command
{
    protected $signature = 'lakoli:reparer-echeances {--dry-run : Simule sans écrire en base}';

    protected $description = 'Complete les echeances manquantes des grilles tarifaires et des frais eleves.';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $this->info($dryRun ? 'Simulation (--dry-run) : aucune ecriture en base.' : 'Execution reelle.');

        DB::beginTransaction();
        try {
            $grilles = $this->completerGrilles();
            $frais = $this->completerFraisSansEcheance();
            $appliques = $this->appliquerGrillesManquantes();

            $this->newLine();
            $this->info("Grilles completees : {$grilles}");
            $this->info("Frais eleves completes : {$frais}");
            $this->info("Frais crees pour des eleves inscrits : {$appliques}");

            $dryRun ? DB::rollBack() : DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }

        return self::SUCCESS;
    }

    private function completerGrilles(): int
    {
        $completees = 0;
        $grilles = GrilleTarifaire::doesntHave('echeances')->with('typeFrais')->get();

        foreach ($grilles as $grille) {
            if (FraisService::estFraisParEleve($grille)) {
                continue;
            }

            // Decoupage de chaque frais eleve issu de cette grille (ceux qui ont des echeances).
            $decoupages = FraisEleve::where('grille_tarifaire_id', $grille->id)
                ->has('echeances')
                ->with('echeances')
                ->get()
                ->map(fn ($f) => $f->echeances
                    ->sortBy('date_limite')
                    ->map(fn ($e) => ['libelle' => $e->libelle, 'montant' => (float) $e->montant, 'date_limite' => (string) $e->date_limite])
                    ->values()
                    ->all())
                ->unique(fn ($d) => json_encode($d));

            if ($decoupages->count() !== 1) {
                $this->warn("Grille {$grille->id} (classe {$grille->classe_id}) : "
                    . ($decoupages->isEmpty() ? 'aucun eleve de reference' : 'decoupages differents entre eleves')
                    . ', a completer a la main.');
                continue;
            }

            $echeances = $decoupages->first();
            if (abs(array_sum(array_column($echeances, 'montant')) - (float) $grille->montant) > 0.01) {
                $this->warn("Grille {$grille->id} : la somme des echeances ne correspond pas au montant de la grille, ignoree.");
                continue;
            }

            foreach ($echeances as $ech) {
                $grille->echeances()->create($ech);
            }
            $this->line("Grille {$grille->id} (classe {$grille->classe_id}) : " . count($echeances) . ' echeance(s) ajoutee(s).');
            $completees++;
        }

        return $completees;
    }

    private function completerFraisSansEcheance(): int
    {
        $completes = 0;
        $frais = FraisEleve::doesntHave('echeances')
            ->whereNotNull('grille_tarifaire_id')
            ->with('grilleTarifaire.echeances')
            ->get();

        foreach ($frais as $f) {
            $echeancesGrille = $f->grilleTarifaire?->echeances ?? collect();
            if ($echeancesGrille->isEmpty() || (float) $f->montant_total !== (float) $f->grilleTarifaire->montant) {
                $this->warn("Frais eleve {$f->id} (eleve {$f->eleve_id}) : pas de modele d'echeances utilisable, a completer a la main.");
                continue;
            }
            foreach ($echeancesGrille as $ech) {
                $f->echeances()->create([
                    'libelle' => $ech->libelle,
                    'montant' => $ech->montant,
                    'date_limite' => $ech->date_limite,
                ]);
            }
            $this->line("Frais eleve {$f->id} (eleve {$f->eleve_id}) : {$echeancesGrille->count()} echeance(s) ajoutee(s).");
            $completes++;
        }

        return $completes;
    }

    private function appliquerGrillesManquantes(): int
    {
        $service = new FraisService();
        $crees = 0;
        foreach (Inscription::where('statut', 'active')->get() as $inscription) {
            $n = $service->appliquerGrillesAInscription($inscription);
            if ($n > 0) {
                $this->line("Eleve {$inscription->eleve_id} : {$n} frais cree(s).");
            }
            $crees += $n;
        }

        return $crees;
    }
}
