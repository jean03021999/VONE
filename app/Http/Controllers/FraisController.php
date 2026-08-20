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
            ->with(['classe', 'typeFrais', 'echeances'])->get();
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

        $eleves = \App\Models\Eleve::where('classe_id', $classe->id)->get();
        foreach ($eleves as $eleve) {
            $existe = FraisEleve::where('eleve_id', $eleve->id)
                ->where('type_frais_id', $request->type_frais_id)
                ->where('session_scolaire_id', $classe->session_scolaire_id)->exists();
            if ($existe) continue;

            $fraisEleve = FraisEleve::create([
                'eleve_id' => $eleve->id,
                'type_frais_id' => $request->type_frais_id,
                'session_scolaire_id' => $classe->session_scolaire_id,
                'montant_total' => $request->montant,
                'montant_original' => $request->montant,
            ]);

            foreach ($grille->echeances as $ech) {
                $fraisEleve->echeances()->create([
                    'libelle' => $ech->libelle,
                    'montant' => $ech->montant,
                    'date_limite' => $ech->date_limite,
                ]);
            }
        }

        return response()->json($grille->load('echeances'), 201);
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

