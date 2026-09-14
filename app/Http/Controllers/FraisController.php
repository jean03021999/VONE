<?php

namespace App\Http\Controllers;

use App\Models\TypeFrais;
use App\Models\GrilleTarifaire;
use App\Models\FraisEleve;
use App\Models\Paiement;
use App\Models\Classe;
use Illuminate\Http\Request;

class FraisController extends Controller
{
    public function typesFrais(Request $request)
    {
        return response()->json(TypeFrais::where('etablissement_id', $request->user()->etablissement_id)->get());
    }

    public function storeTypeFrais(Request $request)
    {
        $request->validate(['nom' => 'required|string']);
        $type = TypeFrais::firstOrCreate(['etablissement_id' => $request->user()->etablissement_id, 'nom' => $request->nom]);
        return response()->json($type, 201);
    }

    public function grilles(Request $request)
    {
        $grilles = GrilleTarifaire::where('etablissement_id', $request->user()->etablissement_id)
            ->with(['classe', 'typeFrais', 'echeances'])
            ->get()
            ->map(function ($grille) {
                $nombreElevesClasse = \App\Models\Eleve::where('classe_id', $grille->classe_id)->count();
                $nombreCouverts = FraisEleve::where('type_frais_id', $grille->type_frais_id)
                    ->where('session_scolaire_id', $grille->session_scolaire_id)
                    ->whereHas('eleve', fn($q) => $q->where('classe_id', $grille->classe_id))
                    ->count();

                $grille->nombre_eleves_classe = $nombreElevesClasse;
                $grille->nombre_eleves_couverts = $nombreCouverts;
                return $grille;
            });

        return response()->json($grilles);
    }

    public function storeGrille(Request $request)
    {
        $request->validate([
            'classe_id' => 'required|exists:classes,id',
            'type_frais_id' => 'required|exists:types_frais,id',
            'montant' => 'required|numeric',
            'echeances' => 'required|array|min:1',
            'echeances.*.libelle' => 'required|string',
            'echeances.*.montant' => 'required|numeric',
            'echeances.*.date_limite' => 'required|date',
        ]);

        $etablissementId = $request->user()->etablissement_id;
        $classe = Classe::findOrFail($request->classe_id);

        $grilleExistante = GrilleTarifaire::where('classe_id', $request->classe_id)
            ->where('type_frais_id', $request->type_frais_id)
            ->where('session_scolaire_id', $classe->session_scolaire_id)
            ->first();

        if ($grilleExistante) {
            return response()->json([
                'message' => 'Une grille tarifaire existe deja pour cette classe et ce type de frais sur cette session scolaire.',
            ], 422);
        }

        $grille = GrilleTarifaire::create([
            'etablissement_id' => $etablissementId,
            'session_scolaire_id' => $classe->session_scolaire_id,
            'classe_id' => $request->classe_id,
            'type_frais_id' => $request->type_frais_id,
            'montant' => $request->montant,
        ]);

        foreach ($request->echeances as $ech) {
            $grille->echeances()->create($ech);
        }

        $this->appliquerGrilleAuxEleves($grille);

        return response()->json($grille->load('echeances'), 201);
    }

    /**
     * Cree les frais et echeances manquants pour les eleves de la classe
     * qui n'ont pas encore de FraisEleve pour cette grille (nouveaux inscrits notamment).
     */
    private function appliquerGrilleAuxEleves(GrilleTarifaire $grille): int
    {
        $grille->loadMissing('echeances');
        $crees = 0;

        $eleves = \App\Models\Eleve::whereHas('inscriptionActive', fn($q) => $q->where('classe_id', $grille->classe_id)->where('statut', 'active'))->get();
        foreach ($eleves as $eleve) {
            $existe = FraisEleve::where('eleve_id', $eleve->id)
                ->where('type_frais_id', $grille->type_frais_id)
                ->where('session_scolaire_id', $grille->session_scolaire_id)->exists();
            if ($existe) continue;

            $fraisEleve = FraisEleve::create([
                'eleve_id' => $eleve->id,
                'type_frais_id' => $grille->type_frais_id,
                'session_scolaire_id' => $grille->session_scolaire_id,
                'montant_total' => $grille->montant,
                'montant_original' => $grille->montant,
            ]);

            foreach ($grille->echeances as $ech) {
                $fraisEleve->echeances()->create([
                    'libelle' => $ech->libelle,
                    'montant' => $ech->montant,
                    'date_limite' => $ech->date_limite,
                ]);
            }
            $crees++;
        }

        return $crees;
    }

    public function synchroniserGrille(Request $request, $id)
    {
        $grille = GrilleTarifaire::where('etablissement_id', $request->user()->etablissement_id)
            ->findOrFail($id);

        $crees = $this->appliquerGrilleAuxEleves($grille);

        return response()->json([
            'message' => $crees > 0
                ? "{$crees} élève(s) synchronisé(s) avec cette grille tarifaire."
                : "Tous les élèves de cette classe sont déjà à jour avec cette grille.",
            'crees' => $crees,
        ]);
    }

