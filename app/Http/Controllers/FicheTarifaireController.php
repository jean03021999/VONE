<?php

namespace App\Http\Controllers;

use App\Models\Classe;
use App\Models\EcheanceGrille;
use App\Models\FicheTarifaire;
use App\Models\FraisEleve;
use App\Models\GrilleTarifaire;
use App\Models\SessionScolaire;
use App\Models\TypeFrais;
use App\Services\FraisService;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Fiches tarifaires : un formulaire unique par groupe de classes (inscription, reinscription,
 * scolarite et ses echeances, autres frais). Une fiche ne stocke que sa presentation (nom, mode,
 * mois, caractere des autres frais) : les montants vivent dans les grilles tarifaires qu'elle cree
 * ou met a jour, une par type de frais et par classe, que lisent inscription, caisse et import.
 */
class FicheTarifaireController extends Controller
{
    private static function normaliser(?string $nom): string
    {
        return Str::of($nom ?? '')->ascii()->lower()->trim()->toString();
    }

    private static function estScolarite(?string $nom): bool
    {
        return str_starts_with(self::normaliser($nom), 'scolarit');
    }

    /** Refus en cours de transaction : tout ce qui a ete ecrit est annule. */
    private static function refuser(string $message, int $statut = 422, array $extra = []): never
    {
        throw new HttpResponseException(response()->json(['message' => $message] + $extra, $statut));
    }

    public function index(Request $request)
    {
        $etablissementId = $request->user()->etablissement_id;
        $session = SessionScolaire::where('etablissement_id', $etablissementId)->where('est_active', true)->first();
        if (! $session) {
            return response()->json(['session' => null, 'fiches' => [], 'tarifs_sans_fiche' => (object) []]);
        }

        $fiches = FicheTarifaire::where('etablissement_id', $etablissementId)
            ->where('session_scolaire_id', $session->id)
            ->with('classes:id')
            ->orderBy('nom')
            ->get();
        $grilles = GrilleTarifaire::where('etablissement_id', $etablissementId)
            ->where('session_scolaire_id', $session->id)
            ->with('typeFrais', 'echeances')
            ->get();
        $service = new FraisService();

        $resultat = $fiches->map(function (FicheTarifaire $fiche) use ($grilles, $service) {
            $classesIds = $fiche->classes->pluck('id')->sort()->values();
            $grillesFiche = $grilles->where('fiche_tarifaire_id', $fiche->id);
            // Les classes d'une fiche ont les memes tarifs : la premiere sert de reference.
            $resume = $this->resumeClasse($grillesFiche->where('classe_id', $classesIds->first()), $fiche->autres_frais ?? []);

            return [
                'id' => $fiche->id,
                'nom' => $fiche->nom,
                'mode_paiement' => $fiche->mode_paiement,
                'mode_saisie' => $fiche->mode_saisie,
                'montant_saisi' => $fiche->montant_saisi !== null ? (float) $fiche->montant_saisi : null,
                'mois' => $fiche->mois ?? [],
                'classes_ids' => $classesIds,
                'eleves_ayant_paye' => $service->nombrePayeurs($grillesFiche->pluck('id')),
                'mis_a_jour' => $fiche->updated_at?->toDateString(),
            ] + $resume;
        });

        // Tarifs des classes sans fiche (grilles creees avant les fiches) : de quoi pre-remplir
        // une fiche pour ces classes au lieu de tout ressaisir.
        $classesAvecFiche = $fiches->flatMap(fn ($f) => $f->classes->pluck('id'))->flip();
        $tarifsSansFiche = $grilles->reject(fn ($g) => isset($classesAvecFiche[$g->classe_id]))
            ->groupBy('classe_id')
            ->map(fn ($gs) => $this->resumeClasse($gs, []));

        return response()->json([
            'session' => ['id' => $session->id, 'libelle' => $session->libelle],
            'fiches' => $resultat,
            'tarifs_sans_fiche' => (object) $tarifsSansFiche->all(),
        ]);
    }

