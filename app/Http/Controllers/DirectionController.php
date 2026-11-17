<?php

namespace App\Http\Controllers;

use App\Models\Bulletin;
use App\Models\Classe;
use App\Models\EmploiDuTemps;
use App\Models\Evaluation;
use App\Models\Periode;
use App\Models\SessionScolaire;
use Illuminate\Http\Request;

/**
 * Espace Directeur : synthese pedagogique du tableau de bord, en une requete.
 * Periode courante, classes (effectif, moyenne generale des bulletins de la periode, bulletins
 * generes, creneaux d'emploi du temps), evaluations par statut et cours du jour.
 */
class DirectionController extends Controller
{
    private const JOURS = [1 => 'lundi', 'mardi', 'mercredi', 'jeudi', 'vendredi', 'samedi'];

    public function synthese(Request $request)
    {
        $etablissementId = $request->user()->etablissement_id;
        $session = SessionScolaire::where('etablissement_id', $etablissementId)->where('est_active', true)->first();

        // Periode courante : l'ouverte qui contient aujourd'hui, sinon la derniere ouverte, sinon la derniere.
        $periodes = Periode::where('etablissement_id', $etablissementId)
            ->when($session, fn ($q) => $q->where('session_scolaire_id', $session->id))
            ->orderBy('date_debut')
            ->get();
        $aujourdhui = today()->toDateString();
        $courante = $periodes->first(fn ($p) => $p->statut === 'ouverte'
                && substr((string) $p->date_debut, 0, 10) <= $aujourdhui
                && substr((string) $p->date_fin, 0, 10) >= $aujourdhui)
            ?? $periodes->where('statut', 'ouverte')->last()
            ?? $periodes->last();

        $classes = Classe::where('etablissement_id', $etablissementId)->ordonneesPedagogiquement()->get();

        $creneaux = EmploiDuTemps::join('affectations', 'affectations.id', '=', 'emploi_du_temps.affectation_id')
            ->whereIn('affectations.classe_id', $classes->pluck('id'))
            ->groupBy('affectations.classe_id')
            ->selectRaw('affectations.classe_id, COUNT(*) as nombre')
            ->pluck('nombre', 'classe_id');

        $lignes = $classes->map(function (Classe $classe) use ($courante, $creneaux) {
            $eleveIds = $classe->eleves()->pluck('eleves.id');
            $bulletins = $courante
                ? Bulletin::where('periode_id', $courante->id)->where('statut', 'courante')->whereIn('eleve_id', $eleveIds)->pluck('moyenne')
                : collect();

            return [
                'id' => $classe->id,
                'nom' => $classe->nom,
                'niveau' => $classe->niveau,
                'eleves' => $eleveIds->count(),
                'bulletins' => $bulletins->count(),
                'moyenne' => $bulletins->isNotEmpty() ? round($bulletins->avg(fn ($m) => (float) $m), 2) : null,
                'creneaux' => (int) ($creneaux[$classe->id] ?? 0),
            ];
        })->values();

        $evaluations = Evaluation::whereHas('affectation.classe', fn ($q) => $q->where('etablissement_id', $etablissementId))
            ->selectRaw('statut, COUNT(*) as nombre')
            ->groupBy('statut')
            ->pluck('nombre', 'statut');

        $jour = self::JOURS[today()->dayOfWeekIso] ?? null; // dimanche : pas de cours
        $coursDuJour = $jour
            ? EmploiDuTemps::where('jour', $jour)
                ->whereHas('affectation.classe', fn ($q) => $q->where('etablissement_id', $etablissementId))
                ->with(['affectation.classe', 'affectation.matiere', 'affectation.enseignant'])
                ->orderBy('heure_debut')
                ->get()
                ->map(fn ($c) => [
                    'id' => $c->id,
                    'heure_debut' => substr((string) $c->heure_debut, 0, 5),
                    'heure_fin' => substr((string) $c->heure_fin, 0, 5),
                    'classe' => $c->affectation?->classe?->nom,
                    'matiere' => $c->affectation?->matiere?->nom,
                    'enseignant' => trim(($c->affectation?->enseignant?->prenom ?? '') . ' ' . ($c->affectation?->enseignant?->nom ?? '')),
                ])
                ->values()
            : collect();

        return response()->json([
            'session' => $session?->libelle,
            'periode' => $courante ? ['id' => $courante->id, 'libelle' => $courante->libelle, 'date_fin' => substr((string) $courante->date_fin, 0, 10)] : null,
            'classes' => $lignes,
            'evaluations' => [
                'brouillon' => (int) ($evaluations['brouillon'] ?? 0),
                'soumis' => (int) ($evaluations['soumis'] ?? 0),
                'valide' => (int) ($evaluations['valide'] ?? 0),
                'rejete' => (int) ($evaluations['rejete'] ?? 0),
                'publie' => (int) ($evaluations['publie'] ?? 0),
            ],
            'jour' => $jour,
            'cours_du_jour' => $coursDuJour,
        ]);
    }
}
