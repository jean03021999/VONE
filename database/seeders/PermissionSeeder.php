<?php

namespace Database\Seeders;

use App\Models\Permission;
use Illuminate\Database\Seeder;

class PermissionSeeder extends Seeder
{
    public function run(): void
    {
        $permissions = [
            ['nom' => 'eleves.voir', 'module' => 'eleves', 'description' => 'Consulter la liste et les fiches des eleves'],
            ['nom' => 'eleves.creer', 'module' => 'eleves', 'description' => 'Creer un eleve'],
            ['nom' => 'eleves.modifier', 'module' => 'eleves', 'description' => 'Modifier un eleve'],
            ['nom' => 'eleves.importer', 'module' => 'eleves', 'description' => 'Importer des eleves en masse'],

            ['nom' => 'enseignants.voir', 'module' => 'enseignants', 'description' => 'Consulter la liste des enseignants'],
            ['nom' => 'enseignants.creer', 'module' => 'enseignants', 'description' => 'Creer ou modifier un enseignant'],
            ['nom' => 'enseignants.contrats.gerer', 'module' => 'enseignants', 'description' => 'Gerer les contrats des enseignants'],
            ['nom' => 'enseignants.salaires.voir', 'module' => 'enseignants', 'description' => 'Consulter les salaires des enseignants'],
            ['nom' => 'enseignants.salaires.gerer', 'module' => 'enseignants', 'description' => 'Gerer les salaires des enseignants'],

            ['nom' => 'matieres.gerer', 'module' => 'matieres', 'description' => 'Gerer les matieres et filieres'],
            ['nom' => 'classes.voir', 'module' => 'classes', 'description' => 'Consulter la liste des classes'],
            ['nom' => 'classes.gerer', 'module' => 'classes', 'description' => 'Creer ou modifier les classes'],
            ['nom' => 'affectations.gerer', 'module' => 'affectations', 'description' => 'Gerer les affectations enseignant/classe/matiere'],

            ['nom' => 'emploi_du_temps.voir', 'module' => 'emploi_du_temps', 'description' => 'Consulter l\'emploi du temps'],
            ['nom' => 'emploi_du_temps.gerer', 'module' => 'emploi_du_temps', 'description' => 'Creer ou modifier l\'emploi du temps'],

            ['nom' => 'periodes.gerer', 'module' => 'periodes', 'description' => 'Creer ou modifier les periodes scolaires'],

            ['nom' => 'notes.voir', 'module' => 'notes', 'description' => 'Consulter les evaluations et notes'],
            ['nom' => 'notes.saisir', 'module' => 'notes', 'description' => 'Saisir des notes'],
            ['nom' => 'notes.soumettre', 'module' => 'notes', 'description' => 'Soumettre des notes pour validation'],
            ['nom' => 'notes.valider', 'module' => 'notes', 'description' => 'Valider ou rejeter des notes soumises'],
            ['nom' => 'notes.publier', 'module' => 'notes', 'description' => 'Publier des notes validees'],

            ['nom' => 'bulletins.voir', 'module' => 'bulletins', 'description' => 'Consulter les bulletins'],
            ['nom' => 'bulletins.generer', 'module' => 'bulletins', 'description' => 'Generer les bulletins d\'une classe'],
            ['nom' => 'bulletins.valider', 'module' => 'bulletins', 'description' => 'Valider les bulletins generes'],
            ['nom' => 'bulletins.publier', 'module' => 'bulletins', 'description' => 'Publier les bulletins aux familles'],

            ['nom' => 'frais.voir', 'module' => 'frais', 'description' => 'Consulter types de frais, grilles et suivi eleve'],
            ['nom' => 'frais.creer', 'module' => 'frais', 'description' => 'Creer des types de frais et grilles tarifaires'],
            ['nom' => 'frais.paiement.enregistrer', 'module' => 'frais', 'description' => 'Enregistrer un paiement de frais de scolarite'],
            ['nom' => 'frais.stats.voir', 'module' => 'frais', 'description' => 'Consulter les statistiques financieres'],

            ['nom' => 'abonnement.voir', 'module' => 'abonnement', 'description' => 'Consulter l\'abonnement de l\'etablissement'],
            ['nom' => 'abonnement.gerer', 'module' => 'abonnement', 'description' => 'Gerer l\'abonnement (plan, facturation)'],
        ];

        foreach ($permissions as $permission) {
            Permission::updateOrCreate(['nom' => $permission['nom']], $permission);
        }
    }
}
