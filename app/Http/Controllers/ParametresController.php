<?php

namespace App\Http\Controllers;

use App\Models\AppareilConfiance;
use App\Models\Enseignant;
use App\Models\Etablissement;
use App\Models\Role;
use App\Models\SessionScolaire;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

// Module Parametres : fiche de l'etablissement, profil et securite du compte connecte, sessions
// scolaires, roles et consommation. La fiche etablissement et les sessions ne sont modifiables
// que par la direction (Fondateur, Directeur, Proviseur).
class ParametresController extends Controller
{
    private const ROLES_ADMINISTRATION = ['FONDATEUR', 'DIRECTEUR', 'PROVISEUR'];
    private const TYPES = ['ecole_privee', 'ecole_publique', 'universite', 'centre_formation'];
    private const CYCLES = ['maternelle', 'primaire', 'college', 'lycee'];

    public function index(Request $request)
    {
        $user = $request->user();
        $etablissementId = $user->etablissement_id;

        return response()->json([
            'peut_administrer' => $this->peutAdministrer($user),
            'etablissement' => $this->ficheEtablissement($user->etablissement),
            'profil' => [
                'name' => $user->name,
                'email' => $user->email,
                'telephone' => $user->telephone,
                'photo_url' => $user->photo_url,
                'role' => $this->roleActuel($user),
                'membre_depuis' => $user->created_at?->toDateString(),
            ],
            'sessions' => $this->sessions($etablissementId),
            'securite' => $this->securite($request),
            'roles' => $this->roles($etablissementId),
            'consommation' => [
                'eleves' => DB::table('inscriptions')
                    ->join('sessions_scolaires', 'sessions_scolaires.id', '=', 'inscriptions.session_scolaire_id')
                    ->where('sessions_scolaires.etablissement_id', $etablissementId)
                    ->where('sessions_scolaires.est_active', true)
                    ->where('inscriptions.statut', 'active')
                    ->count(),
                'enseignants' => Enseignant::where('etablissement_id', $etablissementId)->count(),
                'utilisateurs' => User::where('etablissement_id', $etablissementId)->count(),
                'classes' => DB::table('classes')->where('etablissement_id', $etablissementId)->count(),
            ],
        ]);
    }

    public function updateEtablissement(Request $request)
    {
        $this->exigerAdministration($request->user());

        $donnees = $request->validate([
            'nom' => 'required|string|max:255',
            'type' => ['required', Rule::in(self::TYPES)],
            'ville' => 'nullable|string|max:255',
            'quartier' => 'nullable|string|max:255',
            'region' => 'nullable|string|max:255',
            'prefecture' => 'nullable|string|max:255',
            'coordonnees_gps' => 'nullable|string|max:255',
            'adresse' => 'nullable|string|max:255',
            'telephone' => 'nullable|string|max:50',
            'telephone_secondaire' => 'nullable|string|max:50',
            'whatsapp_relance' => 'nullable|string|max:50',
            'email' => 'nullable|email|max:255',
            'cycles' => 'nullable|array',
            'cycles.*' => Rule::in(self::CYCLES),
            'capacite_accueil' => 'nullable|integer|min:0|max:100000',
            'agrement' => 'nullable|string|max:255',
            'slogan' => 'nullable|string|max:255',
        ]);

        $etablissement = $request->user()->etablissement;
        $etablissement->update($donnees);

        return response()->json($this->ficheEtablissement($etablissement->fresh()));
    }

    public function enregistrerLogo(Request $request)
    {
        $this->exigerAdministration($request->user());
        $request->validate(['logo' => 'required|image|mimes:png,jpg,jpeg,webp|max:2048']);

        $etablissement = $request->user()->etablissement;
        if ($etablissement->logo_path) {
            Storage::disk('local')->delete($etablissement->logo_path);
        }
        $chemin = $request->file('logo')->storeAs(
            'logos',
            $etablissement->id . '-' . time() . '.' . $request->file('logo')->extension(),
            'local'
        );
        $etablissement->update(['logo_path' => $chemin]);

        return response()->json($this->ficheEtablissement($etablissement->fresh()));
    }

