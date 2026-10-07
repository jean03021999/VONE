<?php

namespace App\Http\Controllers;

use App\Models\ArreteCaisse;
use App\Models\Depense;
use App\Models\Paiement;
use App\Models\Salaire;
use Illuminate\Http\Request;

// Arrete de caisse du jour : le soir, le comptable compte les especes (billetage) et les compare a
// ce que le systeme attend (especes de la veille + encaissements en especes du jour - salaires et
// depenses payes en especes le jour meme). L'ecart est enregistre avec une observation.
class ArreteCaisseController extends Controller
{
    public const COUPURES = [20000, 10000, 5000, 2000, 1000, 500, 100];

    private const MOYENS = ['especes', 'mobile_money', 'virement', 'cheque'];

    /** Historique des arretes, les plus recents d'abord. */
    public function index(Request $request)
    {
        return response()->json(
            ArreteCaisse::where('etablissement_id', $request->user()->etablissement_id)
                ->with('auteur:id,name')
                ->orderByDesc('date_arrete')
                ->limit(120)
                ->get()
                ->map(fn ($a) => $this->formater($a))
        );
    }

    /** Situation d'une journee (par defaut aujourd'hui) et arrete deja enregistre s'il existe. */
    public function preparer(Request $request)
    {
        $request->validate(['date' => 'nullable|date|before_or_equal:today']);
        $date = $request->date ?: today()->toDateString();
        $etablissementId = $request->user()->etablissement_id;

        $situation = $this->situation($etablissementId, $date);
        $arrete = ArreteCaisse::where('etablissement_id', $etablissementId)->whereDate('date_arrete', $date)->with('auteur:id,name')->first();

        return response()->json($situation + [
            'coupures' => self::COUPURES,
            'arrete' => $arrete ? $this->formater($arrete) : null,
            // Seul ce jour-la peut etre rouvert ; aucune operation ne peut etre datee de ce jour ou d'avant.
            'dernier_arrete' => ArreteCaisse::dernierJourArrete($etablissementId),
        ]);
    }

    /**
     * Rouvre le DERNIER arrete (motif obligatoire, trace dans le journal du serveur) pour pouvoir
     * corriger une operation de cette journee : voir ArreteCaisse::exigerJourOuvert. Un arrete plus
     * ancien ne se rouvre pas directement, les suivants en dependent (especes de la veille).
     */
    public function rouvrir(Request $request)
    {
        $request->validate([
            'date' => 'required|date',
            'motif' => 'required|string|min:3|max:255',
        ], ['motif.required' => 'Indiquez pourquoi la caisse est rouverte.', 'motif.min' => 'Indiquez pourquoi la caisse est rouverte.']);

        $etablissementId = $request->user()->etablissement_id;
        $date = substr($request->date, 0, 10);
        $arrete = ArreteCaisse::where('etablissement_id', $etablissementId)->whereDate('date_arrete', $date)->first();
        if (!$arrete) {
            return response()->json(['message' => "Aucun arrêté de caisse le {$date}."], 404);
        }
        if (ArreteCaisse::dernierJourArrete($etablissementId) !== $date) {
            return response()->json(['message' => "Seul le dernier arrêté peut être rouvert : rouvrez d'abord les journées suivantes."], 422);
        }

        \Illuminate\Support\Facades\Log::warning('Arrêté de caisse rouvert', [
            'etablissement_id' => $etablissementId,
            'date' => $date,
            'par' => $request->user()->id . ' ' . $request->user()->name,
            'motif' => trim($request->motif),
            'especes_theoriques' => $arrete->especes_theoriques,
            'especes_comptees' => $arrete->especes_comptees,
        ]);
        $arrete->delete();

        return response()->json(['message' => 'Caisse du ' . \Carbon\Carbon::parse($date)->format('d/m/Y') . ' rouverte : faites la correction, puis arrêtez-la à nouveau.']);
    }

    /** Enregistre (ou refait) l'arrete d'une journee a partir du billetage. */
    public function store(Request $request)
    {
        $request->validate([
            'date' => 'required|date|before_or_equal:today',
            'billetage' => 'required|array',
            'billetage.*' => 'nullable|integer|min:0|max:1000000',
            'observation' => 'nullable|string|max:1000',
        ], [
            'date.before_or_equal' => "On ne peut pas arrêter la caisse d'un jour futur.",
            'billetage.*.integer' => 'Le nombre de billets doit être un nombre entier.',
        ]);

        $billetage = [];
        foreach (self::COUPURES as $coupure) {
            $billetage[(string) $coupure] = (int) ($request->billetage[(string) $coupure] ?? 0);
        }
        $comptees = array_sum(array_map(fn ($c, $n) => (int) $c * $n, array_keys($billetage), $billetage));

        $etablissementId = $request->user()->etablissement_id;
        $date = substr($request->date, 0, 10);
        $s = $this->situation($etablissementId, $date);
        $ecart = $comptees - $s['especes_theoriques'];

        if (abs($ecart) >= 1 && trim((string) $request->observation) === '') {
            return response()->json([
                'message' => 'Il y a un écart de ' . number_format($ecart, 0, ',', ' ') . " GNF : expliquez-le dans l'observation.",
                'errors' => ['observation' => ["Expliquez l'écart."]],
            ], 422);
        }

        $arrete = ArreteCaisse::updateOrCreate(
            ['etablissement_id' => $etablissementId, 'date_arrete' => $date],
            [
                'entrees' => $s['entrees'],
                'sorties' => $s['sorties'],
                'nombre_versements' => $s['nombre_versements'],
                'especes_veille' => $s['especes_veille'],
                'especes_theoriques' => $s['especes_theoriques'],
                'especes_comptees' => $comptees,
                'ecart' => $ecart,
                'billetage' => $billetage,
                'observation' => trim((string) $request->observation) ?: null,
                'arrete_par' => $request->user()->id,
            ]
        );

        $texte = abs($ecart) < 1 ? 'caisse juste' : ($ecart > 0 ? 'excédent de ' : 'manquant de ') . number_format(abs($ecart), 0, ',', ' ') . ' GNF';

        return response()->json([
            'message' => 'Caisse du ' . \Illuminate\Support\Carbon::parse($date)->format('d/m/Y') . ($arrete->wasRecentlyCreated ? ' arrêtée' : ' arrêtée à nouveau') . " : {$texte}.",
            'arrete' => $this->formater($arrete->load('auteur:id,name')),
        ]);
    }

