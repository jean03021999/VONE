<?php

namespace App\Console\Commands;

use App\Models\Bulletin;
use App\Models\Classe;
use App\Models\Eleve;
use App\Models\FraisEleve;
use App\Models\Inscription;
use App\Models\Periode;
use App\Models\SessionScolaire;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

class MigrateInscriptions extends Command
{
    protected $signature = 'lakoli:migrate-inscriptions {--dry-run : Simule sans écrire en base}';

    protected $description = 'Migre les eleves existants (classe_id/session_scolaire_id) vers la table inscriptions.';

    /** @var array<string,int> */
    private array $stats = [
        'analyses' => 0,
        'crees' => 0,
        'deja_existants' => 0,
        'nouvelle_haute' => 0,
        'nouvelle_moyenne' => 0,
        'reinscription' => 0,
        'a_determiner' => 0,
        'erreurs' => 0,
    ];

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $this->info($dryRun
            ? 'Mode simulation (--dry-run) : aucune ecriture en base.'
            : 'Mode reel : les inscriptions seront creees en base.');

        $sessions = SessionScolaire::all()->keyBy('id');
        $classes = Classe::all()->keyBy('id');
        $periodesParSession = Periode::all()->groupBy('session_scolaire_id')
            ->map(fn ($periodes) => $periodes->pluck('id')->all())
            ->all();

        $lignes = [];

        Eleve::withTrashed()
            ->without('inscriptionActive')
            ->chunkById(200, function ($eleves) use (&$lignes, $sessions, $classes, $periodesParSession, $dryRun) {
                foreach ($eleves as $eleve) {
                    $this->stats['analyses']++;

                    $ligne = $this->traiterEleve($eleve, $sessions, $classes, $periodesParSession, $dryRun);

                    if ($dryRun) {
                        $lignes[] = $ligne;
                    }
                }
            });

        if ($dryRun) {
            $this->newLine();
            $this->table(
                ['eleve_id', 'nom', 'session', 'classe', 'type_propose', 'niveau_de_confiance', 'raison'],
                $lignes
            );
        }

        $this->afficherRapport();