    public function supprimerLogo(Request $request)
    {
        $this->exigerAdministration($request->user());

        $etablissement = $request->user()->etablissement;
        if ($etablissement->logo_path) {
            Storage::disk('local')->delete($etablissement->logo_path);
            $etablissement->update(['logo_path' => null]);
        }

        return response()->json($this->ficheEtablissement($etablissement->fresh()));
    }

    // Route publique : le logo figure sur des documents imprimes et dans des <img> sans jeton.
    public function logo($id)
    {
        $etablissement = Etablissement::findOrFail($id);
        if (!$etablissement->logo_path || !Storage::disk('local')->exists($etablissement->logo_path)) {
            abort(404);
        }

        return response()->file(Storage::disk('local')->path($etablissement->logo_path), [
            'Cache-Control' => 'public, max-age=86400',
        ]);
    }

    public function updateProfil(Request $request)
    {
        $user = $request->user();
        $donnees = $request->validate([
            'name' => 'required|string|max:255',
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'telephone' => ['nullable', 'string', 'max:50', Rule::unique('users', 'telephone')->ignore($user->id)],
        ], [
            'email.unique' => 'Cette adresse e-mail est déjà utilisée par un autre compte.',
            'telephone.unique' => 'Ce numéro est déjà utilisé par un autre compte.',
        ]);

        $user->update($donnees + ['updated_by' => $user->id]);

        return response()->json($this->profilPublic($user));
    }

    public function enregistrerPhoto(Request $request)
    {
        $request->validate(['photo' => 'required|image|mimes:png,jpg,jpeg,webp|max:2048'], [
            'photo.max' => 'La photo ne doit pas dépasser 2 Mo.',
            'photo.image' => 'Le fichier doit être une image.',
            'photo.mimes' => 'Formats acceptés : PNG, JPG ou WebP.',
        ]);

        $user = $request->user();
        if ($user->photo_path) {
            Storage::disk('local')->delete($user->photo_path);
        }
        $chemin = $request->file('photo')->storeAs(
            'photos-utilisateurs',
            $user->id . '-' . time() . '.' . $request->file('photo')->extension(),
            'local'
        );
        $user->update(['photo_path' => $chemin]);

        return response()->json($this->profilPublic($user->fresh()));
    }

    public function supprimerPhoto(Request $request)
    {
        $user = $request->user();
        if ($user->photo_path) {
            Storage::disk('local')->delete($user->photo_path);
            $user->update(['photo_path' => null]);
        }

        return response()->json($this->profilPublic($user->fresh()));
    }

    // Route signee (voir User::getPhotoUrlAttribute).
    public function photo($id)
    {
        $user = User::findOrFail($id);
        if (!$user->photo_path || !Storage::disk('local')->exists($user->photo_path)) {
            abort(404);
        }

        return response()->file(Storage::disk('local')->path($user->photo_path), [
            'Cache-Control' => 'private, max-age=86400',
        ]);
    }

    private function profilPublic(User $user): array
    {
        return [
            'name' => $user->name,
            'email' => $user->email,
            'telephone' => $user->telephone,
            'photo_url' => $user->photo_url,
        ];
    }

    public function changerMotDePasse(Request $request)
    {
        $request->validate([
            'mot_de_passe_actuel' => 'required|string',
            'nouveau_mot_de_passe' => 'required|string|min:8|confirmed|different:mot_de_passe_actuel',
        ], [
            'nouveau_mot_de_passe.min' => 'Le nouveau mot de passe doit contenir au moins 8 caractères.',
            'nouveau_mot_de_passe.confirmed' => 'La confirmation ne correspond pas au nouveau mot de passe.',
            'nouveau_mot_de_passe.different' => 'Le nouveau mot de passe doit être différent de l\'actuel.',
        ]);

        $user = $request->user();
        if (!Hash::check($request->mot_de_passe_actuel, $user->password)) {
            return response()->json(['message' => 'Le mot de passe actuel est incorrect.'], 422);
        }

        $user->update(['password' => Hash::make($request->nouveau_mot_de_passe)]);

        // Les autres connexions ouvertes avec l'ancien mot de passe sont fermees.
        $user->tokens()->where('id', '!=', $user->currentAccessToken()->id)->delete();

        return response()->json(['message' => 'Mot de passe mis à jour.']);
    }

