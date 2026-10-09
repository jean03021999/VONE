<?php

namespace App\Http\Controllers;

use App\Models\SessionScolaire;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

// Rapport financier mois par mois d'une session : attendu selon l'echeancier (echeances dont la
// date limite tombe dans le mois) et part deja recouvree, encaissements (par type de frais),
// salaires et depenses payes, solde du mois et solde cumule.
class RapportFinancierController extends Controller
{
    public function evolution(Request $request)
    {
        $etablissementId = $request->user()->etablissement_id;
        $sessions = SessionScolaire::where('etablissement_id', $etablissementId)->orderByDesc('date_debut')->get(['id', 'libelle', 'date_debut', 'date_fin', 'est_active']);
        $session = $request->filled('session_id')
            ? $sessions->firstWhere('id', (int) $request->session_id)
            : ($sessions->firstWhere('est_active', true) ?? $sessions->first());
        abort_unless($session, 404, 'Aucune session scolaire.');

        $debutSession = Carbon::parse($session->date_debut)->startOfMonth();
        $finSession = Carbon::parse($session->date_fin)->endOfMonth();

        // Paiements valides des eleves de l'etablissement, avec le type de frais (pour la repartition).
        $paiements = DB::table('paiements as p')
            ->join('eleves as e', 'e.id', '=', 'p.eleve_id')
            ->leftJoin('echeances_eleves as ee', 'ee.id', '=', 'p.echeance_eleve_id')
            ->leftJoin('frais_eleves as fe', 'fe.id', '=', 'ee.frais_eleve_id')
            ->leftJoin('types_frais as tf', 'tf.id', '=', 'fe.type_frais_id')
            ->where('e.etablissement_id', $etablissementId)
            ->whereNull('p.annule_le')
            ->where(fn ($q) => $q->where('fe.session_scolaire_id', $session->id)->orWhereNull('fe.id'))
            ->selectRaw("to_char(p.date_paiement, 'YYYY-MM') AS periode, tf.nom AS type, SUM(p.montant) AS total")
            // Reprises de l'existant (import Excel) : comptees dans l'annee, mais hors caisse.
            ->selectRaw("SUM(CASE WHEN p.moyen_paiement = 'reprise' THEN p.montant ELSE 0 END) AS reprises")
            ->groupBy('periode', 'type')
            ->get();

        // Le rapport commence au premier mois de la session, ou plus tot si des inscriptions ont ete
        // encaissees avant la rentree, ou si des echeances tombent avant (mensualites de Mai et Juin
        // payees avant la rentree d'octobre).
        $premiereEcheance = DB::table('echeances_eleves as ee')
            ->join('frais_eleves as fe', 'fe.id', '=', 'ee.frais_eleve_id')
            ->join('eleves as e', 'e.id', '=', 'fe.eleve_id')
            ->where('e.etablissement_id', $etablissementId)
            ->where('fe.session_scolaire_id', $session->id)
            ->min('ee.date_limite');
        $premierMois = collect([$paiements->min('periode'), $premiereEcheance ? substr($premiereEcheance, 0, 7) : null])->filter()->min();
        $debut = $premierMois && $premierMois < $debutSession->format('Y-m') ? Carbon::parse("{$premierMois}-01") : $debutSession->copy();

        $salaires = DB::table('salaires')->where('etablissement_id', $etablissementId)->where('statut', 'paye')
            ->whereBetween('date_paiement', [$debut->toDateString(), $finSession->toDateString()])
            ->selectRaw("to_char(date_paiement, 'YYYY-MM') AS periode, SUM(montant_net) AS total, COUNT(*) AS nombre")
            ->groupBy('periode')->get()->keyBy('periode');
        $depenses = DB::table('depenses')->where('etablissement_id', $etablissementId)->whereNull('annule_le')
            ->whereBetween('date_depense', [$debut->toDateString(), $finSession->toDateString()])
            ->selectRaw("to_char(date_depense, 'YYYY-MM') AS periode, categorie, SUM(montant) AS total")
            ->groupBy('periode', 'categorie')->get()->groupBy('periode');

        // Attendu selon l'echeancier et part recouvree (paiements valides sur ces echeances).
        $payes = DB::table('paiements')->whereNull('annule_le')->groupBy('echeance_eleve_id')->selectRaw('echeance_eleve_id, SUM(montant) AS total');
        $attendu = DB::table('echeances_eleves as ee')
            ->join('frais_eleves as fe', 'fe.id', '=', 'ee.frais_eleve_id')
            ->join('eleves as e', 'e.id', '=', 'fe.eleve_id')
            ->leftJoinSub($payes, 'pp', 'pp.echeance_eleve_id', '=', 'ee.id')
            ->where('e.etablissement_id', $etablissementId)
            ->whereNull('e.deleted_at')
            ->where('fe.session_scolaire_id', $session->id)
            ->selectRaw("to_char(ee.date_limite, 'YYYY-MM') AS periode, SUM(ee.montant) AS attendu, SUM(LEAST(COALESCE(pp.total, 0), ee.montant)) AS recouvre")
            // Part reellement echue (date limite depassee) de chaque mois.
            ->selectRaw('SUM(CASE WHEN ee.date_limite < ? THEN ee.montant ELSE 0 END) AS attendu_echu', [today()->toDateString()])
            ->selectRaw('SUM(CASE WHEN ee.date_limite < ? THEN LEAST(COALESCE(pp.total, 0), ee.montant) ELSE 0 END) AS recouvre_echu', [today()->toDateString()])
            ->groupBy('periode')->get()->keyBy('periode');

        $categories = CaisseController::CATEGORIES;
        $mois = [];
        $cumul = 0;
        $aujourdhui = today()->format('Y-m');
        for ($m = $debut->copy(); $m->lte($finSession); $m->addMonth()) {
            $cle = $m->format('Y-m');
            $entreesMois = $paiements->where('periode', $cle);
            $parType = ['scolarite' => 0.0, 'inscription' => 0.0, 'autres' => 0.0];
            foreach ($entreesMois as $p) {
                $nom = strtolower(\Illuminate\Support\Str::ascii((string) $p->type));
                $parType[str_starts_with($nom, 'scolarit') ? 'scolarite' : (str_contains($nom, 'inscription') ? 'inscription' : 'autres')] += (float) $p->total;
            }
            $entrees = array_sum($parType);
            $reprises = (float) $entreesMois->sum('reprises');
            $sal = (float) ($salaires[$cle]->total ?? 0);
            $dep = (float) collect($depenses[$cle] ?? [])->sum('total');
            $cumul += $entrees - $sal - $dep;
            $mois[] = [
                'mois' => $cle,
                'libelle' => ucfirst($m->locale('fr')->translatedFormat('F Y')),
                'futur' => $cle > $aujourdhui,
                'attendu' => (float) ($attendu[$cle]->attendu ?? 0),
                'recouvre' => (float) ($attendu[$cle]->recouvre ?? 0),
                'entrees' => $entrees,
                'entrees_par_type' => $parType,
                'dont_reprises' => $reprises,
                'salaires' => $sal,
                'nombre_salaires' => (int) ($salaires[$cle]->nombre ?? 0),
                'depenses' => $dep,
                'depenses_par_categorie' => collect($depenses[$cle] ?? [])
                    ->map(fn ($d) => ['categorie' => $d->categorie, 'libelle' => $categories[$d->categorie] ?? $d->categorie, 'total' => (float) $d->total])
                    ->sortByDesc('total')->values(),
                'solde' => $entrees - $sal - $dep,
                'solde_cumule' => $cumul,
            ];
        }

        // Attendu echu (date limite depassee) : base du taux de recouvrement a ce jour.
        $echu = $attendu->values();

        return response()->json([
            'session' => ['id' => $session->id, 'libelle' => $session->libelle, 'date_debut' => $session->date_debut, 'date_fin' => $session->date_fin],
            'sessions' => $sessions->map(fn ($s) => ['id' => $s->id, 'libelle' => $s->libelle, 'est_active' => (bool) $s->est_active])->values(),
            'mois' => $mois,
            'totaux' => [
                'attendu' => collect($mois)->sum('attendu'),
                'recouvre' => collect($mois)->sum('recouvre'),
                'attendu_echu' => (float) $echu->sum('attendu_echu'),
                'recouvre_echu' => (float) $echu->sum('recouvre_echu'),
                'entrees' => collect($mois)->sum('entrees'),
                // Part des entrees payee avant LAKOLI : entrees - reprises = encaissements de la caisse.
                'dont_reprises' => collect($mois)->sum('dont_reprises'),
                'salaires' => collect($mois)->sum('salaires'),
                'depenses' => collect($mois)->sum('depenses'),
                'solde' => $cumul,
            ],
        ]);
    }
}
