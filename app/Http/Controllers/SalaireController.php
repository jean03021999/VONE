<?php

namespace App\Http\Controllers;

use App\Services\Numerotation;
use App\Models\Enseignant;
use App\Models\Salaire;
use Illuminate\Http\Request;

class SalaireController extends Controller
{
    public function index(Request $request)
    {
        $etablissementId = $request->user()->etablissement_id;

        $query = Salaire::where('etablissement_id', $etablissementId)
            ->with(['enseignant:id,nom,prenom,matricule', 'caissier:id,name', 'annulateur:id,name']);

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
            'confirmer_avance' => 'boolean',
        ]);

        $etablissementId = $request->user()->etablissement_id;

        $enseignant = Enseignant::where('etablissement_id', $etablissementId)->find($request->enseignant_id);
        if (!$enseignant) {
            return response()->json(['message' => 'Enseignant introuvable dans cet etablissement.'], 422);
        }

        $existe = Salaire::where('enseignant_id', $enseignant->id)
            ->where('mois', $request->mois)
            ->where('annee', $request->annee)
            ->where('statut', '!=', 'annule')
            ->exists();
        if ($existe) {
            return response()->json(['message' => 'Un salaire existe deja pour cet enseignant sur ce mois.'], 422);
        }

        $estFixe = $request->type_remuneration === 'fixe';
        $payer = $request->boolean('payer');
        if ($refus = $this->controlerContrat($request, $enseignant)) {
            return $refus;
        }
        if ($payer && ($refus = $this->controlerAvance($request, (int) $request->mois, (int) $request->annee))) {
            return $refus;
        }

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
            'caissier_id' => $payer ? $request->user()->id : null,
        ]);
        $salaire->montant_net = $salaire->montant_calcule;
        $salaire->save();

        $salaire->update(['reference' => Numerotation::referenceOperation($salaire->etablissement_id, 'SAL', $salaire->annee, $salaire->id)]);

        return response()->json($salaire->load(['enseignant:id,nom,prenom,matricule', 'caissier:id,name']), 201);
    }

    public function show(Request $request, $id)
    {
        $etablissementId = $request->user()->etablissement_id;

        $salaire = Salaire::where('etablissement_id', $etablissementId)
            ->with([
                'enseignant.contratActif',
                'enseignant.affectations.classe',
                'enseignant.affectations.matiere',
                'etablissement',
                'caissier:id,name',
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
        if ($salaire->statut === 'annule') {
            return response()->json(['message' => 'Ce salaire est annulé.'], 422);
        }

        $request->validate([
            'moyen_paiement' => 'nullable|in:especes,mobile_money,virement,cheque',
            'confirmer_avance' => 'boolean',
        ]);
        if ($refus = $this->controlerAvance($request, $salaire->mois, $salaire->annee)) {
            return $refus;
        }

        $salaire->update([
            'statut' => 'paye',
            'date_paiement' => now()->toDateString(),
            'moyen_paiement' => $request->moyen_paiement ?? $salaire->moyen_paiement,
            'caissier_id' => $request->user()->id,
        ]);

        return response()->json($salaire->load(['enseignant:id,nom,prenom,matricule', 'caissier:id,name']));
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


    /** Corrige un salaire encore en attente ; un salaire paye ne se modifie plus. */
    public function update(Request $request, $id)
    {
        $etablissementId = $request->user()->etablissement_id;
        $salaire = Salaire::where('etablissement_id', $etablissementId)->findOrFail($id);

        if ($salaire->statut !== 'en_attente') {
            return response()->json(['message' => $salaire->statut === 'annule'
                ? 'Un salaire annulé ne peut plus être modifié.'
                : 'Un salaire déjà payé ne peut plus être modifié : annulez-le puis saisissez le bon montant.'], 422);
        }

        $request->validate([
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
        ]);

        $doublon = Salaire::where('enseignant_id', $salaire->enseignant_id)
            ->where('mois', $request->mois)
            ->where('annee', $request->annee)
            ->where('id', '!=', $salaire->id)
            ->where('statut', '!=', 'annule')
            ->exists();
        if ($doublon) {
            return response()->json(['message' => 'Un salaire existe deja pour cet enseignant sur ce mois.'], 422);
        }
        if ($refus = $this->controlerContrat($request, $salaire->enseignant)) {
            return $refus;
        }

        $estFixe = $request->type_remuneration === 'fixe';
        $salaire->fill([
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
        ]);
        $salaire->montant_net = $salaire->montant_calcule;
        // Reference deja attribuee (et deja sur la fiche de paie) conservee.
        $salaire->reference = $salaire->reference ?: Numerotation::referenceOperation($salaire->etablissement_id, 'SAL', $salaire->annee, $salaire->id);
        $salaire->save();

        return response()->json($salaire->load(['enseignant:id,nom,prenom,matricule', 'caissier:id,name']));
    }

    /**
     * Annule un salaire paye par erreur : il reste visible (barre, auteur, motif) mais ne compte plus
     * dans la caisse ni les rapports ; le bon salaire du mois peut ensuite etre saisi.
     */
    public function annuler(Request $request, $id)
    {
        $request->validate(['motif' => 'required|string|min:3|max:255'], [
            'motif.required' => "Indiquez le motif de l'annulation.",
            'motif.min' => "Indiquez le motif de l'annulation.",
        ]);
        $salaire = Salaire::where('etablissement_id', $request->user()->etablissement_id)->findOrFail($id);
        if ($salaire->statut !== 'paye') {
            return response()->json(['message' => $salaire->statut === 'annule'
                ? 'Ce salaire est déjà annulé.'
                : "Ce salaire n'est pas payé : modifiez-le ou supprimez-le."], 422);
        }

        $salaire->update([
            'statut' => 'annule',
            'annule_le' => now(),
            'annule_par' => $request->user()->id,
            'motif_annulation' => trim($request->motif),
        ]);

        return response()->json([
            'message' => "Salaire {$salaire->reference} annulé : " . number_format((float) $salaire->montant_net, 0, ',', ' ') . ' GNF reviennent dans la caisse.',
            'salaire' => $salaire->load(['enseignant:id,nom,prenom,matricule', 'caissier:id,name', 'annulateur:id,name']),
        ]);
    }

    /**
     * Controle du salaire par rapport au contrat de l'enseignant :
     * - le mois doit etre couvert par son contrat actif (pas avant le debut ni apres la fin) ;
     * - tout ecart au contrat (type de remuneration, salaire de base, taux d'heure sup) doit etre
     *   justifie dans l'observation.
     */
    private function controlerContrat(Request $request, Enseignant $enseignant)
    {
        $nom = trim("{$enseignant->prenom} {$enseignant->nom}");
        $contrat = $enseignant->contratActif;
        $periode = \Illuminate\Support\Carbon::create((int) $request->annee, (int) $request->mois, 1);
        $libelleMois = ucfirst($periode->locale('fr')->translatedFormat('F Y'));
        if (! $contrat) {
            return response()->json(['message' => "{$nom} n'a pas de contrat actif : enregistrez son contrat avant de saisir un salaire.", 'code' => 'sans_contrat'], 422);
        }
        $debut = $contrat->date_debut ? \Illuminate\Support\Carbon::parse($contrat->date_debut) : null;
        $fin = $contrat->date_fin ? \Illuminate\Support\Carbon::parse($contrat->date_fin) : null;
        // Mois entierement avant le debut du contrat, ou commencant apres sa fin.
        if (($debut && $periode->copy()->endOfMonth()->lt($debut)) || ($fin && $periode->gt($fin))) {
            return response()->json([
                'message' => "{$libelleMois} n'est pas couvert par le contrat de {$nom} (du " . ($debut?->format('d/m/Y') ?? '?') . ($fin ? ' au ' . $fin->format('d/m/Y') : '') . ').',
                'code' => 'hors_contrat',
            ], 422);
        }

        $attendu = $contrat->type === 'vacataire' ? 'horaire' : 'fixe';
        $ecarts = [];
        if ($request->type_remuneration !== $attendu) {
            $ecarts[] = 'rémunération ' . ($request->type_remuneration === 'horaire' ? 'à l\'heure' : 'fixe') . ' pour un contrat ' . strtoupper($contrat->type);
        }
        if ($request->type_remuneration === 'fixe' && $contrat->salaire_base !== null && abs((float) $request->salaire_base - (float) $contrat->salaire_base) >= 1) {
            $ecarts[] = 'salaire de base ' . number_format((float) $request->salaire_base, 0, ',', ' ') . ' GNF au lieu de ' . number_format((float) $contrat->salaire_base, 0, ',', ' ') . ' GNF (contrat)';
        }
        if ((float) $request->nb_heures_supp > 0 && $contrat->taux_horaire_heures_sup !== null && abs((float) $request->taux_heure_supp - (float) $contrat->taux_horaire_heures_sup) >= 1) {
            $ecarts[] = "taux d'heure supplémentaire " . number_format((float) $request->taux_heure_supp, 0, ',', ' ') . ' GNF au lieu de ' . number_format((float) $contrat->taux_horaire_heures_sup, 0, ',', ' ') . ' GNF (contrat)';
        }
        if ($ecarts && mb_strlen(trim((string) $request->observation)) < 5) {
            return response()->json([
                'message' => 'Écart avec le contrat de ' . $nom . ' : ' . implode(' ; ', $ecarts) . '. Justifiez cet écart dans l\'observation (prime, rattrapage, avenant…).',
                'code' => 'ecart_contrat',
            ], 422);
        }

        return null;
    }

    /** Payer un mois pas encore commence (avance) demande une confirmation explicite. */
    private function controlerAvance(Request $request, int $mois, int $annee)
    {
        $periode = sprintf('%04d-%02d', $annee, $mois);
        if ($periode > today()->format('Y-m') && ! $request->boolean('confirmer_avance')) {
            $libelle = ucfirst(\Illuminate\Support\Carbon::create($annee, $mois, 1)->locale('fr')->translatedFormat('F Y'));
            return response()->json([
                'message' => "Le mois de {$libelle} n'a pas encore commencé : payer maintenant est une avance sur salaire. Confirmez-vous ce paiement d'avance ?",
                'code' => 'avance',
            ], 422);
        }

        return null;
    }
}
