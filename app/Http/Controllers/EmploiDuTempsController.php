<?php

namespace App\Http\Controllers;

use App\Models\EmploiDuTemps;
use App\Models\Affectation;
use App\Models\Classe;
use Illuminate\Http\Request;

class EmploiDuTempsController extends Controller
{
    public function index(Request $request, $classeId)
    {
        $etablissementId = $request->user()->etablissement_id;
        $classe = Classe::where('etablissement_id', $etablissementId)->findOrFail($classeId);

        $affectationIds = Affectation::where('classe_id', $classeId)->pluck('id');

        $creneaux = EmploiDuTemps::whereIn('affectation_id', $affectationIds)
            ->with(['affectation.matiere', 'affectation.enseignant'])
            ->get()
            ->map(fn($c) => [
                'id' => $c->id,
                'jour' => $c->jour,
                'heure_debut' => $c->heure_debut,
                'heure_fin' => $c->heure_fin,
                'matiere' => $c->affectation->matiere->nom,
                'enseignant' => $c->affectation->enseignant->nom . ' ' . $c->affectation->enseignant->prenom,
            ]);

        return response()->json($creneaux);
    }

    public function store(Request $request)
    {
        $request->validate([
            'classe_id' => 'required|exists:classes,id',
            'matiere_id' => 'required|exists:matieres,id',
            'enseignant_id' => 'required|exists:enseignants,id',
            'jour' => 'required|in:lundi,mardi,mercredi,jeudi,vendredi,samedi',
            'heure_debut' => 'required',
            'heure_fin' => 'required',
        ]);

        $affectation = Affectation::firstOrCreate([
            'enseignant_id' => $request->enseignant_id,
            'classe_id' => $request->classe_id,
            'matiere_id' => $request->matiere_id,
        ], [
            'volume_horaire_hebdomadaire' => 0,
            'est_classe_examen' => false,
        ]);

        $creneau = EmploiDuTemps::create([
            'affectation_id' => $affectation->id,
            'jour' => $request->jour,
            'heure_debut' => $request->heure_debut,
            'heure_fin' => $request->heure_fin,
        ]);

        return response()->json($creneau, 201);
    }

    public function exporter(Request $request, $classeId)
    {
        $etablissementId = $request->user()->etablissement_id;
        $classe = Classe::where('etablissement_id', $etablissementId)->findOrFail($classeId);

        $affectationIds = Affectation::where('classe_id', $classeId)->pluck('id');
        $creneaux = EmploiDuTemps::whereIn('affectation_id', $affectationIds)
            ->with(['affectation.matiere', 'affectation.enseignant'])
            ->get();

        $jours = ['lundi', 'mardi', 'mercredi', 'jeudi', 'vendredi', 'samedi'];
        $creneauxHoraires = $creneaux->map(fn($c) => $c->heure_debut . '-' . $c->heure_fin)->unique()->sort()->values();

        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $feuille = $spreadsheet->getActiveSheet();
        $feuille->setTitle('Emploi du temps');

        $feuille->setCellValue('A1', 'Horaire');
        foreach ($jours as $i => $jour) {
            $feuille->setCellValueByColumnAndRow($i + 2, 1, ucfirst($jour));
        }

        foreach ($creneauxHoraires as $ligneIndex => $horaire) {
            $feuille->setCellValue('A' . ($ligneIndex + 2), $horaire);
            foreach ($jours as $i => $jour) {
                [$debut, $fin] = explode('-', $horaire);
                $creneau = $creneaux->first(fn($c) => $c->jour === $jour && $c->heure_debut === $debut && $c->heure_fin === $fin);
                if ($creneau) {
                    $texte = $creneau->affectation->matiere->nom . "\n" . $creneau->affectation->enseignant->nom . ' ' . $creneau->affectation->enseignant->prenom;
                    $feuille->setCellValueByColumnAndRow($i + 2, $ligneIndex + 2, $texte);
                }
            }
        }

        foreach (range('A', 'H') as $colonne) {
            $feuille->getColumnDimension($colonne)->setAutoSize(true);
        }

        $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
        $nomFichier = 'emploi_du_temps_' . str_replace(' ', '_', $classe->nom) . '.xlsx';
        $chemin = storage_path('app/' . $nomFichier);
        $writer->save($chemin);

        return response()->download($chemin)->deleteFileAfterSend(true);
    }

    public function destroy($id)
    {
        EmploiDuTemps::findOrFail($id)->delete();
        return response()->json(['message' => 'Creneau supprime.']);
    }
}