    public function fermerConnexion(Request $request, $id)
    {
        $user = $request->user();
        if ((int) $id === $user->currentAccessToken()->id) {
            return response()->json(['message' => 'Utilisez « Se déconnecter » pour fermer la session en cours.'], 422);
        }
        $user->tokens()->where('id', $id)->delete();

        return response()->json($this->securite($request));
    }

    public function fermerAutresConnexions(Request $request)
    {
        $user = $request->user();
        $user->tokens()->where('id', '!=', $user->currentAccessToken()->id)->delete();

        return response()->json($this->securite($request));
    }

    public function oublierAppareil(Request $request, $id)
    {
        AppareilConfiance::where('user_id', $request->user()->id)->where('id', $id)->delete();

        return response()->json($this->securite($request));
    }

    public function oublierAppareils(Request $request)
    {
        AppareilConfiance::where('user_id', $request->user()->id)->delete();

        return response()->json($this->securite($request));
    }

    public function creerSession(Request $request)
    {
        $user = $request->user();
        $this->exigerAdministration($user);

        $request->validate([
            'annee_debut' => 'required|integer|between:2000,2100',
            'date_debut' => 'required|date',
            'date_fin' => 'required|date|after:date_debut',
            'activer' => 'boolean',
        ]);

        $libelle = $request->annee_debut . '-' . ($request->annee_debut + 1);
        $existe = SessionScolaire::where('etablissement_id', $user->etablissement_id)->where('libelle', $libelle)->exists();
        if ($existe) {
            return response()->json(['message' => "La session {$libelle} existe déjà."], 422);
        }

        $session = SessionScolaire::create([
            'etablissement_id' => $user->etablissement_id,
            'libelle' => $libelle,
            'date_debut' => $request->date_debut,
            'date_fin' => $request->date_fin,
            'statut' => 'preparation',
            'est_active' => false,
        ]);

        if ($request->boolean('activer')) {
            $this->basculerSur($session);
        }

        return response()->json($this->sessions($user->etablissement_id), 201);
    }

    public function activerSession(Request $request, $id)
    {
        $user = $request->user();
        $this->exigerAdministration($user);

        $session = SessionScolaire::where('etablissement_id', $user->etablissement_id)->findOrFail($id);
        if ($session->est_active) {
            return response()->json(['message' => 'Cette session est déjà active.'], 422);
        }
        $this->basculerSur($session);

        return response()->json($this->sessions($user->etablissement_id));
    }

    // Une seule session active : l'ancienne est cloturee au moment de la bascule.
    private function basculerSur(SessionScolaire $session): void
    {
        DB::transaction(function () use ($session) {
            SessionScolaire::where('etablissement_id', $session->etablissement_id)
                ->where('est_active', true)
                ->update(['est_active' => false, 'statut' => 'cloturee']);
            $session->update(['est_active' => true, 'statut' => 'en_cours']);
        });
    }

