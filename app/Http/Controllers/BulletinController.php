<?php

namespace App\Http\Controllers;

use App\Models\Bulletin;
use App\Models\LigneBulletin;
use App\Models\Classe;
use App\Models\Periode;
use App\Models\Eleve;
use App\Models\Note;
use App\Models\MatiereCoefficient;
use Illuminate\Http\Request;

class BulletinController extends Controller
{
    private function coefficientPour($matiereId, $classe)
    {
        $coef = MatiereCoefficient::where('matiere_id', $matiereId)
            ->where(function ($q) use ($classe) {
                $q->where('filiere_id', $classe->filiere_id)->orWhereNull('filiere_id');
            })
            ->where(function ($q) use ($classe) {
                $q->where('niveau', $classe->niveau)->orWhereNull('niveau');
            })
            ->orderByRaw('filiere_id IS NULL, niveau IS NULL')
            ->first();

        return $coef?->coefficient ?? 1;
    }

    private function compteDansMoyenne($matiereId, $classe): bool
    {
        $coef = MatiereCoefficient::where('matiere_id', $matiereId)
            ->where(function ($q) use ($classe) {
                $q->where('filiere_id', $classe->filiere_id)->orWhereNull('filiere_id');
            })
            ->where(function ($q) use ($classe) {
                $q->where('niveau', $classe->niveau)->orWhereNull('niveau');
            })
            ->orderByRaw('filiere_id IS NULL, niveau IS NULL')
            ->first();

        return $coef?->compte_dans_moyenne ?? true;
    }

