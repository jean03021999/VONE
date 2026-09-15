<?php

namespace App\Http\Controllers;

use App\Models\Eleve;
use App\Models\EleveFiliation;
use App\Services\InscriptionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class EleveController extends Controller
{
    public function index(Request $request)
    {
        $etablissementId = $request->user()->etablissement_id;

        $query = Eleve::where('etablissement_id', $etablissementId)
            ->with('inscriptionActive.classe', 'inscriptionActive.sessionScolaire');

        if ($request->filled('classe_id')) {
            $query->whereHas('inscriptionActive', fn ($q) => $q->where('classe_id', $request->classe_id));
        }

        if ($request->filled('recherche')) {
            $recherche = $request->recherche;
            $query->where(function ($q) use ($recherche) {
                $q->where('nom', 'like', "%{$recherche}%")
                    ->orWhere('prenom', 'like', "%{$recherche}%")
                    ->orWhere('matricule', 'like', "%{$recherche}%");
            });
        }

        $eleves = $query->get()->map(function ($eleve) {
            return [
                'id' => $eleve->id,
                'nom' => $eleve->nom,
                'prenom' => $eleve->prenom,
                'matricule' => $eleve->matricule,
                'classe' => $eleve->inscriptionActive?->classe?->nom,
                'inscription_active' => $eleve->inscriptionActive ? [
                    'type_inscription' => $eleve->inscriptionActive->type_inscription,
                    'statut' => $eleve->inscriptionActive->statut,
                ] : null,
                'photo_path' => $eleve->photo_path,
                'statut_dossier' => $eleve->statut_dossier,
                'statut_paiement' => $eleve->statut_paiement,
            ];
        });

        $total = $eleves->count();
        $aJour = $eleves->where('statut_paiement', 'a_jour')->count();
        $enRetard = $eleves->where('statut_paiement', 'en_retard')->count();
        $aEchoir = $eleves->where('statut_paiement', 'a_echoir')->count();

        return response()->json([
            'eleves' => $eleves,
            'stats' => [
                'total' => $total,
                'a_jour' => $aJour,
                'en_retard' => $enRetard,
                'a_echoir' => $aEchoir,
            ],
        ]);
    }

    public function show(Request $request, $id)
    {
        $etablissementId = $request->user()->etablissement_id;

        $eleve = Eleve::where('etablissement_id', $etablissementId)
            ->with(['inscriptionActive.classe', 'filiations', 'fraisEleves.echeances.paiements'])
            ->findOrFail($id);

        return response()->json($eleve);
    }

    public function store(Request $request)
    {
        $request->validate([
            'nom' => 'required|string',
            'prenom' => 'required|string',
            'date_naissance' => 'required|date',
            'lieu_naissance' => 'nullable|string',
            'classe_id' => 'required|exists:classes,id',
        ]);

        $etablissementId = $request->user()->etablissement_id;
        $classe = \App\Models\Classe::findOrFail($request->classe_id);

        $eleve = DB::transaction(function () use ($request, $etablissementId, $classe) {
            $prefixeMatricule = 'LAK-' . date('Y') . '-';
            $dernierNumero = Eleve::withTrashed()
                ->where('matricule', 'like', $prefixeMatricule . '%')
                ->get(['matricule'])
                ->max(fn ($e) => (int) substr($e->matricule, strlen($prefixeMatricule)));

            do {
                $dernierNumero = ($dernierNumero ?? 0) + 1;
                $matricule = $prefixeMatricule . str_pad($dernierNumero, 3, '0', STR_PAD_LEFT);
            } while (Eleve::withTrashed()->where('matricule', $matricule)->exists());

            $eleve = Eleve::create([
                'etablissement_id' => $etablissementId,
                'nom' => $request->nom,
                'prenom' => $request->prenom,
                'matricule' => $matricule,
                'date_naissance' => $request->date_naissance,
                'lieu_naissance' => $request->lieu_naissance,
                'statut_dossier' => 'photo_manquante',
            ]);

            (new InscriptionService())->inscrire($eleve, $classe);

            if ($request->filled('pere_nom')) {
                $eleve->filiations()->create([
                    'type_lien' => 'pere',
                    'nom_complet' => $request->pere_nom,
                    'telephone' => $request->pere_telephone,
                ]);
            }
            if ($request->filled('mere_nom')) {
                $eleve->filiations()->create([
                    'type_lien' => 'mere',
                    'nom_complet' => $request->mere_nom,
                    'telephone' => $request->mere_telephone,
                ]);
            }
            if ($request->filled('tuteur_nom')) {
                $eleve->filiations()->create([
                    'type_lien' => 'tuteur',
                    'nom_complet' => $request->tuteur_nom,
                    'telephone' => $request->tuteur_telephone,
                    'lien_avec_eleve' => $request->tuteur_lien,
                ]);
            }

            return $eleve;
        });

        return response()->json($eleve->load('inscriptionActive.classe', 'filiations'), 201);
    }

    public function update(Request $request, $id)
    {
        $request->validate([
            'nom' => 'sometimes|string',
            'prenom' => 'sometimes|string',
            'date_naissance' => 'sometimes|date',
            'lieu_naissance' => 'nullable|string',
            'classe_id' => 'sometimes|exists:classes,id',
            'correction' => 'sometimes|boolean',
            'motif' => 'sometimes|string',
        ]);

        $etablissementId = $request->user()->etablissement_id;
        $eleve = Eleve::where('etablissement_id', $etablissementId)->findOrFail($id);

        $champsIdentitaires = $request->only(['nom', 'prenom', 'date_naissance', 'lieu_naissance']);

        return DB::transaction(function () use ($request, $eleve, $champsIdentitaires) {
            $message = null;

            if ($request->filled('classe_id')) {
                $inscriptionActive = $eleve->inscriptionActive;
                $nouvelleClasse = \App\Models\Classe::findOrFail($request->classe_id);
                $service = new InscriptionService();

                if ($request->boolean('correction')) {
                    $service->corrigerClasse($eleve, $nouvelleClasse);
                    $message = 'Correction administrative effectuee';
                } elseif (! $inscriptionActive || $nouvelleClasse->session_scolaire_id !== $inscriptionActive->session_scolaire_id) {
                    $service->reinscrire($eleve, $nouvelleClasse);
                    $message = 'Reinscription effectuee';
                } else {
                    if (! $request->filled('motif')) {
                        return response()->json(['message' => 'Le motif est obligatoire pour un changement de classe'], 422);
                    }
                    $service->changerClasse($eleve, $nouvelleClasse, $request->motif);
                    $message = 'Changement de classe effectue';
                }
            }

            if (! empty($champsIdentitaires)) {
                $eleve->update($champsIdentitaires);
            }

            $eleve = $eleve->fresh(['inscriptionActive.classe']);

            return $message
                ? response()->json(['message' => $message, 'eleve' => $eleve])
                : response()->json($eleve);
        });
    }
}