    private function ficheEtablissement(?Etablissement $e): ?array
    {
        if (!$e) {
            return null;
        }

        return [
            'id' => $e->id,
            'nom' => $e->nom,
            'code' => $e->code,
            'type' => $e->type,
            'ville' => $e->ville,
            'quartier' => $e->quartier,
            'region' => $e->region,
            'prefecture' => $e->prefecture,
            'coordonnees_gps' => $e->coordonnees_gps,
            'adresse' => $e->adresse,
            'telephone' => $e->telephone,
            'telephone_secondaire' => $e->telephone_secondaire,
            'whatsapp_relance' => $e->whatsapp_relance,
            'email' => $e->email,
            'cycles' => $e->cycles ?? [],
            'capacite_accueil' => $e->capacite_accueil,
            'agrement' => $e->agrement,
            'slogan' => $e->slogan,
            'devise' => $e->devise,
            'statut' => $e->statut,
            'date_fin_essai' => $e->date_fin_essai,
            'logo_url' => $e->logo_url,
        ];
    }

    private function sessions(int $etablissementId): array
    {
        $effectifs = DB::table('inscriptions')
            ->where('statut', 'active')
            ->groupBy('session_scolaire_id')
            ->selectRaw('session_scolaire_id, COUNT(*) as total')
            ->pluck('total', 'session_scolaire_id');

        return SessionScolaire::where('etablissement_id', $etablissementId)
            ->orderByDesc('date_debut')
            ->get()
            ->map(fn ($s) => [
                'id' => $s->id,
                'libelle' => $s->libelle,
                'date_debut' => $s->date_debut,
                'date_fin' => $s->date_fin,
                'statut' => $s->statut,
                'est_active' => (bool) $s->est_active,
                'eleves' => (int) ($effectifs[$s->id] ?? 0),
            ])
            ->all();
    }

    private function securite(Request $request): array
    {
        $user = $request->user();
        $courant = $user->currentAccessToken()->id;

        return [
            'total_connexions' => $user->tokens()->count(),
            // Les plus recentes seulement : les anciens jetons jamais fermes s'accumulent.
            'connexions' => $user->tokens()
                ->orderByRaw('COALESCE(last_used_at, created_at) DESC')
                ->limit(15)
                ->get(['id', 'created_at', 'last_used_at'])
                ->map(fn ($t) => [
                    'id' => $t->id,
                    'ouverte_le' => $t->created_at?->toIso8601String(),
                    'derniere_activite' => ($t->last_used_at ?? $t->created_at)?->toIso8601String(),
                    'courante' => $t->id === $courant,
                ])
                ->all(),
            'appareils' => AppareilConfiance::where('user_id', $user->id)
                ->where('date_expiration', '>', now())
                ->orderByDesc('created_at')
                ->get()
                ->map(fn ($a) => [
                    'id' => $a->id,
                    'nom' => $a->nom_appareil,
                    'ajoute_le' => $a->created_at?->toIso8601String(),
                    'expire_le' => $a->date_expiration ? \Illuminate\Support\Carbon::parse($a->date_expiration)->toIso8601String() : null,
                ])
                ->all(),
        ];
    }

    // Roles de l'etablissement avec leurs permissions reelles (groupees par module) et leurs membres.
    private function roles(int $etablissementId): array
    {
        return Role::where('etablissement_id', $etablissementId)
            ->with(['permissions:id,nom,module,description'])
            ->withCount('users')
            ->orderBy('nom')
            ->get()
            ->map(fn ($r) => [
                'id' => $r->id,
                'nom' => $r->nom,
                'description' => $r->description,
                'utilisateurs' => $r->users_count,
                'permissions' => $r->permissions
                    ->sortBy('nom')
                    ->map(fn ($p) => ['nom' => $p->nom, 'module' => $p->module, 'description' => $p->description])
                    ->values(),
            ])
            ->all();
    }

    private function roleActuel(User $user): ?string
    {
        $role = $user->roles()->where('roles.etablissement_id', $user->etablissement_id)->first();

        return $role?->nom;
    }

    private function peutAdministrer(User $user): bool
    {
        return in_array(strtoupper((string) $this->roleActuel($user)), self::ROLES_ADMINISTRATION, true);
    }

    private function exigerAdministration(User $user): void
    {
        if (!$this->peutAdministrer($user)) {
            abort(403, 'Seule la direction peut modifier ces paramètres.');
        }
    }
}
