<?php

namespace App\Http\Controllers;

use App\Models\Depense;
use App\Models\JustificatifDepense;
use App\Models\Paiement;
use App\Models\Salaire;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

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

    // Pieces justificatives : photo de la facture / du recu ou PDF, 5 Mo max, 5 par envoi.
    private const REGLE_FICHIER = 'file|mimes:jpg,jpeg,png,webp,pdf|max:5120';
    private const MESSAGES_FICHIER = [
        'justificatifs.*.mimes' => 'Pièce justificative : formats acceptés JPG, PNG, WebP ou PDF.',
        'justificatifs.*.max' => 'Chaque pièce justificative doit faire 5 Mo au maximum.',
        'justificatifs.max' => '5 fichiers au maximum par envoi.',
    ];

    /**
     * Synthese de caisse : totaux sur la periode demandee (debut / fin, optionnels) et solde
     * disponible a ce jour, global et par moyen de paiement.
     */
    public function synthese(Request $request)
    {
        $etablissementId = $request->user()->etablissement_id;
        $debut = $request->query('debut');
        $fin = $request->query('fin');

        $encaissements = fn () => Paiement::encaisses()->whereHas('eleve', fn ($q) => $q->where('etablissement_id', $etablissementId));
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

        $aJustifier = $depenses()->whereDoesntHave('justificatifs');
        $parCategorie = $periode($depenses(), 'date_depense')->groupBy('categorie')
            ->selectRaw('categorie, COUNT(*) as nombre, SUM(montant) as total')->get()
            ->map(fn ($c) => ['categorie' => $c->categorie, 'libelle' => self::CATEGORIES[$c->categorie] ?? $c->categorie, 'nombre' => (int) $c->nombre, 'total' => (float) $c->total])
            ->sortByDesc('total')->values();

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
            'depenses_par_categorie' => $parCategorie,
            'a_justifier' => ['nombre' => (clone $aJustifier)->count(), 'montant' => (float) $aJustifier->sum('montant')],
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
            ->withCount('justificatifs')
            ->get()
            ->map(fn ($d) => ['cle' => "depense-{$d->id}", 'source' => 'depense'] + $this->formaterDepense($d));

        return response()->json(
            $salaires->concat($depenses)->sortByDesc(fn ($x) => $x['date'] . str_pad((string) $x['id'], 10, '0', STR_PAD_LEFT))->values()
        );
    }

    /**
     * Module Depenses : liste avec l'etat de la justification (pieces jointes). Filtres : debut,
     * fin, categorie, etat (a_justifier | justifiees | annulees).
     */
    public function depenses(Request $request)
    {
        $requete = Depense::where('etablissement_id', $request->user()->etablissement_id)
            ->with(['auteur:id,name', 'annulateur:id,name', 'justificatifs.auteur:id,name'])
            ->withCount('justificatifs')
            ->orderByDesc('date_depense')->orderByDesc('id');
        if ($request->filled('debut')) {
            $requete->whereDate('date_depense', '>=', $request->debut);
        }
        if ($request->filled('fin')) {
            $requete->whereDate('date_depense', '<=', $request->fin);
        }
        if ($request->filled('categorie')) {
            $requete->where('categorie', $request->categorie);
        }
        match ($request->query('etat')) {
            'a_justifier' => $requete->whereNull('annule_le')->whereDoesntHave('justificatifs'),
            'justifiees' => $requete->whereNull('annule_le')->whereHas('justificatifs'),
            'annulees' => $requete->whereNotNull('annule_le'),
            default => null,
        };

        return response()->json([
            'depenses' => $requete->get()->map(fn ($d) => $this->formaterDepense($d, true))->values(),
            'categories' => self::CATEGORIES,
        ]);
    }

    public function storeDepense(Request $request)
    {
        $request->validate($this->reglesDepense() + [
            'justificatifs' => 'nullable|array|max:5',
            'justificatifs.*' => self::REGLE_FICHIER,
        ], $this->messagesDepense());

        $etablissementId = $request->user()->etablissement_id;
        $depense = DB::transaction(function () use ($request, $etablissementId) {
            $d = Depense::create($this->champsDepense($request) + [
                'etablissement_id' => $etablissementId,
                'enregistre_par' => $request->user()->id,
            ]);
            $d->update(['reference' => 'DEP-' . substr((string) $request->date_depense, 0, 4) . '-' . $d->id]);

            return $d;
        });
        $nombre = $this->stockerJustificatifs($request, $depense);

        return response()->json([
            'message' => 'Dépense ' . $depense->reference . ' enregistrée : ' . number_format((float) $depense->montant, 0, ',', ' ') . ' GNF'
                . ($nombre > 0 ? '.' : ' — pièce justificative à joindre.'),
            'depense' => $this->formaterDepense($depense->fresh()->load('justificatifs')->loadCount('justificatifs'), true),
        ], 201);
    }

    /** Corrige une depense (hors annulees) : montant, moyen, categorie, numero de piece... */
    public function updateDepense(Request $request, $id)
    {
        $depense = $this->depenseModifiable($request, $id);
        $request->validate($this->reglesDepense(), $this->messagesDepense());
        $depense->update($this->champsDepense($request));

        return response()->json([
            'message' => "Dépense {$depense->reference} modifiée.",
            'depense' => $this->formaterDepense($depense->fresh()->load('justificatifs')->loadCount('justificatifs'), true),
        ]);
    }

    /** Ajoute une ou plusieurs pieces justificatives a une depense. */
    public function ajouterJustificatifs(Request $request, $id)
    {
        $depense = $this->depenseModifiable($request, $id);
        $request->validate([
            'justificatifs' => 'required|array|min:1|max:5',
            'justificatifs.*' => 'required|' . self::REGLE_FICHIER,
            'numero_piece' => 'nullable|string|max:60',
        ], self::MESSAGES_FICHIER + ['justificatifs.required' => 'Choisissez au moins un fichier.']);
        if ($request->filled('numero_piece')) {
            $depense->update(['numero_piece' => trim($request->numero_piece)]);
        }
        $nombre = $this->stockerJustificatifs($request, $depense);

        return response()->json([
            'message' => $nombre > 1 ? "{$nombre} pièces jointes à la dépense {$depense->reference}." : "Pièce jointe à la dépense {$depense->reference}.",
            'depense' => $this->formaterDepense($depense->fresh()->load('justificatifs')->loadCount('justificatifs'), true),
        ]);
    }

    public function supprimerJustificatif(Request $request, $id)
    {
        $justificatif = JustificatifDepense::whereHas('depense', fn ($q) => $q->where('etablissement_id', $request->user()->etablissement_id))
            ->findOrFail($id);
        $depense = $justificatif->depense;
        if ($depense->annule_le) {
            return response()->json(['message' => 'Cette dépense est annulée : ses pièces sont conservées.'], 422);
        }
        Storage::disk('local')->delete($justificatif->fichier_path);
        $justificatif->delete();

        return response()->json([
            'message' => 'Pièce retirée.',
            'depense' => $this->formaterDepense($depense->fresh()->load('justificatifs')->loadCount('justificatifs'), true),
        ]);
    }

    // Route signee (voir JustificatifDepense::getUrlAttribute).
    public function fichierJustificatif($id)
    {
        $justificatif = JustificatifDepense::findOrFail($id);
        if (!Storage::disk('local')->exists($justificatif->fichier_path)) {
            abort(404);
        }

        return response()->file(Storage::disk('local')->path($justificatif->fichier_path), [
            'Content-Type' => $justificatif->type_mime,
            'Content-Disposition' => 'inline; filename="' . str_replace('"', '', $justificatif->nom_original) . '"',
            'Cache-Control' => 'private, max-age=86400',
        ]);
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

    private function reglesDepense(): array
    {
        return [
            'date_depense' => 'required|date|before_or_equal:today',
            'categorie' => 'required|in:' . implode(',', array_keys(self::CATEGORIES)),
            'libelle' => 'required|string|max:200',
            'montant' => 'required|numeric|min:1',
            'moyen_paiement' => 'required|in:' . implode(',', self::MOYENS),
            'beneficiaire' => 'nullable|string|max:150',
            'numero_piece' => 'nullable|string|max:60',
            'observation' => 'nullable|string|max:1000',
        ];
    }

    private function messagesDepense(): array
    {
        return self::MESSAGES_FICHIER + [
            'date_depense.before_or_equal' => 'La date de la dépense ne peut pas être dans le futur.',
            'montant.min' => 'Le montant doit être positif.',
        ];
    }

    private function champsDepense(Request $request): array
    {
        return [
            'date_depense' => $request->date_depense,
            'categorie' => $request->categorie,
            'libelle' => trim($request->libelle),
            'montant' => $request->montant,
            'moyen_paiement' => $request->moyen_paiement,
            'beneficiaire' => $request->beneficiaire ?: null,
            'numero_piece' => $request->numero_piece ? trim($request->numero_piece) : null,
            'observation' => $request->observation ?: null,
        ];
    }

    private function depenseModifiable(Request $request, $id): Depense
    {
        $depense = Depense::where('etablissement_id', $request->user()->etablissement_id)->findOrFail($id);
        abort_if($depense->annule_le !== null, 422, 'Cette dépense est annulée : elle ne peut plus être modifiée.');

        return $depense;
    }

    private function stockerJustificatifs(Request $request, Depense $depense): int
    {
        $fichiers = $request->file('justificatifs') ?? [];
        foreach ($fichiers as $fichier) {
            $chemin = $fichier->storeAs(
                "justificatifs/{$depense->etablissement_id}",
                $depense->id . '-' . Str::random(12) . '.' . $fichier->extension(),
                'local'
            );
            $depense->justificatifs()->create([
                'fichier_path' => $chemin,
                'nom_original' => mb_substr($fichier->getClientOriginalName(), 0, 250),
                'type_mime' => $fichier->getMimeType() ?: 'application/octet-stream',
                'taille' => $fichier->getSize(),
                'ajoute_par' => $request->user()->id,
            ]);
        }

        return count($fichiers);
    }

    private function formaterDepense(Depense $d, bool $avecPieces = false): array
    {
        $nombrePieces = (int) ($d->justificatifs_count ?? 0);
        $donnees = [
            'id' => $d->id,
            'date' => $d->date_depense?->toDateString(),
            'categorie' => $d->categorie,
            'libelle' => $d->libelle,
            'beneficiaire' => $d->beneficiaire,
            'montant' => (float) $d->montant,
            'moyen_paiement' => $d->moyen_paiement,
            'reference' => $d->reference,
            'numero_piece' => $d->numero_piece,
            'observation' => $d->observation,
            'par' => $d->auteur?->name,
            'annule' => $d->annule_le !== null,
            'annule_le' => $d->annule_le?->toDateTimeString(),
            'annule_par' => $d->annulateur?->name,
            'motif_annulation' => $d->motif_annulation,
            'nb_justificatifs' => $nombrePieces,
            'a_justifier' => $d->annule_le === null && $nombrePieces === 0,
        ];
        if ($avecPieces) {
            $donnees['justificatifs'] = $d->justificatifs->map(fn ($j) => [
                'id' => $j->id,
                'nom' => $j->nom_original,
                'type_mime' => $j->type_mime,
                'taille' => $j->taille,
                'url' => $j->url,
                'ajoute_par' => $j->auteur?->name,
                'ajoute_le' => $j->created_at?->toDateTimeString(),
            ])->values();
        }

        return $donnees;
    }
}