        return self::SUCCESS;
    }

    /**
     * @param  \Illuminate\Support\Collection<int, SessionScolaire>  $sessions
     * @param  \Illuminate\Support\Collection<int, Classe>  $classes
     * @param  array<int, array<int, int>>  $periodesParSession
     * @return array<int, mixed>
     */
    private function traiterEleve(Eleve $eleve, $sessions, $classes, array $periodesParSession, bool $dryRun): array
    {
        // On lit les colonnes brutes eleves.session_scolaire_id / eleves.classe_id : les
        // accesseurs du modele (getSessionScolaireIdAttribute/getClasseIdAttribute) passent
        // par inscriptionActive, qui est justement vide tant que cette migration n'a pas tourne.
        $sessionId = (int) $eleve->getRawOriginal('session_scolaire_id');
        $classeId = (int) $eleve->getRawOriginal('classe_id');
        $session = $sessions->get($sessionId);
        $nom = trim("{$eleve->nom} {$eleve->prenom}");
        $classeLabel = $classes->get($classeId)?->nom ?? "#{$classeId}";
        $sessionLabel = $session?->libelle ?? "#{$sessionId}";

        // Idempotence : une inscription existe deja pour ce couple (eleve, session)
        $existe = Inscription::where('eleve_id', $eleve->id)
            ->where('session_scolaire_id', $sessionId)
            ->exists();

        if ($existe) {
            $this->stats['deja_existants']++;

            return [$eleve->id, $nom, $sessionLabel, $classeLabel, '-', '-', 'Inscription deja existante (idempotence)'];
        }

        [$type, $confiance, $raison] = $this->determinerType($eleve, $session, $periodesParSession);

        match (true) {
            $type === 'reinscription' => $this->stats['reinscription']++,
            $type === 'nouvelle' && $confiance === 'haute' => $this->stats['nouvelle_haute']++,
            $type === 'nouvelle' => $this->stats['nouvelle_moyenne']++,
            default => $this->stats['a_determiner']++,
        };

        if (! $dryRun) {
            $this->creerInscription($eleve, $sessionId, $classeId, $type);
        }

        return [$eleve->id, $nom, $sessionLabel, $classeLabel, $type, $confiance, $raison];
    }

    private function creerInscription(Eleve $eleve, int $sessionId, int $classeId, string $type): void
    {
        try {
            DB::transaction(function () use ($eleve, $sessionId, $classeId, $type) {
                Inscription::create([
                    'eleve_id' => $eleve->id,
                    'session_scolaire_id' => $sessionId,
                    'classe_id' => $classeId,
                    'type_inscription' => $type,
                    'statut' => 'active',
                    'date_inscription' => $eleve->created_at ?? now(),
                ]);
            });

            $this->stats['crees']++;
        } catch (UniqueConstraintViolationException $e) {
            // Course entre deux executions concurrentes ou doublon non detecte par le check d'idempotence.
            $this->stats['erreurs']++;
            $this->error("Eleve #{$eleve->id}: violation de contrainte unique - {$e->getMessage()}");
        } catch (QueryException $e) {
            $this->stats['erreurs']++;
            $this->error("Eleve #{$eleve->id}: erreur base de donnees - {$e->getMessage()}");
        }
    }

    /**
     * @param  array<int, array<int, int>>  $periodesParSession
     * @return array{0: string, 1: string, 2: string}
     */
    private function determinerType(Eleve $eleve, ?SessionScolaire $session, array $periodesParSession): array
    {
        if (! $session || ! $eleve->created_at) {
            return ['a_determiner', 'basse', 'Session ou date de creation introuvable'];
        }

        if ($this->aPreuveSessionAnterieure($eleve, $session, $periodesParSession)) {
            return ['reinscription', 'haute', 'Frais eleve ou bulletin trouve sur une session anterieure'];
        }

        $dateDebut = Carbon::parse($session->date_debut);

        if ($eleve->created_at->greaterThanOrEqualTo($dateDebut)) {
            return ['nouvelle', 'haute', 'Cree a/apres le debut de la session, aucune preuve de session anterieure'];
        }

        // Fiche creee avant le debut officiel de la session (import, saisie a posteriori...) :
        // le signal de date est moins fiable, on degrade la confiance a "moyenne".
        return ['nouvelle', 'moyenne', 'Cree avant le debut de la session, aucune preuve de session anterieure'];
    }

    /**
     * @param  array<int, array<int, int>>  $periodesParSession
     */
    private function aPreuveSessionAnterieure(Eleve $eleve, SessionScolaire $session, array $periodesParSession): bool
    {
        $dateDebutSession = Carbon::parse($session->date_debut);

        $sessionsAnterieures = SessionScolaire::query()
            ->where('id', '!=', $session->id)
            ->get()
            ->filter(fn (SessionScolaire $s) => Carbon::parse($s->date_debut)->lessThan($dateDebutSession))
            ->pluck('id');

        if ($sessionsAnterieures->isEmpty()) {
            return false;
        }

        $aFrais = FraisEleve::where('eleve_id', $eleve->id)
            ->whereIn('session_scolaire_id', $sessionsAnterieures)
            ->exists();

        if ($aFrais) {
            return true;
        }

        $periodeIds = $sessionsAnterieures
            ->flatMap(fn ($sid) => $periodesParSession[$sid] ?? [])
            ->all();

        if (empty($periodeIds)) {
            return false;
        }

        return Bulletin::where('eleve_id', $eleve->id)
            ->whereIn('periode_id', $periodeIds)
            ->exists();
    }

    private function afficherRapport(): void
    {
        $this->newLine();
        $this->info('Rapport de migration');
        $this->table(
            ['Indicateur', 'Valeur'],
            [
                ['Analyses', $this->stats['analyses']],
                ['Crees', $this->stats['crees']],
                ['Deja existants', $this->stats['deja_existants']],
                ['Type nouvelle (haute)', $this->stats['nouvelle_haute']],
                ['Type nouvelle (moyenne)', $this->stats['nouvelle_moyenne']],
                ['Type reinscription', $this->stats['reinscription']],
                ['Type a_determiner', $this->stats['a_determiner']],
                ['Erreurs', $this->stats['erreurs']],
            ]
        );
    }
}