    /** Montants d'une classe : inscription, reinscription, echeances de scolarite, autres frais. */
    private function resumeClasse(Collection $grilles, array $autresMeta): array
    {
        $parType = fn (string $cherche) => $grilles->first(fn ($g) => self::normaliser($g->typeFrais?->nom) === $cherche);
        $scolarite = $grilles->filter(fn ($g) => self::estScolarite($g->typeFrais?->nom))
            ->sortBy(fn ($g) => $g->applicable_a === 'tous' ? 0 : 1)
            ->first();
        $echeances = fn ($g) => $g->echeances->sortBy([['date_limite', 'asc'], ['id', 'asc']])->values()
            ->map(fn ($e) => ['libelle' => $e->libelle, 'montant' => (float) $e->montant, 'date_limite' => substr((string) $e->date_limite, 0, 10)]);

        $autres = collect($autresMeta)->map(function ($meta) use ($grilles, $echeances) {
            $g = $grilles->firstWhere('type_frais_id', $meta['type_frais_id']);
            if (! $g) {
                return null;
            }
            $lignes = $echeances($g);

            return [
                'type_frais_id' => $g->type_frais_id,
                'libelle' => $g->typeFrais?->nom,
                // A chaque echeance : montant d'une echeance ; sinon montant unique.
                'montant' => $meta['periodicite'] === 'echeance' ? ($lignes[0]['montant'] ?? 0) : (float) $g->montant,
                'obligatoire' => (bool) $meta['obligatoire'],
                'periodicite' => $meta['periodicite'],
            ];
        })->filter()->values();

        return [
            'frais_inscription' => (float) ($parType('inscription')?->montant ?? 0),
            'frais_reinscription' => (float) ($parType('reinscription')?->montant ?? 0),
            'echeances' => $scolarite ? $echeances($scolarite) : [],
            'autres_frais' => $autres,
        ];
    }

    public function store(Request $request)
    {
        return $this->enregistrer($request, null);
    }

    public function update(Request $request, $id)
    {
        $fiche = FicheTarifaire::where('etablissement_id', $request->user()->etablissement_id)->findOrFail($id);

        return $this->enregistrer($request, $fiche);
    }

