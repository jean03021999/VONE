<?php

namespace App\Console\Commands;

use App\Models\Etablissement;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Installation sur site (ecole pilote) : sur une base neuve (php artisan migrate), cree le catalogue
 * des permissions, les roles modeles, puis l'etablissement avec ses propres roles (copies des
 * modeles) et le compte du fondateur. Les autres comptes (directeur, comptable...) se creent ensuite
 * dans l'application : Parametres > Utilisateurs.
 */
class InstallerEtablissement extends Command
{
    protected $signature = 'lakoli:installer
        {--nom= : Nom de l\'etablissement}
        {--ville= : Ville}
        {--telephone= : Telephone de l\'etablissement}
        {--fondateur= : Nom complet du fondateur}
        {--email= : E-mail de connexion du fondateur}
        {--telephone-fondateur= : Telephone du fondateur (facultatif, sert aussi d\'identifiant)}';

    protected $description = "Cree l'etablissement, ses roles et le compte du fondateur sur une base neuve.";

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

        // Catalogue des permissions et roles modeles (idempotent).
        $this->call('db:seed', ['--class' => PermissionSeeder::class, '--force' => true]);
        $this->call('db:seed', ['--class' => RolePermissionSeeder::class, '--force' => true]);

        $modeles = Role::whereNull('etablissement_id')->where('est_modele', true)->with('permissions')->get();
        if (! $modeles->firstWhere('nom', 'Fondateur')) {
            $this->error('Rôle modèle « Fondateur » introuvable après le chargement des rôles.');
            return self::FAILURE;
        }

        $etablissement = DB::transaction(function () use ($nom, $ville, $telephone, $fondateur, $email, $telFondateur, $motDePasse, $modeles) {
            $etablissement = Etablissement::create([
                'nom' => trim($nom),
                'code' => $this->codeUnique($nom),
                'type' => 'ecole_privee',
                'ville' => $ville ?: null,
                'telephone' => $telephone ?: null,
                'statut' => 'actif',
            ]);

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
        $this->line('Créez ensuite les autres comptes dans Paramètres > Utilisateurs, puis la session scolaire.');

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
