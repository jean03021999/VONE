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
                'eleves.voir', 'eleves.creer', 'eleves.modifier', 'eleves.importer',
                'enseignants.voir',
                'matieres.gerer', 'classes.voir', 'classes.gerer', 'affectations.gerer',
                'emploi_du_temps.voir', 'emploi_du_temps.gerer',
                'periodes.gerer',
                'notes.voir', 'notes.valider', 'notes.publier',
                'bulletins.voir', 'bulletins.generer', 'bulletins.valider', 'bulletins.publier',
            ],
            'Proviseur' => array_values(array_diff($catalogue, ['abonnement.voir', 'abonnement.gerer'])),
            'Censeur' => [
                'eleves.voir', 'eleves.creer', 'eleves.modifier', 'eleves.importer',
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
