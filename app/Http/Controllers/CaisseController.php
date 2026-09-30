<?php

namespace App\Http\Controllers;

use App\Models\Depense;
use App\Models\Paiement;
use App\Models\Salaire;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

// Caisse de l'etablissement : l'argent encaisse (paiements des familles) finance les salaires et
// les autres depenses. Solde = encaissements - salaires verses - depenses, global et par moyen de
// paiement (especes en caisse, Mobile Money, banque). Paiements et depenses annules exclus.
class CaisseController extends Controller
{
    public const CATEGORIES = [
        'fournitures' => 'Fournitures & matériel',
        'electricite_eau' => 'Électricité & eau',
        'entretien' => 'Entretien & réparations',
        'transport' => 'Transport & carburant',
        'communication' => 'Communication & internet',
        'loyer' => 'Loyer & charges',
        'evenement' => 'Événements & fêtes',
        'administratif' => 'Frais administratifs & bancaires',
        'autre' => 'Autre dépense',
    ];

    private const MOYENS = ['especes', 'mobile_money', 'virement', 'cheque'];

    /**
     * Synthese de caisse : totaux sur la periode demandee (debut / fin, optionnels) et solde
     * disponible a ce jour, global et par moyen de paiement.
     */
    public function synthese(Request $request)
    {
        $etablissementId = $request->user()->etablissement_id;
        $debut = $request->query('debut');
        $fin = $request->query('fin');

        $encaissements = fn () => Paiement::valides()->whereHas('eleve', fn ($q) => $q->where('etablissement_id', $etablissementId));
        $salaires = fn () => Salaire::where('etablissement_id', $etablissementId)->where('statut', 'paye');
        $depenses = fn () => Depense::valides()->where('etablissement_id', $etablissementId);

        $parMoyen = function ($requete, string $colonneMontant) {
            return $requete->groupBy('moyen_paiement')->selectRaw("moyen_paiement, SUM({$colonneMontant}) as total")->pluck('total', 'moyen_paiement');
        };
        $entrees = $parMoyen($encaissements(), 'montant');
        $sortiesSalaires = $parMoyen($salaires(), 'montant_net');
        $sortiesDepenses = $parMoyen($depenses(), 'montant');

        $soldeParMoyen = collect(self::MOYENS)->mapWithKeys(fn ($m) => [$m => [
            'entrees' => (float) ($entrees[$m] ?? 0),
            'salaires' => (float) ($sortiesSalaires[$m] ?? 0),
            'depenses' => (float) ($sortiesDepenses[$m] ?? 0),
            'solde' => (float) ($entrees[$m] ?? 0) - (float) ($sortiesSalaires[$m] ?? 0) - (float) ($sortiesDepenses[$m] ?? 0),
        ]]);

        // Periode : filtre sur la date de chaque mouvement.
        $periode = function ($requete, string $colonneDate) use ($debut, $fin) {
            if ($debut) {
                $requete->whereDate($colonneDate, '>=', $debut);
            }
            if ($fin) {
                $requete->whereDate($colonneDate, '<=', $fin);
            }

            return $requete;
        };
        $entreesPeriode = (float) $periode($encaissements(), 'date_paiement')->sum('montant');
        $salairesPeriode = (float) $periode($salaires(), 'date_paiement')->sum('montant_net');
        $depensesPeriode = (float) $periode($depenses(), 'date_depense')->sum('montant');

        return response()->json([
            'solde' => $soldeParMoyen->sum('solde'),
            'total_entrees' => $soldeParMoyen->sum('entrees'),
            'total_salaires' => $soldeParMoyen->sum('salaires'),
            'total_depenses' => $soldeParMoyen->sum('depenses'),
            'par_moyen' => $soldeParMoyen,
            'periode' => [
                'debut' => $debut,
                'fin' => $fin,
                'entrees' => $entreesPeriode,
                'salaires' => $salairesPeriode,
                'depenses' => $depensesPeriode,
                'solde' => $entreesPeriode - $salairesPeriode - $depensesPeriode,
            ],
            'categories' => self::CATEGORIES,
        ]);
    }