    /**
     * Mouvements du jour par moyen, operations detaillees et especes attendues le soir : especes
     * cumulees jusqu'a la veille + entrees en especes du jour - sorties en especes du jour.
     */
    private function situation(int $etablissementId, string $date): array
    {
        $paiements = fn () => Paiement::encaisses()->whereHas('eleve', fn ($q) => $q->where('etablissement_id', $etablissementId));
        $salaires = fn () => Salaire::where('etablissement_id', $etablissementId)->where('statut', 'paye');
        $depenses = fn () => Depense::valides()->where('etablissement_id', $etablissementId);
        $parMoyen = fn ($requete, string $colonne) => $requete->groupBy('moyen_paiement')->selectRaw("moyen_paiement, SUM({$colonne}) as total")->pluck('total', 'moyen_paiement');

        $entreesJour = $parMoyen($paiements()->whereDate('date_paiement', $date), 'montant');
        $salairesJour = $parMoyen($salaires()->whereDate('date_paiement', $date), 'montant_net');
        $depensesJour = $parMoyen($depenses()->whereDate('date_depense', $date), 'montant');

        $entrees = [];
        $sorties = [];
        foreach (self::MOYENS as $m) {
            $entrees[$m] = (float) ($entreesJour[$m] ?? 0);
            $sorties[$m] = ['salaires' => (float) ($salairesJour[$m] ?? 0), 'depenses' => (float) ($depensesJour[$m] ?? 0)];
        }

        // Especes jusqu'a la veille (toutes les operations en especes anterieures au jour).
        $veille = (float) $paiements()->where('moyen_paiement', 'especes')->whereDate('date_paiement', '<', $date)->sum('montant')
            - (float) $salaires()->where('moyen_paiement', 'especes')->whereDate('date_paiement', '<', $date)->sum('montant_net')
            - (float) $depenses()->where('moyen_paiement', 'especes')->whereDate('date_depense', '<', $date)->sum('montant');
        $theoriques = $veille + $entrees['especes'] - $sorties['especes']['salaires'] - $sorties['especes']['depenses'];

        // Operations du jour (pour controle a l'ecran et sur la fiche).
        $versements = $paiements()->whereDate('date_paiement', $date)->with('eleve:id,nom,prenom,matricule')->orderBy('id')->get();
        $operations = $versements
            ->groupBy(fn ($p) => $p->reference ?: 'P' . $p->id)
            ->map(fn ($groupe) => [
                'type' => 'entree',
                'libelle' => trim(($groupe->first()->eleve?->prenom ?? '') . ' ' . ($groupe->first()->eleve?->nom ?? '')),
                'detail' => $groupe->pluck('libelle')->filter()->unique()->implode(' + '),
                'reference' => $groupe->first()->reference,
                'moyen_paiement' => $groupe->first()->moyen_paiement,
                'montant' => (float) $groupe->sum('montant'),
            ])->values();
        foreach ($salaires()->whereDate('date_paiement', $date)->with('enseignant:id,nom,prenom')->get() as $s) {
            $operations->push([
                'type' => 'sortie',
                'libelle' => 'Salaire ' . trim(($s->enseignant?->prenom ?? '') . ' ' . ($s->enseignant?->nom ?? '')),
                'detail' => \Illuminate\Support\Carbon::create()->month($s->mois)->locale('fr')->translatedFormat('F') . " {$s->annee}",
                'reference' => $s->reference,
                'moyen_paiement' => $s->moyen_paiement,
                'montant' => (float) $s->montant_net,
            ]);
        }
        foreach ($depenses()->whereDate('date_depense', $date)->get() as $d) {
            $operations->push([
                'type' => 'sortie',
                'libelle' => $d->libelle,
                'detail' => $d->beneficiaire,
                'reference' => $d->reference,
                'moyen_paiement' => $d->moyen_paiement,
                'montant' => (float) $d->montant,
            ]);
        }

        return [
            'date' => $date,
            'entrees' => $entrees,
            'sorties' => $sorties,
            'nombre_versements' => $operations->where('type', 'entree')->count(),
            'especes_veille' => $veille,
            'especes_theoriques' => $theoriques,
            'operations' => $operations->values(),
        ];
    }

    private function formater(ArreteCaisse $a): array
    {
        return [
            'id' => $a->id,
            'date' => $a->date_arrete?->toDateString(),
            'entrees' => $a->entrees,
            'sorties' => $a->sorties,
            'nombre_versements' => $a->nombre_versements,
            'especes_veille' => $a->especes_veille,
            'especes_theoriques' => $a->especes_theoriques,
            'especes_comptees' => $a->especes_comptees,
            'ecart' => $a->ecart,
            'billetage' => $a->billetage,
            'observation' => $a->observation,
            'arrete_par' => $a->auteur?->name,
            'arrete_le' => $a->updated_at?->toDateTimeString(),
            'refait' => $a->updated_at && $a->created_at && $a->updated_at->gt($a->created_at),
        ];
    }
}