    public function genererPourClasse(Request $request)
    {
        $request->validate(['classe_id' => 'required|exists:classes,id', 'periode_id' => 'required|exists:periodes,id']);

        $etablissementId = $request->user()->etablissement_id;
        $classe = Classe::findOrFail($request->classe_id);
        $periode = Periode::findOrFail($request->periode_id);
        $eleves = Eleve::whereHas('inscriptionActive', fn($q) => $q->where('classe_id', $classe->id)->where('statut', 'active'))->get();

        // Verifie que TOUTES les matieres affectees a cette classe ont au moins
        // une evaluation publiee ou archivee sur cette periode, avant d'autoriser
        // la generation - un bulletin ne doit jamais etre partiel.
        $matieresAffectees = \App\Models\Affectation::where('classe_id', $classe->id)
            ->with('matiere')
            ->get()
            ->pluck('matiere.nom', 'matiere_id')
            ->unique();

        $matieresAvecNotesPubliees = \App\Models\Evaluation::whereHas('affectation', fn($q) => $q->where('classe_id', $classe->id))
            ->where('periode_id', $periode->id)
            ->whereIn('statut', ['publie', 'archive'])
            ->with('affectation')
            ->get()
            ->pluck('affectation.matiere_id')
            ->unique();

        $matieresManquantes = $matieresAffectees->keys()->diff($matieresAvecNotesPubliees);

        if ($matieresManquantes->isNotEmpty()) {
            $nomsManquants = $matieresManquantes->map(fn($id) => $matieresAffectees[$id])->values();
            return response()->json([
                'message' => 'Generation impossible : toutes les matieres de la classe doivent avoir au moins une evaluation publiee pour cette periode.',
                'matieres_manquantes' => $nomsManquants,
            ], 422);
        }

        $bulletinsGeneres = [];

        foreach ($eleves as $eleve) {
            // Recupere toutes les notes publiees/archivees de cet eleve, sur la periode, groupees par matiere
            $notes = Note::where('eleve_id', $eleve->id)
                ->whereHas('evaluation', function ($q) use ($periode) {
                    $q->where('periode_id', $periode->id)->whereIn('statut', ['publie', 'archive']);
                })
                ->with('evaluation.affectation.matiere')
                ->get()
                ->groupBy(fn($n) => $n->evaluation->affectation->matiere_id);

            if ($notes->isEmpty()) {
                continue; // aucune matiere publiee, pas de bulletin genere pour cet eleve
            }

            // Marque l'ancien bulletin courant (s'il existe) comme remplace
            $ancien = Bulletin::where('eleve_id', $eleve->id)->where('periode_id', $periode->id)->where('statut', 'courante')->first();

            $sommeValeursPonderees = 0;
            $sommeCoefficients = 0;
            $lignesACreer = [];

            foreach ($notes as $matiereId => $notesMatiere) {
                $notesValides = $notesMatiere->filter(fn($n) => $n->valeur !== null && $n->statut_presence === 'present');
                if ($notesValides->isEmpty()) continue; // matiere exclue (tout absent)

                $moyenneMatiere = round($notesValides->avg('valeur'), 2);
                $compteDansMoyenne = $this->compteDansMoyenne($matiereId, $classe);
                $coefficient = $compteDansMoyenne ? $this->coefficientPour($matiereId, $classe) : 0;
                $valeurPonderee = round($moyenneMatiere * $coefficient, 2);

                if ($compteDansMoyenne) {
                    $sommeValeursPonderees += $valeurPonderee;
                    $sommeCoefficients += $coefficient;
                }

                $lignesACreer[] = [
                    'matiere_id' => $matiereId,
                    'coefficient' => $coefficient,
                    'moyenne_matiere' => $moyenneMatiere,
                    'valeur_ponderee' => $valeurPonderee,
                ];
            }

            if ($sommeCoefficients == 0) continue;

            $moyenneGenerale = round($sommeValeursPonderees / $sommeCoefficients, 2);
            $nouvelleVersion = $ancien ? $ancien->version + 1 : 1;

            $bulletin = Bulletin::create([
                'etablissement_id' => $etablissementId,
                'eleve_id' => $eleve->id,
                'periode_id' => $periode->id,
                'est_annuel' => false,
                'moyenne' => $moyenneGenerale,
                'version' => $nouvelleVersion,
                'statut' => 'courante',
                'genere_par' => $request->user()->id,
            ]);

            foreach ($lignesACreer as $ligne) {
                $bulletin->lignes()->create($ligne);
            }

            if ($ancien) {
                $ancien->update(['statut' => 'remplacee', 'remplacee_par_id' => $bulletin->id, 'date_remplacement' => now()]);
            }

            $bulletinsGeneres[] = $bulletin;
        }

        // Calcul du rang, avec gestion des egalites
        $bulletinsClasse = Bulletin::where('periode_id', $periode->id)
            ->where('statut', 'courante')
            ->whereIn('eleve_id', Eleve::whereHas('inscriptionActive', fn($q) => $q->where('classe_id', $classe->id)->where('statut', 'active'))->pluck('id'))
            ->orderByDesc('moyenne')
            ->get();

        $rang = 1;
        $precedent = null;
        foreach ($bulletinsClasse as $index => $b) {
            if ($precedent !== null && $b->moyenne < $precedent) {
                $rang = $index + 1;
            }
            $b->update(['rang' => $rang, 'effectif_classe' => $bulletinsClasse->count()]);
            $precedent = $b->moyenne;
        }

        return response()->json(['message' => count($bulletinsGeneres) . ' bulletin(s) genere(s).', 'total' => count($bulletinsGeneres)]);
    }

    public function parClasse(Request $request)
    {
        $request->validate(['classe_id' => 'required|exists:classes,id', 'periode_id' => 'required|exists:periodes,id']);

        $bulletins = Bulletin::where('periode_id', $request->periode_id)
            ->where('statut', 'courante')
            ->whereIn('eleve_id', Eleve::whereHas('inscriptionActive', fn($q) => $q->where('classe_id', $request->classe_id)->where('statut', 'active'))->pluck('id'))
            ->with('eleve')
            ->orderBy('rang')
            ->get();

        return response()->json($bulletins);
    }

    public function show($id)
    {
        $bulletin = Bulletin::with(['eleve', 'periode', 'lignes.matiere'])->findOrFail($id);
        return response()->json($bulletin);
    }
}


