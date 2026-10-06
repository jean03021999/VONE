<?php

namespace App\Console\Commands;

use App\Models\Classe;
use App\Models\Etablissement;
use App\Models\SessionScolaire;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Complete une installation existante (mise a jour d'une installation faite avant que l'installateur
 * ne cree les classes) : annee scolaire active si elle manque, puis classes courantes des cycles de
 * l'ecole si l'annee active n'a AUCUNE classe. Une annee qui a deja des classes n'est jamais modifiee.
 */
class ClassesCourantes extends Command
{
    protected $signature = 'lakoli:classes-courantes
        {--etablissement= : Identifiant de l\'etablissement (par defaut : chacun)}
        {--cycles= : Cycles, separes par des virgules (par defaut : ceux de la fiche, sinon tous)}
        {--annee= : Annee de debut si l\'annee scolaire active doit etre creee}
        {--oui : Ne pas demander de confirmation}';

    protected $description = "Crée l'année scolaire active et les classes courantes d'un établissement qui n'en a pas.";

    public function handle(): int
    {
        $etablissements = $this->option('etablissement')
            ? Etablissement::whereKey($this->option('etablissement'))->get()
            : Etablissement::all();

        foreach ($etablissements as $etablissement) {
            $this->info("Établissement : {$etablissement->nom}");

            $session = SessionScolaire::where('etablissement_id', $etablissement->id)->where('est_active', true)->first();
            $nbClasses = $session ? Classe::where('session_scolaire_id', $session->id)->count() : 0;
            if ($session && $nbClasses > 0) {
                $this->line("  Année {$session->libelle} : {$nbClasses} classe(s) déjà présente(s), rien à faire.");
                continue;
            }

            $cycles = $this->option('cycles')
                ? array_map('trim', explode(',', $this->option('cycles')))
                : ($etablissement->cycles ?: array_keys(InstallerEtablissement::CLASSES));
            $cycles = array_values(array_intersect(array_keys(InstallerEtablissement::CLASSES), $cycles));
            $noms = collect($cycles)->flatMap(fn ($c) => InstallerEtablissement::CLASSES[$c]);

            $anneeParDefaut = now()->month >= 8 ? now()->year : now()->year - 1;
            $annee = $session ? null : (int) ($this->option('annee') ?: $anneeParDefaut);
            $libelleCycles = collect($cycles)->map(fn ($c) => InstallerEtablissement::LIBELLES_CYCLES[$c])->implode(', ');
            $this->line($session
                ? "  Année {$session->libelle} active, sans classe."
                : "  Aucune année scolaire active : {$annee}-" . ($annee + 1) . ' sera créée et activée.');
            $this->line("  Classes à créer ({$libelleCycles}) : {$noms->count()}");

            if (! $this->option('oui') && ! $this->confirm('  Créer ces classes ?', true)) {
                $this->line('  Rien n\'a été créé.');
                continue;
            }

            DB::transaction(function () use ($etablissement, &$session, $annee, $noms, $cycles) {
                if (! $session) {
                    $session = SessionScolaire::create([
                        'etablissement_id' => $etablissement->id,
                        'libelle' => $annee . '-' . ($annee + 1),
                        'date_debut' => "{$annee}-10-01",
                        'date_fin' => ($annee + 1) . '-07-31',
                        'statut' => 'en_cours',
                        'est_active' => true,
                    ]);
                }
                foreach ($noms as $nom) {
                    Classe::create([
                        'etablissement_id' => $etablissement->id,
                        'session_scolaire_id' => $session->id,
                        'nom' => $nom,
                        'niveau' => $nom,
                    ]);
                }
                if (! $etablissement->cycles) {
                    $etablissement->update(['cycles' => $cycles]);
                }
            });

            $this->info("  {$noms->count()} classe(s) créée(s) dans l'année {$session->libelle}.");
        }

        return self::SUCCESS;
    }
}
