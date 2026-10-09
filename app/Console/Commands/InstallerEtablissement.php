<?php

namespace App\Console\Commands;

use App\Models\Classe;
use App\Models\Etablissement;
use App\Models\Role;
use App\Models\SessionScolaire;
use App\Models\User;
use App\Services\Numerotation;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Installation sur site (ecole pilote) : sur une base neuve (php artisan migrate), cree le catalogue
 * des permissions, les roles modeles, puis l'etablissement avec ses propres roles (copies des
 * modeles), le compte du fondateur, l'annee scolaire en cours (active) et, au choix, les classes
 * courantes des cycles de l'ecole (systeme guineen). Les autres comptes (directeur, comptable...) se
 * creent ensuite dans l'application : Parametres > Utilisateurs & roles.
 */
class InstallerEtablissement extends Command
{
    protected $signature = 'lakoli:installer
        {--nom= : Nom de l\'etablissement}
        {--ville= : Ville}
        {--telephone= : Telephone de l\'etablissement}
        {--fondateur= : Nom complet du fondateur}
        {--email= : E-mail de connexion du fondateur}
        {--telephone-fondateur= : Telephone du fondateur (facultatif, sert aussi d\'identifiant)}
        {--cycles= : Cycles de l\'ecole, separes par des virgules (maternelle,primaire,college,lycee)}
        {--annee= : Annee de debut de l\'annee scolaire en cours (ex. 2026 pour 2026-2027)}
        {--sans-classes : Ne pas creer les classes courantes}
        {--essai=30 : Jours d\'essai gratuit (0 = abonnement actif d\'emblee)}';

    protected $description = "Cree l'etablissement, ses roles, le compte du fondateur, l'annee scolaire et les classes sur une base neuve.";

    // Classes courantes par cycle (systeme guineen), dans l'ordre pedagogique.
    public const CLASSES = [
        'maternelle' => ['Crèche', 'Petite Section', 'Moyenne Section', 'Grande Section'],
        'primaire' => ['1ère Année', '2ème Année', '3ème Année', '4ème Année', '5ème Année', '6ème Année'],
        'college' => ['7ème Année', '8ème Année', '9ème Année', '10ème Année'],
        // 11e, 12e et Terminale : series SM, SE, SS.
        'lycee' => [
            '11ème Année - Sciences Mathématiques', '11ème Année - Sciences Expérimentales', '11ème Année - Sciences Sociales',
            '12ème Année - Sciences Mathématiques', '12ème Année - Sciences Expérimentales', '12ème Année - Sciences Sociales',
            'Terminale - Sciences Mathématiques', 'Terminale - Sciences Expérimentales', 'Terminale - Sciences Sociales',
        ],
    ];

    public const LIBELLES_CYCLES = [
        'maternelle' => 'Maternelle', 'primaire' => 'Primaire', 'college' => 'Collège', 'lycee' => 'Lycée',
    ];

    public function handle(): int
    {
        $nom = $this->option('nom') ?: $this->ask("Nom de l'établissement");
        $ville = $this->option('ville') ?: $this->ask('Ville', 'Conakry');
        $telephone = $this->option('telephone') ?: $this->ask("Téléphone de l'établissement (facultatif)", '');
        $fondateur = $this->option('fondateur') ?: $this->ask('Nom complet du fondateur');
        $email = $this->option('email') ?: $this->ask('E-mail de connexion du fondateur');
        $telFondateur = $this->option('telephone-fondateur') ?: $this->ask('Téléphone du fondateur (facultatif)', '');

        if (! $nom || ! $fondateur || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->error("Nom de l'établissement, nom du fondateur et e-mail valide sont obligatoires.");
            return self::FAILURE;
        }
        if (User::where('email', $email)->exists() || ($telFondateur && User::where('telephone', $telFondateur)->exists())) {
            $this->error('Un compte utilise déjà cet e-mail ou ce téléphone.');
            return self::FAILURE;
        }

        $motDePasse = $this->secret('Mot de passe du fondateur (8 caractères minimum)');
        if (strlen((string) $motDePasse) < 8 || $motDePasse !== $this->secret('Confirmez le mot de passe')) {
            $this->error('Mot de passe trop court ou confirmation différente.');
            return self::FAILURE;
        }

        // Cycles de l'ecole et annee scolaire en cours.
        if ($this->option('cycles')) {
            $cycles = array_values(array_intersect(array_keys(self::CLASSES), array_map('trim', explode(',', $this->option('cycles')))));
        } else {
            $choix = $this->choice(
                "Cycles de l'école (numéros séparés par des virgules)",
                array_values(self::LIBELLES_CYCLES),
                '0,1,2,3',
                null,
                true
            );
            $cycles = array_keys(array_intersect(self::LIBELLES_CYCLES, $choix));
        }
        if (! $cycles) {
            $this->error('Choisissez au moins un cycle.');
            return self::FAILURE;
        }

        // Annee scolaire : a partir d'aout, celle qui commence ; avant, celle qui se termine.
        $anneeParDefaut = now()->month >= 8 ? now()->year : now()->year - 1;
        $annee = (int) ($this->option('annee') ?: $this->ask('Année scolaire en cours : année de début', (string) $anneeParDefaut));
        if ($annee < 2000 || $annee > 2100) {
            $this->error('Année scolaire invalide.');
            return self::FAILURE;
        }
        $creerClasses = ! $this->option('sans-classes')
            && ($this->option('cycles') || $this->confirm('Créer les classes courantes de ces cycles ? (modifiables ensuite)', true));

        // Catalogue des permissions et roles modeles (idempotent).
        $this->call('db:seed', ['--class' => PermissionSeeder::class, '--force' => true]);
        $this->call('db:seed', ['--class' => RolePermissionSeeder::class, '--force' => true]);

        $modeles = Role::whereNull('etablissement_id')->where('est_modele', true)->with('permissions')->get();
        if (! $modeles->firstWhere('nom', 'Fondateur')) {
            $this->error('Rôle modèle « Fondateur » introuvable après le chargement des rôles.');
            return self::FAILURE;
        }

        $joursEssai = max(0, (int) $this->option('essai'));

        $etablissement = DB::transaction(function () use ($nom, $ville, $telephone, $fondateur, $email, $telFondateur, $motDePasse, $modeles, $cycles, $annee, $creerClasses, $joursEssai) {
            $etablissement = Etablissement::create([
                'nom' => trim($nom),
                'code' => $this->codeUnique($nom),
                // Prefixe des matricules et references, modifiable dans Parametres > Etablissement.
                'sigle' => Numerotation::initiales($nom),
                'type' => 'ecole_privee',
                'ville' => $ville ?: null,
                'telephone' => $telephone ?: null,
                'cycles' => $cycles,
                // Un mois d'essai gratuit par defaut, puis lecture seule (php artisan lakoli:essai).
                'statut' => $joursEssai > 0 ? 'essai' : 'actif',
                'date_fin_essai' => $joursEssai > 0 ? today()->addDays($joursEssai - 1)->toDateString() : null,
            ]);

            // Annee scolaire en cours, active d'emblee (aucune autre session sur une base neuve) :
            // la creation des classes et les inscriptions l'exigent.
            $session = SessionScolaire::create([
                'etablissement_id' => $etablissement->id,
                'libelle' => $annee . '-' . ($annee + 1),
                'date_debut' => "{$annee}-10-01",
                'date_fin' => ($annee + 1) . '-07-31',
                'statut' => 'en_cours',
                'est_active' => true,
            ]);

            if ($creerClasses) {
                foreach ($cycles as $cycle) {
                    foreach (self::CLASSES[$cycle] as $classe) {
                        Classe::create([
                            'etablissement_id' => $etablissement->id,
                            'session_scolaire_id' => $session->id,
                            'nom' => $classe,
                            'niveau' => $classe,
                        ]);
                    }
                }
            }

            // Roles propres a l'etablissement, copies des modeles avec leurs permissions.
            $roles = [];
            foreach ($modeles as $modele) {
                $role = Role::create([
                    'etablissement_id' => $etablissement->id,
                    'nom' => $modele->nom,
                    'description' => "Role {$modele->nom}",
                    'est_modele' => false,
                ]);
                $role->permissions()->sync($modele->permissions->pluck('id'));
                $roles[$modele->nom] = $role;
            }

            $utilisateur = User::create([
                'etablissement_id' => $etablissement->id,
                'name' => trim($fondateur),
                'email' => trim($email),
                'telephone' => $telFondateur ?: null,
                'password' => Hash::make($motDePasse),
                'statut' => 'actif',
            ]);
            $utilisateur->roles()->sync([$roles['Fondateur']->id]);

            return $etablissement;
        });

        $this->newLine();
        $this->info("Établissement « {$etablissement->nom} » créé (code {$etablissement->code}).");
        $this->line("Compte fondateur : {$email} — connexion avec le profil « Fondateur ».");
        $nbClasses = Classe::where('etablissement_id', $etablissement->id)->count();
        $this->line("Année scolaire {$annee}-" . ($annee + 1) . " active ; {$nbClasses} classe(s) créée(s).");
        $this->line($joursEssai > 0
            ? "Essai gratuit de {$joursEssai} jours, jusqu'au " . $etablissement->date_fin_essai->format('d/m/Y') . " inclus (prolonger ou activer : php artisan lakoli:essai)."
            : "Abonnement actif, sans période d'essai.");
        $this->line('Ensuite : Paramètres > Utilisateurs & rôles pour créer les comptes (directeur ou proviseur, comptable...).');

        return self::SUCCESS;
    }

    // Code court tire du nom (« Groupe scolaire Saint Emmanuel » -> GSSE), rendu unique.
    private function codeUnique(string $nom): string
    {
        $initiales = collect(preg_split('/\s+/', Str::ascii($nom)))
            ->filter()
            ->map(fn ($mot) => strtoupper($mot[0]))
            ->implode('');
        $base = substr(preg_replace('/[^A-Z0-9]/', '', $initiales) ?: 'ECOLE', 0, 8);

        $code = $base;
        for ($i = 2; Etablissement::withTrashed()->where('code', $code)->exists(); $i++) {
            $code = "{$base}-{$i}";
        }

        return $code;
    }
}