    /**
     * Cree ou met a jour, en une transaction, les grilles de chaque classe cochee. Une grille
     * modifiee alors que des eleves ont deja paye dessus exige `confirmer` (sinon 409) : les eleves
     * sans paiement passent au nouveau tarif, ceux qui ont paye gardent leur echeancier. Une classe
     * decochee garde ses tarifs (detaches de la fiche) ; un autre frais retire voit ses grilles
     * supprimees, refuse si un eleve a deja paye dessus.
     */
    private function enregistrer(Request $request, ?FicheTarifaire $fiche)
    {
        $request->validate([
            'nom' => 'required|string|max:100',
            'classes_ids' => 'required|array|min:1',
            'classes_ids.*' => 'integer|distinct',
            'frais_inscription' => 'required|numeric|min:1',
            'frais_reinscription' => 'required|numeric|min:1',
            'mode_paiement' => 'required|in:trimestre,mois,personnalise',
            'mode_saisie' => 'required|in:echeance,annuel',
            'montant_saisi' => 'nullable|numeric|min:0',
            'mois' => 'nullable|array',
            'mois.*' => 'string|max:20',
            'echeances' => 'required|array|min:1|max:24',
            'echeances.*.libelle' => 'required|string|max:100',
            'echeances.*.montant' => 'required|numeric|min:1',
            'echeances.*.date_limite' => 'required|date',
            'autres_frais' => 'nullable|array',
            'autres_frais.*.libelle' => 'required|string|max:100',
            'autres_frais.*.montant' => 'required|numeric|min:1',
            'autres_frais.*.obligatoire' => 'required|boolean',
            'autres_frais.*.periodicite' => 'required|in:unique,echeance',
            'confirmer' => 'boolean',
        ], [
            'echeances.*.montant.min' => 'Chaque échéance de scolarité doit avoir un montant.',
            'autres_frais.*.montant.min' => 'Chaque autre frais doit avoir un montant.',
        ]);

        $etablissementId = $request->user()->etablissement_id;

        $resultat = DB::transaction(function () use ($request, $fiche, $etablissementId) {
            $classes = Classe::where('etablissement_id', $etablissementId)->whereIn('id', $request->classes_ids)->get();
            if ($classes->count() !== count($request->classes_ids)) {
                self::refuser('Une des classes cochées est introuvable.');
            }
            $sessions = $classes->pluck('session_scolaire_id')->unique();
            if ($sessions->count() > 1) {
                self::refuser("Les classes cochées appartiennent à des années scolaires différentes.");
            }
            $sessionId = $sessions->first();
            if ($fiche && $fiche->session_scolaire_id !== $sessionId) {
                self::refuser("Une fiche ne peut pas changer d'année scolaire.");
            }

            $nom = trim($request->nom);
            $homonyme = FicheTarifaire::where('session_scolaire_id', $sessionId)
                ->whereRaw('LOWER(nom) = ?', [mb_strtolower($nom)])
                ->when($fiche, fn ($q) => $q->where('id', '!=', $fiche->id))
                ->exists();
            if ($homonyme) {
                self::refuser("Une autre fiche s'appelle déjà « {$nom} ».");
            }

            // Une seule fiche par classe (et la contrainte unique de fiche_tarifaire_classe en garantie).
            $occupees = DB::table('fiche_tarifaire_classe as fc')
                ->join('fiches_tarifaires as f', 'f.id', '=', 'fc.fiche_tarifaire_id')
                ->join('classes as c', 'c.id', '=', 'fc.classe_id')
                ->whereIn('fc.classe_id', $classes->pluck('id'))
                ->when($fiche, fn ($q) => $q->where('f.id', '!=', $fiche->id))
                ->get(['c.nom as classe', 'f.nom as fiche']);
            if ($occupees->isNotEmpty()) {
                self::refuser('Déjà dans une autre fiche : ' . $occupees->map(fn ($o) => "{$o->classe} (fiche « {$o->fiche} »)")->join(', ') . '.');
            }

            [$typeInscription, $typeReinscription, $typeScolarite, $autres] = $this->typesDeLaFiche($etablissementId, $request->input('autres_frais', []));
            $voulues = $this->grillesVoulues($request, $typeInscription, $typeReinscription, $typeScolarite, $autres);
            $parEleve = [$typeInscription->id, $typeReinscription->id];

            $existantes = GrilleTarifaire::where('session_scolaire_id', $sessionId)
                ->whereIn('classe_id', $classes->pluck('id'))
                ->with('typeFrais', 'echeances')
                ->get()
                ->groupBy('classe_id');

            // Plan : pour chaque classe et chaque frais, la grille existante a reprendre (ou null).
            $plan = [];
            foreach ($classes as $classe) {
                $grillesClasse = $existantes[$classe->id] ?? collect();
                foreach ($voulues as $typeId => $voulue) {
                    $memeType = $grillesClasse->where('type_frais_id', $typeId);
                    if (in_array($typeId, $parEleve, true)) {
                        // Inscription / reinscription : une grille par classe, quel que soit son public.
                        $existante = $memeType->sortByDesc('actif')->first();
                    } else {
                        if ($memeType->contains(fn ($g) => $g->applicable_a !== 'tous')) {
                            self::refuser("{$classe->nom} a un tarif « {$voulue['type']->nom} » distinct pour nouveaux et anciens élèves : supprimez-le dans « Détail des grilles » avant de mettre la classe dans une fiche.");
                        }
                        $existante = $memeType->first();
                    }
                    $plan[] = ['classe' => $classe, 'voulue' => $voulue, 'existante' => $existante];
                }
            }

            // Grilles de la fiche qui n'en font plus partie. Classe decochee : ses tarifs restent (la
            // classe repasse "sans fiche", ses inscriptions restent possibles) ; autre frais retire
            // d'une classe toujours cochee : sa grille est supprimee.
            $gardees = collect($plan)->pluck('existante')->filter()->pluck('id')->flip();
            $sortantes = $fiche
                ? GrilleTarifaire::where('fiche_tarifaire_id', $fiche->id)->with('classe', 'typeFrais')->get()->reject(fn ($g) => isset($gardees[$g->id]))
                : collect();
            $idsClasses = $classes->pluck('id')->flip();
            [$aRetirer, $aDetacher] = $sortantes->partition(fn ($g) => isset($idsClasses[$g->classe_id]));

            $service = new FraisService();
            foreach ($aRetirer as $g) {
                if ($service->nombrePayeurs([$g->id], true) > 0) {
                    self::refuser("Impossible de retirer « {$g->typeFrais?->nom} » de {$g->classe?->nom} : des élèves ont déjà payé dessus.");
                }
            }

            $modifiees = collect($plan)->filter(fn ($p) => $p['existante'] && $this->differe($p['existante'], $p['voulue']));
            $payeurs = $service->nombrePayeurs($modifiees->pluck('existante.id'));
            if ($payeurs > 0 && ! $request->boolean('confirmer')) {
                self::refuser(
                    "{$payeurs} élève(s) ont déjà payé sur les tarifs modifiés : confirmez la modification.",
                    409,
                    ['confirmation_requise' => true, 'eleves_ayant_paye' => $payeurs]
                );
            }

            $donneesFiche = [
                'nom' => $nom,
                'mode_paiement' => $request->mode_paiement,
                'mode_saisie' => $request->mode_saisie,
                'montant_saisi' => $request->montant_saisi,
                'mois' => $request->mode_paiement === 'mois' ? array_values($request->input('mois', [])) : null,
                'autres_frais' => collect($autres)->map(fn ($a) => [
                    'type_frais_id' => $a['type']->id,
                    'obligatoire' => $a['obligatoire'],
                    'periodicite' => $a['periodicite'],
                ])->values()->all(),
            ];
            if ($fiche) {
                $fiche->update($donneesFiche);
            } else {
                $fiche = FicheTarifaire::create($donneesFiche + ['etablissement_id' => $etablissementId, 'session_scolaire_id' => $sessionId]);
            }

            $stats = ['creees' => 0, 'modifiees' => 0, 'retirees' => 0, 'frais_crees' => 0, 'mis_a_jour' => 0, 'conserves' => 0];
            foreach ($aRetirer as $g) {
                FraisEleve::where('grille_tarifaire_id', $g->id)->delete();
                $g->echeances()->delete();
                $g->delete();
                $stats['retirees']++;
            }
            GrilleTarifaire::whereIn('id', $aDetacher->pluck('id'))->update(['fiche_tarifaire_id' => null]);

            $periodicite = ['trimestre' => 'trimestriel', 'mois' => 'mensuel'][$request->mode_paiement] ?? null;
            foreach ($plan as ['classe' => $classe, 'voulue' => $voulue, 'existante' => $grille]) {
                $attributs = [
                    'montant' => $voulue['montant'],
                    'actif' => $voulue['actif'],
                    'fiche_tarifaire_id' => $fiche->id,
                    'periodicite' => $voulue['type']->id === $typeScolarite->id ? $periodicite : null,
                ];
                if (! $grille) {
                    $grille = GrilleTarifaire::create($attributs + [
                        'etablissement_id' => $etablissementId,
                        'session_scolaire_id' => $sessionId,
                        'classe_id' => $classe->id,
                        'type_frais_id' => $voulue['type']->id,
                        'applicable_a' => $voulue['public'],
                    ]);
                    $grille->echeances()->createMany($voulue['echeances']);
                    $grille->setRelation('typeFrais', $voulue['type']);
                    $stats['creees']++;
                    $stats['frais_crees'] += $service->appliquerGrilleAuxEleves($grille);
                    continue;
                }

                $changee = $this->differe($grille, $voulue);
                $activee = $voulue['actif'] && ! $grille->actif;
                $grille->update($attributs);
                if ($changee) {
                    $grille->echeances()->delete();
                    $grille->echeances()->createMany($voulue['echeances']);
                    $report = $service->reporterTarifSurEleves($grille, $voulue['montant'], $voulue['echeances']);
                    $stats['mis_a_jour'] += $report['mis_a_jour'];
                    $stats['conserves'] += $report['conserves'];
                    $stats['modifiees']++;
                }
                if ($activee) {
                    $stats['frais_crees'] += $service->appliquerGrilleAuxEleves($grille->fresh(['typeFrais', 'echeances']));
                }
            }

            $fiche->classes()->sync($classes->pluck('id'));

            return ['fiche' => $fiche, 'stats' => $stats];
        });

        $s = $resultat['stats'];
        $details = array_filter([
            $s['creees'] ? "{$s['creees']} tarif(s) créé(s)" : null,
            $s['modifiees'] ? "{$s['modifiees']} modifié(s)" : null,
            $s['retirees'] ? "{$s['retirees']} retiré(s)" : null,
            $s['frais_crees'] ? "{$s['frais_crees']} frais ajouté(s) aux élèves" : null,
            $s['mis_a_jour'] ? "{$s['mis_a_jour']} élève(s) passé(s) au nouveau tarif" : null,
            $s['conserves'] ? "{$s['conserves']} élève(s) ayant déjà payé gardent leur échéancier" : null,
        ]);

        return response()->json([
            'message' => "Fiche « {$resultat['fiche']->nom} » enregistrée" . ($details ? ' : ' . implode(', ', $details) . '.' : '.'),
            'id' => $resultat['fiche']->id,
        ] + $s, $fiche ? 200 : 201);
    }

