<?php

namespace App\Http\Controllers;

use App\Models\Eleve;
use App\Models\Relance;
use Illuminate\Http\Request;

// Relances des impayes de scolarite : familles en retard (montant echu non paye, meme regle que le
// statut « en retard ») et familles dont une echeance arrive bientot (rappel preventif), avec les
// contacts des parents et le suivi des relances deja faites.
class RelanceController extends Controller
{
    private const CANAUX = ['lettre', 'whatsapp', 'sms', 'appel'];

    public function index(Request $request)
    {
        $request->validate(['horizon' => 'nullable|integer|min:1|max:90']);
        $horizon = (int) ($request->horizon ?: 15);
        $etablissementId = $request->user()->etablissement_id;
        $aujourdhui = today()->toDateString();
        $limiteRappel = today()->addDays($horizon)->toDateString();

        $eleves = Eleve::where('etablissement_id', $etablissementId)
            ->whereHas('inscriptionActive')
            ->with(['inscriptionActive.classe:id,nom', 'filiations:id,eleve_id,type_lien,nom_complet,telephone'])
            ->get(['id', 'nom', 'prenom', 'matricule']);
        $scolarite = Eleve::echeancesScolariteDe($eleves->pluck('id'));

        $relances = Relance::where('etablissement_id', $etablissementId)
            ->whereIn('eleve_id', $eleves->pluck('id'))
            ->with('auteur:id,name')
            ->orderByDesc('created_at')
            ->get()
            ->groupBy('eleve_id');

        $lignes = [];
        foreach ($eleves as $eleve) {
            $echeances = $scolarite[$eleve->id]['echeances'] ?? collect();
            if ($echeances->isEmpty()) {
                continue;
            }
            $sessionId = $scolarite[$eleve->id]['session_id'];
            $resteAnnee = $echeances->sum(fn ($e) => max(0, $e->montant - $e->montant_paye));
            if ($resteAnnee <= 0) {
                continue;
            }

            if (Eleve::statutDepuis($echeances, $sessionId, $aujourdhui) === 'en_retard') {
                $retard = Eleve::detailRetardDepuis($echeances, $sessionId, $aujourdhui);
                $motif = 'retard';
                $montant = $retard['montant_du'] ?? 0;
                $echeance = $retard['echeance'] ?? null;
                $dateLimite = $retard['date_limite'] ?? null;
                $nombre = max(1, $retard['nombre_echeances'] ?? 1);
            } else {
                // Rappel : echeances non soldees dont la date limite tombe dans l'horizon choisi.
                $proches = $echeances
                    ->filter(fn ($e) => $e->montant - $e->montant_paye > 0 && $e->date_limite >= $aujourdhui && $e->date_limite <= $limiteRappel)
                    ->sortBy('date_limite');
                if ($proches->isEmpty()) {
                    continue;
                }
                $motif = 'rappel';
                $montant = $proches->sum(fn ($e) => $e->montant - $e->montant_paye);
                $echeance = $proches->first()->libelle;
                $dateLimite = $proches->first()->date_limite;
                $nombre = $proches->count();
            }

            $historique = $relances[$eleve->id] ?? collect();
            $derniere = $historique->first();
            $lignes[] = [
                'eleve_id' => $eleve->id,
                'nom' => $eleve->nom,
                'prenom' => $eleve->prenom,
                'matricule' => $eleve->matricule,
                'classe' => $eleve->inscriptionActive?->classe?->nom,
                'classe_id' => $eleve->inscriptionActive?->classe_id,
                'motif' => $motif,
                'echeance' => $echeance,
                'date_limite' => $dateLimite,
                // Jours avant (positif) ou depuis (negatif) la date limite.
                'jours' => $dateLimite ? (int) round((strtotime($dateLimite) - strtotime($aujourdhui)) / 86400) : null,
                'nombre_echeances' => $nombre,
                'montant_du' => (float) $montant,
                'reste_annee' => (float) $resteAnnee,
                'contacts' => $eleve->filiations
                    ->filter(fn ($f) => $f->telephone || $f->nom_complet)
                    ->sortBy(fn ($f) => ['tuteur' => 0, 'pere' => 1, 'mere' => 2][$f->type_lien] ?? 3)
                    ->map(fn ($f) => ['lien' => $f->type_lien, 'nom' => $f->nom_complet, 'telephone' => $f->telephone])
                    ->values(),
                'nombre_relances' => $historique->count(),
                'derniere_relance' => $derniere ? [
                    'date' => $derniere->created_at->toDateTimeString(),
                    'canal' => $derniere->canal,
                    'par' => $derniere->auteur?->name,
                ] : null,
            ];
        }

        // Les plus gros retards d'abord, puis les echeances les plus proches.
        usort($lignes, fn ($a, $b) => [$a['motif'] === 'retard' ? 0 : 1, -$a['montant_du']] <=> [$b['motif'] === 'retard' ? 0 : 1, -$b['montant_du']]);

        return response()->json([
            'horizon' => $horizon,
            'lignes' => $lignes,
            'relances_7_jours' => Relance::where('etablissement_id', $etablissementId)->where('created_at', '>=', now()->subDays(7))->count(),
        ]);
    }

    /** Enregistre les relances faites (une par eleve), avec le montant du a cet instant. */
    public function store(Request $request)
    {
        $request->validate([
            'relances' => 'required|array|min:1|max:1500',
            'relances.*.eleve_id' => 'required|integer',
            'relances.*.montant_du' => 'required|numeric|min:0',
            'relances.*.motif' => 'required|in:retard,rappel',
            'canal' => 'required|in:' . implode(',', self::CANAUX),
            'note' => 'nullable|string|max:500',
        ]);
        $etablissementId = $request->user()->etablissement_id;
        $autorises = Eleve::where('etablissement_id', $etablissementId)
            ->whereIn('id', collect($request->relances)->pluck('eleve_id'))
            ->pluck('id')
            ->flip();

        $nombre = 0;
        foreach ($request->relances as $r) {
            if (! isset($autorises[$r['eleve_id']])) {
                continue;
            }
            Relance::create([
                'etablissement_id' => $etablissementId,
                'eleve_id' => $r['eleve_id'],
                'canal' => $request->canal,
                'motif' => $r['motif'],
                'montant_du' => $r['montant_du'],
                'note' => $request->note ?: null,
                'fait_par' => $request->user()->id,
            ]);
            $nombre++;
        }

        $canaux = ['lettre' => 'par lettre', 'whatsapp' => 'par WhatsApp', 'sms' => 'par SMS', 'appel' => 'par téléphone'];

        return response()->json([
            'message' => ($nombre > 1 ? "{$nombre} familles relancées " : 'Famille relancée ') . $canaux[$request->canal] . '.',
            'nombre' => $nombre,
        ], 201);
    }

    /** Historique des relances d'un eleve. */
    public function historique(Request $request, $eleveId)
    {
        $eleve = Eleve::where('etablissement_id', $request->user()->etablissement_id)->findOrFail($eleveId);

        return response()->json(
            Relance::where('eleve_id', $eleve->id)->with('auteur:id,name')->orderByDesc('created_at')->get()
                ->map(fn ($r) => [
                    'id' => $r->id,
                    'date' => $r->created_at->toDateTimeString(),
                    'canal' => $r->canal,
                    'motif' => $r->motif,
                    'montant_du' => $r->montant_du,
                    'note' => $r->note,
                    'par' => $r->auteur?->name,
                ])
        );
    }
}
