<?php

namespace App\Http\Controllers;

use App\Models\Enseignant;
use App\Models\Salaire;
use Illuminate\Http\Request;

class SalaireController extends Controller
{
    public function index(Request $request)
    {
        $etablissementId = $request->user()->etablissement_id;

        $query = Salaire::where('etablissement_id', $etablissementId)
            ->with('enseignant:id,nom,prenom,matricule');

        if ($request->filled('mois')) {
            $query->where('mois', $request->mois);
        }
        if ($request->filled('annee')) {
            $query->where('annee', $request->annee);
        }
        if ($request->filled('enseignant_id')) {
            $query->where('enseignant_id', $request->enseignant_id);
        }

        $salaires = $query->orderByDesc('annee')->orderByDesc('mois')->orderByDesc('id')->get();

        return response()->json($salaires);
    }

    public function store(Request $request)
    {
        $request->validate([
            'enseignant_id' => 'required|integer',
            'mois' => 'required|integer|between:1,12',
            'annee' => 'required|integer|between:2000,2100',
            'type_remuneration' => 'required|in:fixe,horaire',
            'salaire_base' => 'required_if:type_remuneration,fixe|nullable|numeric|min:0',
            'nb_heures' => 'required_if:type_remuneration,horaire|nullable|numeric|min:0|max:999.99',
            'taux_horaire' => 'required_if:type_remuneration,horaire|nullable|numeric|min:0',
            'nb_heures_supp' => 'nullable|numeric|min:0|max:999.99',
            'taux_heure_supp' => 'nullable|numeric|min:0',
            'moyen_paiement' => 'required|in:especes,mobile_money,virement,cheque',
            'observation' => 'nullable|string',
            'payer' => 'boolean',
        ]);

        $etablissementId = $request->user()->etablissement_id;

        $enseignant = Enseignant::where('etablissement_id', $etablissementId)->find($request->enseignant_id);
        if (!$enseignant) {
            return response()->json(['message' => 'Enseignant introuvable dans cet etablissement.'], 422);
        }

        $existe = Salaire::where('enseignant_id', $enseignant->id)
            ->where('mois', $request->mois)
            ->where('annee', $request->annee)
            ->exists();
        if ($existe) {
            return response()->json(['message' => 'Un salaire existe deja pour cet enseignant sur ce mois.'], 422);
        }

        $estFixe = $request->type_remuneration === 'fixe';
        $payer = $request->boolean('payer');

        $salaire = new Salaire([
            'enseignant_id' => $enseignant->id,
            'etablissement_id' => $etablissementId,
            'mois' => $request->mois,
            'annee' => $request->annee,
            'type_remuneration' => $request->type_remuneration,
            'salaire_base' => $estFixe ? $request->salaire_base : null,
            'nb_heures' => $estFixe ? null : $request->nb_heures,
            'taux_horaire' => $estFixe ? null : $request->taux_horaire,
            'nb_heures_supp' => $request->nb_heures_supp ?? 0,
            'taux_heure_supp' => $request->taux_heure_supp,
            'moyen_paiement' => $request->moyen_paiement,
            'observation' => $request->observation,
            'statut' => $payer ? 'paye' : 'en_attente',
            'date_paiement' => $payer ? now()->toDateString() : null,
        ]);
        $salaire->montant_net = $salaire->montant_calcule;
        $salaire->save();

        $salaire->update(['reference' => 'SAL-' . $salaire->annee . '-' . $salaire->id]);

        return response()->json($salaire->load('enseignant:id,nom,prenom,matricule'), 201);
    }

    public function show(Request $request, $id)
    {
        $etablissementId = $request->user()->etablissement_id;

        $salaire = Salaire::where('etablissement_id', $etablissementId)
            ->with([
                'enseignant.contratActif',
                'enseignant.affectations.classe',
                'enseignant.affectations.matiere',
                'etablissement:id,nom',
            ])
            ->findOrFail($id);

        return response()->json($salaire);
    }

    public function payer(Request $request, $id)
    {
        $etablissementId = $request->user()->etablissement_id;

        $salaire = Salaire::where('etablissement_id', $etablissementId)->findOrFail($id);

        if ($salaire->statut === 'paye') {
            return response()->json(['message' => 'Ce salaire est deja paye.'], 422);
        }

        $request->validate([
            'moyen_paiement' => 'nullable|in:especes,mobile_money,virement,cheque',
        ]);

        $salaire->update([
            'statut' => 'paye',
            'date_paiement' => now()->toDateString(),
            'moyen_paiement' => $request->moyen_paiement ?? $salaire->moyen_paiement,
        ]);

        return response()->json($salaire->load('enseignant:id,nom,prenom,matricule'));
    }

    public function destroy(Request $request, $id)
    {
        $etablissementId = $request->user()->etablissement_id;

        $salaire = Salaire::where('etablissement_id', $etablissementId)->findOrFail($id);

        if ($salaire->statut !== 'en_attente') {
            return response()->json(['message' => 'Impossible de supprimer un salaire deja paye.'], 422);
        }

        $salaire->delete();
        return response()->json(['message' => 'Salaire supprime.']);
    }
}