    /**
     * Types de frais de la fiche, crees s'ils n'existent pas encore : Inscription, Reinscription,
     * Scolarite, et un type par autre frais (reconnu par son nom sans accent ni casse).
     */
    private function typesDeLaFiche(int $etablissementId, array $autresFrais): array
    {
        $types = TypeFrais::where('etablissement_id', $etablissementId)->orderBy('id')->get();
        $trouverOuCreer = function (callable $critere, string $nom) use (&$types, $etablissementId) {
            $type = $types->first($critere);
            if (! $type) {
                $type = TypeFrais::create(['etablissement_id' => $etablissementId, 'nom' => $nom]);
                $types->push($type);
            }

            return $type;
        };

        $inscription = $trouverOuCreer(fn ($t) => self::normaliser($t->nom) === 'inscription', 'Inscription');
        $reinscription = $trouverOuCreer(fn ($t) => self::normaliser($t->nom) === 'reinscription', 'Réinscription');
        $scolarite = $trouverOuCreer(fn ($t) => self::estScolarite($t->nom), 'Scolarité');

        $autres = [];
        foreach ($autresFrais as $ligne) {
            $libelle = trim($ligne['libelle']);
            $cle = self::normaliser($libelle);
            if (in_array($cle, ['inscription', 'reinscription'], true) || self::estScolarite($libelle)) {
                self::refuser("« {$libelle} » se règle dans les frais d'entrée ou la scolarité, pas dans les autres frais.");
            }
            if (isset($autres[$cle])) {
                self::refuser("L'autre frais « {$libelle} » est saisi deux fois.");
            }
            $autres[$cle] = [
                'type' => $trouverOuCreer(fn ($t) => self::normaliser($t->nom) === $cle, $libelle),
                'montant' => (float) $ligne['montant'],
                'obligatoire' => (bool) $ligne['obligatoire'],
                'periodicite' => $ligne['periodicite'],
            ];
        }

        return [$inscription, $reinscription, $scolarite, array_values($autres)];
    }