    public function suiviEleve(Request $request, $eleveId)
    {
        $eleve = \App\Models\Eleve::where('etablissement_id', $request->user()->etablissement_id)->findOrFail($eleveId);

        $fraisEleves = FraisEleve::where('eleve_id', $eleve->id)
            ->with(['typeFrais', 'echeances.paiements'])->get()
            ->map(fn($fe) => [
                'id' => $fe->id,
                'type_frais' => $fe->typeFrais->nom,
                'montant_total' => $fe->montant_total,
                'echeances' => $fe->echeances->map(fn($ech) => [
                    'id' => $ech->id,
                    'libelle' => $ech->libelle,
                    'montant' => $ech->montant,
                    'date_limite' => $ech->date_limite,
                    'montant_paye' => $ech->montant_paye,
                    'solde' => $ech->solde,
                    'statut' => $ech->statut,
                ]),
            ]);

        return response()->json(['eleve' => ['id' => $eleve->id, 'nom' => $eleve->nom, 'prenom' => $eleve->prenom], 'frais' => $fraisEleves]);
    }

    public function paiements(Request $request)
    {
        $etablissementId = $request->user()->etablissement_id;

        $paiements = Paiement::whereHas('eleve', function ($q) use ($etablissementId) {
            $q->where('etablissement_id', $etablissementId);
        })
            ->with('eleve.classe')
            ->orderByDesc('date_paiement')
            ->orderByDesc('id')
            ->get()
            ->map(fn($p) => [
                'id' => $p->id,
                'montant' => $p->montant,
                'moyen_paiement' => $p->moyen_paiement,
                'date_paiement' => $p->date_paiement,
                'heure' => $p->created_at?->format('H:i'),
                'libelle' => $p->libelle,
                'eleve' => $p->eleve ? [
                    'id' => $p->eleve->id,
                    'nom_complet' => "{$p->eleve->nom} {$p->eleve->prenom}",
                    'matricule' => $p->eleve->matricule,
                    'classe' => $p->eleve->classe?->nom,
                ] : null,
            ]);

        return response()->json($paiements);
    }

    public function paiementsRecent(Request $request)
    {
        $etablissementId = $request->user()->etablissement_id;

        $paiements = Paiement::whereHas('eleve', function ($q) use ($etablissementId) {
            $q->where('etablissement_id', $etablissementId);
        })
            ->with('eleve.classe')
            ->orderByDesc('date_paiement')
            ->orderByDesc('id')
            ->limit(5)
            ->get()
            ->map(fn($p) => [
                'id' => $p->id,
                'montant' => $p->montant,
                'periode' => $p->libelle,
                'date_paiement' => $p->date_paiement,
                'heure' => $p->created_at?->format('H:i'),
                'eleve' => $p->eleve ? [
                    'id' => $p->eleve->id,
                    'nom_complet' => "{$p->eleve->nom} {$p->eleve->prenom}",
                    'classe' => $p->eleve->classe?->nom,
                ] : null,
            ]);

        return response()->json($paiements);
    }

    public function statsParClasse(Request $request)
    {
        $etablissementId = $request->user()->etablissement_id;

        $classes = Classe::where('etablissement_id', $etablissementId)
            ->with('eleves.fraisEleves.echeances')
            ->orderBy('nom')
            ->get();

        $resultat = $classes->map(function ($classe) {
            $montantTotal = 0;
            $montantEncaisse = 0;
            $nombreSoldes = 0;
            $nombreEnRetard = 0;
            $nombreSansFrais = 0;

            foreach ($classe->eleves as $eleve) {
                $echeances = $eleve->fraisEleves->flatMap->echeances;

                if ($echeances->isEmpty()) {
                    $nombreSansFrais++;
                    continue;
                }

                $montantTotal += $echeances->sum('montant');
                $montantEncaisse += $echeances->sum('montant_paye');

                $aDuRetard = $echeances->contains(fn($e) => $e->statut === 'en_retard');
                $estSolde = $echeances->every(fn($e) => $e->solde <= 0);

                if ($aDuRetard) {
                    $nombreEnRetard++;
                } elseif ($estSolde) {
                    $nombreSoldes++;
                }
            }

            return [
                'classe_id' => $classe->id,
                'classe' => $classe->nom,
                'niveau' => $classe->niveau,
                'nombre_eleves' => $classe->eleves->count(),
                'montant_total' => $montantTotal,
                'montant_encaisse' => $montantEncaisse,
                'nombre_soldes' => $nombreSoldes,
                'nombre_en_retard' => $nombreEnRetard,
                'nombre_sans_frais' => $nombreSansFrais,
            ];
        })->values();

        return response()->json($resultat);
    }

    public function enregistrerPaiement(Request $request)
    {
        $request->validate([
            'echeance_eleve_id' => 'required|exists:echeances_eleves,id',
            'montant' => 'required|numeric|min:1',
            'moyen_paiement' => 'required|in:especes,mobile_money,virement,cheque',
            'date_paiement' => 'required|date',
        ]);

        $echeance = \App\Models\EcheanceEleve::findOrFail($request->echeance_eleve_id);

        if ($request->montant > $echeance->solde) {
            return response()->json(['message' => 'Le montant depasse le solde restant (' . $echeance->solde . ' GNF).'], 422);
        }

        $paiement = Paiement::create([
            'eleve_id' => $echeance->fraisEleve->eleve_id,
            'echeance_eleve_id' => $echeance->id,
            'libelle' => $echeance->libelle,
            'montant' => $request->montant,
            'moyen_paiement' => $request->moyen_paiement,
            'date_paiement' => $request->date_paiement,
        ]);

        return response()->json($paiement, 201);
    }
}

