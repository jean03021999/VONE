<?php

namespace Database\Seeders;

use App\Models\Etablissement;
use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;

class RolePermissionSeeder extends Seeder
{
    private const CODES_ETABLISSEMENTS_CIBLES = ['TEST-001', 'TEST-002'];

    public function run(): void
    {
        $etablissements = Etablissement::whereIn('code', self::CODES_ETABLISSEMENTS_CIBLES)->get();

        // Catalogue explicite (et non Permission::pluck('nom')) pour ne pas hériter
        // d'anciennes permissions orphelines eventuellement presentes en base.
        $catalogue = [
            'eleves.voir', 'eleves.creer', 'eleves.modifier', 'eleves.importer',
            'enseignants.voir', 'enseignants.creer', 'enseignants.contrats.gerer',
            'enseignants.salaires.voir', 'enseignants.salaires.gerer',
            'matieres.gerer', 'classes.voir', 'classes.gerer', 'affectations.gerer',
            'emploi_du_temps.voir', 'emploi_du_temps.gerer',
            'periodes.gerer',
            'notes.voir', 'notes.saisir', 'notes.soumettre', 'notes.valider', 'notes.publier',
            'bulletins.voir', 'bulletins.generer', 'bulletins.valider', 'bulletins.publier',
            'frais.voir', 'frais.creer', 'frais.paiement.enregistrer', 'frais.stats.voir',
            'abonnement.voir', 'abonnement.gerer',
        ];

        // Source de verite des permissions par role : correspond a l'etat en base (etablissement 2)
        // au 2026-09-25. Toute correction de permission se fait ici, pas a la main en base.
        $matrice = [
            'Comptable' => [
                'eleves.voir', 'eleves.creer', 'eleves.modifier', 'eleves.importer',
                'enseignants.voir', 'enseignants.creer',
                'enseignants.salaires.voir', 'enseignants.salaires.gerer',
                'classes.voir',
                'emploi_du_temps.voir',
                'frais.voir', 'frais.creer', 'frais.paiement.enregistrer',
            ],
            'Directeur' => [
                'eleves.voir',
                'enseignants.voir',
                'matieres.gerer', 'classes.voir', 'classes.gerer', 'affectations.gerer',
                'emploi_du_temps.voir', 'emploi_du_temps.gerer',
                'periodes.gerer',
                'notes.voir', 'notes.valider', 'notes.publier',
                'bulletins.voir', 'bulletins.generer', 'bulletins.valider', 'bulletins.publier',
            ],
            // Liste explicite (et non "catalogue sauf...") : une permission ajoutee au catalogue ne doit
            // pas etre accordee automatiquement au Proviseur.
            'Proviseur' => [
                'eleves.voir',
                'enseignants.voir', 'enseignants.creer', 'enseignants.contrats.gerer',
                'enseignants.salaires.voir', 'enseignants.salaires.gerer',
                'matieres.gerer', 'classes.voir', 'classes.gerer', 'affectations.gerer',
                'emploi_du_temps.voir', 'emploi_du_temps.gerer',
                'periodes.gerer',
                'notes.voir', 'notes.saisir', 'notes.soumettre', 'notes.valider', 'notes.publier',
                'bulletins.voir', 'bulletins.generer', 'bulletins.valider', 'bulletins.publier',
                'frais.voir', 'frais.creer', 'frais.paiement.enregistrer', 'frais.stats.voir',
            ],
            'Censeur' => [
                'eleves.voir',
                'enseignants.voir',
                'matieres.gerer', 'classes.voir', 'classes.gerer', 'affectations.gerer',
                'emploi_du_temps.voir', 'emploi_du_temps.gerer',
                'periodes.gerer',
                'notes.voir', 'notes.saisir', 'notes.soumettre',
                'bulletins.voir', 'bulletins.generer',
            ],
            'Fondateur' => [
                'eleves.voir', 'enseignants.voir', 'emploi_du_temps.voir', 'bulletins.voir',
                'frais.stats.voir', 'abonnement.voir', 'abonnement.gerer',
            ],
        ];

        foreach ($matrice as $nomRole => $nomsPermissions) {
            $inconnues = array_diff($nomsPermissions, $catalogue);
            if ($inconnues) {
                throw new \RuntimeException("Role {$nomRole} : permission(s) hors catalogue : " . implode(', ', $inconnues));
            }

            $idsPermissions = Permission::whereIn('nom', $nomsPermissions)->pluck('id');

            $modele = Role::updateOrCreate(
                ['nom' => $nomRole, 'etablissement_id' => null],
                ['description' => "Role modele : {$nomRole}", 'est_modele' => true]
            );
            $modele->permissions()->sync($idsPermissions);

            foreach ($etablissements as $etablissement) {
                $instance = Role::updateOrCreate(
                    ['nom' => $nomRole, 'etablissement_id' => $etablissement->id],
                    ['description' => "Role {$nomRole}", 'est_modele' => false]
                );
                $instance->permissions()->sync($idsPermissions);
            }
        }
    }
}