    /** Grilles a obtenir dans chaque classe, par type de frais : montant, echeances, actif, public. */
    private function grillesVoulues(Request $request, TypeFrais $inscription, TypeFrais $reinscription, TypeFrais $scolarite, array $autres): array
    {
        $echeancesScolarite = collect($request->echeances)->map(fn ($e) => [
            'libelle' => trim($e['libelle']),
            'montant' => round((float) $e['montant'], 2),
            'date_limite' => substr($e['date_limite'], 0, 10),
        ])->values()->all();
        // Les frais payes une fois sont dus a la premiere echeance de scolarite.
        $premiereDate = collect($echeancesScolarite)->min('date_limite');
        $unique = fn (TypeFrais $type, float $montant) => [['libelle' => $type->nom, 'montant' => $montant, 'date_limite' => $premiereDate]];

        $voulues = [
            $inscription->id => ['type' => $inscription, 'montant' => (float) $request->frais_inscription, 'echeances' => $unique($inscription, (float) $request->frais_inscription), 'actif' => true, 'public' => 'nouveau', 'par_eleve' => true],
            $reinscription->id => ['type' => $reinscription, 'montant' => (float) $request->frais_reinscription, 'echeances' => $unique($reinscription, (float) $request->frais_reinscription), 'actif' => true, 'public' => 'ancien', 'par_eleve' => true],
            $scolarite->id => ['type' => $scolarite, 'montant' => collect($echeancesScolarite)->sum('montant'), 'echeances' => $echeancesScolarite, 'actif' => true, 'public' => 'tous'],
        ];

        foreach ($autres as $a) {
            $echeances = $a['periodicite'] === 'echeance'
                ? collect($echeancesScolarite)->map(fn ($e) => [
                    'libelle' => Str::limit("{$a['type']->nom} — {$e['libelle']}", 100, ''),
                    'montant' => $a['montant'],
                    'date_limite' => $e['date_limite'],
                ])->all()
                : $unique($a['type'], $a['montant']);
            // Un frais facultatif n'est pas facture d'office : sa grille est creee inactive.
            $voulues[$a['type']->id] = ['type' => $a['type'], 'montant' => collect($echeances)->sum('montant'), 'echeances' => $echeances, 'actif' => $a['obligatoire'], 'public' => 'tous'];
        }

        return $voulues;
    }

    /**
     * Vrai si le montant ou les echeances de la grille different de ce que veut la fiche. Pour
     * l'inscription et la reinscription seul le montant compte : la caisse les encaisse en une fois
     * a la date du jour, l'echeance de la grille ne sert pas.
     */
    private function differe(GrilleTarifaire $grille, array $voulue): bool
    {
        if (abs((float) $grille->montant - $voulue['montant']) > 0.001) {
            return true;
        }
        if (! empty($voulue['par_eleve'])) {
            return false;
        }
        $actuelles = $grille->echeances->sortBy([['date_limite', 'asc'], ['id', 'asc']])->values()
            ->map(fn (EcheanceGrille $e) => [trim($e->libelle), round((float) $e->montant, 2), substr((string) $e->date_limite, 0, 10)])
            ->all();
        $nouvelles = collect($voulue['echeances'])->map(fn ($e) => [$e['libelle'], round((float) $e['montant'], 2), $e['date_limite']])->all();

        return $actuelles !== $nouvelles;
    }
}
