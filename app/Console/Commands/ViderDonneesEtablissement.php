<?php

namespace App\Console\Commands;

use App\Models\Etablissement;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Repart de zero pour un etablissement en GARDANT sa structure : l'etablissement, les comptes et
 * roles, les annees scolaires, les classes et les filieres. Supprime tout le reste : eleves (et leurs
 * inscriptions, frais, paiements, notes, bulletins, relances, historiques), enseignants (contrats,
 * salaires, affectations, emploi du temps), matieres, periodes, types de frais et grilles tarifaires,
 * depenses (et justificatifs), arretes de caisse ; plus les fichiers lies (photos, justificatifs).
 *
 * Par defaut : simulation (affiche ce qui serait supprime). --executer : supprime, apres avoir fait
 * taper le nom de l'etablissement. Faire une sauvegarde avant (deploiement\sauvegarde.ps1).
 */
class ViderDonneesEtablissement extends Command
{
    protected $signature = 'lakoli:vider-donnees
        {etablissement : Identifiant (id) de l\'etablissement}
        {--executer : Supprime reellement (sinon simple simulation)}';

    protected $description = "Supprime les eleves et les autres donnees d'un etablissement, en gardant comptes, annees scolaires et classes.";

    public function handle(): int
    {
        $etablissement = Etablissement::find($this->argument('etablissement'));
        if (! $etablissement) {
            $this->error('Établissement introuvable.');
            return self::FAILURE;
        }
        $id = $etablissement->id;

        $eleves = DB::table('eleves')->where('etablissement_id', $id);
        $enseignants = DB::table('enseignants')->where('etablissement_id', $id);
        $classes = DB::table('classes')->where('etablissement_id', $id)->pluck('id');
        $depenses = DB::table('depenses')->where('etablissement_id', $id)->pluck('id');

        $this->info("Établissement : {$etablissement->nom} (id {$id})");
        $this->newLine();
        $this->line('GARDÉ :');
        $this->table(['Donnée', 'Nombre'], [
            ['Comptes utilisateurs', DB::table('users')->where('etablissement_id', $id)->count()],
            ['Années scolaires', DB::table('sessions_scolaires')->where('etablissement_id', $id)->count()],
            ['Classes', $classes->count()],
            ['Filières', DB::table('filieres')->where('etablissement_id', $id)->count()],
        ]);

        $idsEleves = (clone $eleves)->pluck('id');
        $idsEnseignants = (clone $enseignants)->pluck('id');
        $this->line('SUPPRIMÉ :');
        $this->table(['Donnée', 'Nombre'], [
            ['Élèves', $idsEleves->count()],
            ['Inscriptions', DB::table('inscriptions')->whereIn('eleve_id', $idsEleves)->count()],
            ['Frais des élèves', DB::table('frais_eleves')->whereIn('eleve_id', $idsEleves)->count()],
            ['Paiements', DB::table('paiements')->whereIn('eleve_id', $idsEleves)->count()],
            ['Notes', DB::table('notes')->whereIn('eleve_id', $idsEleves)->count()],
            ['Bulletins', DB::table('bulletins')->where('etablissement_id', $id)->count()],
            ['Relances', DB::table('relances')->where('etablissement_id', $id)->count()],
            ['Enseignants', $idsEnseignants->count()],
            ['Salaires', DB::table('salaires')->where('etablissement_id', $id)->count()],
            ['Affectations (classe / matière)', DB::table('affectations')->whereIn('classe_id', $classes)->count()],
            ['Matières', DB::table('matieres')->where('etablissement_id', $id)->count()],
            ['Périodes', DB::table('periodes')->where('etablissement_id', $id)->count()],
            ['Types de frais', DB::table('types_frais')->where('etablissement_id', $id)->count()],
            ['Grilles tarifaires', DB::table('grilles_tarifaires')->where('etablissement_id', $id)->count()],
            ['Dépenses', $depenses->count()],
            ['Arrêtés de caisse', DB::table('arretes_caisse')->where('etablissement_id', $id)->count()],
        ]);

        if (! $this->option('executer')) {
            $this->warn('Simulation : rien n\'a été supprimé. Pour supprimer : ajoutez --executer (après une sauvegarde).');
            return self::SUCCESS;
        }

        $saisie = $this->ask("Suppression DÉFINITIVE. Tapez le nom exact de l'établissement pour confirmer");
        if (trim((string) $saisie) !== $etablissement->nom) {
            $this->error('Nom différent : suppression annulée.');
            return self::FAILURE;
        }

        // Fichiers a supprimer une fois la base videe (photos des eleves et enseignants, justificatifs).
        $fichiers = collect()
            ->merge((clone $eleves)->whereNotNull('photo_path')->pluck('photo_path'))
            ->merge((clone $enseignants)->whereNotNull('photo_path')->pluck('photo_path'))
            ->merge(DB::table('justificatifs_depense')->whereIn('depense_id', $depenses)->pluck('fichier_path'))
            ->filter();

        DB::transaction(function () use ($id, $classes) {
            // Eleves : inscriptions, frais (et echeances), paiements, notes, bulletins, relances,
            // filiations et historiques suivent par cascade.
            DB::table('eleves')->where('etablissement_id', $id)->delete();
            DB::table('relances')->where('etablissement_id', $id)->delete();
            DB::table('bulletins')->where('etablissement_id', $id)->delete();
            // Enseignants : contrats, salaires, affectations (emploi du temps, evaluations) par cascade.
            DB::table('enseignants')->where('etablissement_id', $id)->delete();
            DB::table('salaires')->where('etablissement_id', $id)->delete();
            DB::table('affectations')->whereIn('classe_id', $classes)->delete();
            DB::table('matieres')->where('etablissement_id', $id)->delete();
            DB::table('periodes')->where('etablissement_id', $id)->delete();
            // Grilles (echeances) puis types de frais.
            DB::table('grilles_tarifaires')->where('etablissement_id', $id)->delete();
            DB::table('types_frais')->where('etablissement_id', $id)->delete();
            // Depenses (justificatifs par cascade) et arretes de caisse.
            DB::table('depenses')->where('etablissement_id', $id)->delete();
            DB::table('arretes_caisse')->where('etablissement_id', $id)->delete();
        });

        Storage::disk('local')->delete($fichiers->all());

        $this->info('Données supprimées. Établissement, comptes, années scolaires et classes conservés.');
        $this->line("{$fichiers->count()} fichier(s) supprimé(s) (photos, justificatifs).");

        return self::SUCCESS;
    }
}