    /** Sorties de caisse : salaires verses et depenses (annulees comprises, marquees), les plus recentes d'abord. */
    public function sorties(Request $request)
    {
        $etablissementId = $request->user()->etablissement_id;

        $salaires = Salaire::where('etablissement_id', $etablissementId)
            ->where('statut', 'paye')
            ->with(['enseignant:id,nom,prenom,matricule', 'caissier:id,name'])
            ->get()
            ->map(fn ($s) => [
                'cle' => "salaire-{$s->id}",
                'id' => $s->id,
                'source' => 'salaire',
                'date' => $s->date_paiement?->toDateString() ?? (string) $s->date_paiement,
                'categorie' => 'salaire',
                'libelle' => 'Salaire ' . \Illuminate\Support\Carbon::create()->month($s->mois)->locale('fr')->translatedFormat('F') . " {$s->annee}",
                'beneficiaire' => trim(($s->enseignant?->prenom ?? '') . ' ' . ($s->enseignant?->nom ?? '')),
                'montant' => (float) $s->montant_net,
                'moyen_paiement' => $s->moyen_paiement,
                'reference' => $s->reference,
                'par' => $s->caissier?->name,
                'annule' => false,
            ]);

        $depenses = Depense::where('etablissement_id', $etablissementId)
            ->with(['auteur:id,name', 'annulateur:id,name'])
            ->get()
            ->map(fn ($d) => [
                'cle' => "depense-{$d->id}",
                'id' => $d->id,
                'source' => 'depense',
                'date' => $d->date_depense?->toDateString(),
                'categorie' => $d->categorie,
                'libelle' => $d->libelle,
                'beneficiaire' => $d->beneficiaire,
                'montant' => (float) $d->montant,
                'moyen_paiement' => $d->moyen_paiement,
                'reference' => $d->reference,
                'observation' => $d->observation,
                'par' => $d->auteur?->name,
                'annule' => $d->annule_le !== null,
                'annule_par' => $d->annulateur?->name,
                'motif_annulation' => $d->motif_annulation,
            ]);

        return response()->json(
            $salaires->concat($depenses)->sortByDesc(fn ($x) => $x['date'] . str_pad((string) $x['id'], 10, '0', STR_PAD_LEFT))->values()
        );
    }

    public function storeDepense(Request $request)
    {
        $request->validate([
            'date_depense' => 'required|date|before_or_equal:today',
            'categorie' => 'required|in:' . implode(',', array_keys(self::CATEGORIES)),
            'libelle' => 'required|string|max:200',
            'montant' => 'required|numeric|min:1',
            'moyen_paiement' => 'required|in:' . implode(',', self::MOYENS),
            'beneficiaire' => 'nullable|string|max:150',
            'observation' => 'nullable|string|max:1000',
        ], [
            'date_depense.before_or_equal' => 'La date de la dépense ne peut pas être dans le futur.',
            'montant.min' => 'Le montant doit être positif.',
        ]);

        $etablissementId = $request->user()->etablissement_id;
        $depense = DB::transaction(function () use ($request, $etablissementId) {
            $d = Depense::create([
                'etablissement_id' => $etablissementId,
                'date_depense' => $request->date_depense,
                'categorie' => $request->categorie,
                'libelle' => trim($request->libelle),
                'montant' => $request->montant,
                'moyen_paiement' => $request->moyen_paiement,
                'beneficiaire' => $request->beneficiaire ?: null,
                'observation' => $request->observation ?: null,
                'enregistre_par' => $request->user()->id,
            ]);
            $d->update(['reference' => 'DEP-' . substr((string) $request->date_depense, 0, 4) . '-' . $d->id]);

            return $d;
        });

        return response()->json([
            'message' => 'Dépense ' . $depense->reference . ' enregistrée : ' . number_format((float) $depense->montant, 0, ',', ' ') . ' GNF.',
            'depense' => $depense,
        ], 201);
    }

    /** Annule une depense saisie par erreur : elle reste visible, barree, mais ne compte plus. */
    public function annulerDepense(Request $request, $id)
    {
        $request->validate(['motif' => 'required|string|min:3|max:255'], [
            'motif.required' => "Indiquez le motif de l'annulation.",
            'motif.min' => "Indiquez le motif de l'annulation.",
        ]);
        $depense = Depense::where('etablissement_id', $request->user()->etablissement_id)->findOrFail($id);
        if ($depense->annule_le) {
            return response()->json(['message' => 'Cette dépense est déjà annulée.'], 422);
        }

        $depense->update([
            'annule_le' => now(),
            'annule_par' => $request->user()->id,
            'motif_annulation' => trim($request->motif),
        ]);

        return response()->json(['message' => "Dépense {$depense->reference} annulée."]);
    }
}
