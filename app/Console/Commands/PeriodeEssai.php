<?php

namespace App\Console\Commands;

use App\Models\Etablissement;
use Carbon\Carbon;
use Illuminate\Console\Command;

/**
 * Periode d'essai de l'ecole (reservee a l'editeur, sur le PC de l'ecole ou a distance) :
 *   php artisan lakoli:essai               etat actuel
 *   php artisan lakoli:essai 30            essai de 30 jours a partir d'aujourd'hui (prolongation comprise)
 *   php artisan lakoli:essai --jusqu-au=2026-12-31
 *   php artisan lakoli:essai --activer     abonnement paye : plus de limite
 *   php artisan lakoli:essai --suspendre   lecture seule immediate
 * Essai termine ou compte suspendu : l'application passe en lecture seule (LectureSeuleAbonnement).
 */
class PeriodeEssai extends Command
{
    protected $signature = 'lakoli:essai
        {jours? : Nombre de jours d\'essai a partir d\'aujourd\'hui (30 par defaut)}
        {--jusqu-au= : Dernier jour d\'essai (AAAA-MM-JJ)}
        {--activer : Abonnement paye, plus de date limite}
        {--suspendre : Lecture seule immediate}
        {--etablissement= : Identifiant de l\'ecole (si la base en contient plusieurs)}';

    protected $description = "Période d'essai de l'école : afficher, fixer, prolonger, activer ou suspendre";

    public function handle(): int
    {
        $etablissement = $this->etablissement();
        if (!$etablissement) {
            return self::FAILURE;
        }

        if ($this->option('activer')) {
            $etablissement->update(['statut' => 'actif', 'date_fin_essai' => null]);
            $this->info("{$etablissement->nom} : abonnement activé, plus de date limite.");
        } elseif ($this->option('suspendre')) {
            $etablissement->update(['statut' => 'suspendu']);
            $this->warn("{$etablissement->nom} : compte suspendu, LAKOLI passe en lecture seule.");
        } elseif ($this->option('jusqu-au') || $this->argument('jours') !== null) {
            if ($this->option('jusqu-au')) {
                try {
                    $fin = Carbon::createFromFormat('!Y-m-d', $this->option('jusqu-au'));
                } catch (\Throwable $e) {
                    $fin = false;
                }
                if (!$fin) {
                    $this->error('Date invalide : utilisez AAAA-MM-JJ (ex. 2026-12-31).');
                    return self::FAILURE;
                }
            } else {
                $jours = (int) $this->argument('jours');
                if ($jours < 1) {
                    $this->error("Nombre de jours invalide : au moins 1 (un mois d'essai = " . Etablissement::JOURS_ESSAI . ').');
                    return self::FAILURE;
                }
                // Dernier jour compris : 30 jours a partir d'aujourd'hui = aujourd'hui + 29.
                $fin = today()->addDays($jours - 1);
            }
            if ($fin->lt(today())) {
                $this->error("La date de fin est déjà passée : l'école serait immédiatement en lecture seule (utilisez --suspendre pour cela).");
                return self::FAILURE;
            }
            $etablissement->update(['statut' => 'essai', 'date_fin_essai' => $fin->toDateString()]);
        }

        $this->afficher($etablissement->fresh());

        return self::SUCCESS;
    }

    private function etablissement(): ?Etablissement
    {
        if ($id = $this->option('etablissement')) {
            $etablissement = Etablissement::find($id);
            if (!$etablissement) {
                $this->error("Aucune école n° {$id}.");
            }

            return $etablissement;
        }

        $ecoles = Etablissement::orderBy('id')->get();
        if ($ecoles->count() === 1) {
            return $ecoles->first();
        }
        if ($ecoles->isEmpty()) {
            $this->error("Aucune école : lancez d'abord php artisan lakoli:installer.");
        } else {
            $this->error('Plusieurs écoles dans la base : précisez --etablissement=ID.');
            $this->table(['ID', 'École', 'Statut'], $ecoles->map(fn ($e) => [$e->id, $e->nom, $e->statut])->all());
        }

        return null;
    }

    private function afficher(Etablissement $etablissement): void
    {
        $etat = $etablissement->etatAbonnement();
        $detail = match (true) {
            $etat['lecture_seule'] && $etablissement->statut === 'suspendu' => 'suspendu : lecture seule',
            $etat['lecture_seule'] => "essai terminé le " . Carbon::parse($etat['date_fin_essai'])->format('d/m/Y') . ' : lecture seule',
            $etablissement->statut === 'essai' && $etat['date_fin_essai'] => 'essai jusqu\'au ' . Carbon::parse($etat['date_fin_essai'])->format('d/m/Y')
                . " inclus ({$etat['jours_restants']} jour(s) restant(s)" . ($etat['rappel'] ? ', rappel affiché' : '') . ')',
            $etablissement->statut === 'essai' => 'essai sans date de fin',
            default => 'abonnement actif, sans limite',
        };
        $this->line("{$etablissement->nom} : {$detail}.");
    }
}
