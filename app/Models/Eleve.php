<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Eleve extends Model
{
    use SoftDeletes;

    protected $with = ['inscriptionActive.classe'];

    protected $fillable = [
        'etablissement_id',
        'nom', 'prenom', 'matricule', 'date_naissance', 'lieu_naissance',
        'photo_path', 'statut_dossier',
    ];

    public function etablissement()
    {
        return $this->belongsTo(Etablissement::class);
    }

    public function inscriptions()
    {
        return $this->hasMany(Inscription::class);
    }

    public function inscriptionActive()
    {
        return $this->hasOne(Inscription::class)
            ->whereHas('sessionScolaire', fn ($q) => $q->where('est_active', true))
            ->where('statut', 'active');
    }

    public function getClasseIdAttribute()
    {
        return $this->inscriptionActive?->classe_id;
    }

    public function getSessionScolaireIdAttribute()
    {
        return $this->inscriptionActive?->session_scolaire_id;
    }

    public function filiations()
    {
        return $this->hasMany(EleveFiliation::class);
    }

    public function paiements()
    {
        return $this->hasMany(Paiement::class);
    }

    public function fraisEleves()
    {
        return $this->hasMany(FraisEleve::class);
    }

    /**
     * Frais de scolarite seuls ("scolarit%" couvre "Scolarite" et "Scolarité"). A precharger avec
     * scopeAvecStatutPaiement() pour calculer statut_paiement sans requete par eleve.
     */
    public function fraisScolarite()
    {
        return $this->hasMany(FraisEleve::class)
            ->whereHas('typeFrais', fn ($q) => $q->where('nom', 'ILIKE', 'scolarit%'));
    }

    /**
     * Precharge de quoi calculer statut_paiement pour toute une liste en quelques requetes
     * (au lieu de 2 par eleve) : frais de scolarite, echeances et somme de leurs paiements.
     */
    public function scopeAvecStatutPaiement($query)
    {
        return $query->with([
            'fraisScolarite.echeances' => fn ($q) => $q->withSum('paiements', 'montant'),
        ]);
    }

    /**
     * Detail du retard de paiement (echeances de scolarite dont la date limite est depassee et qui
     * ne sont pas soldees) : premiere echeance depassee et montant du. null si aucun retard.
     * Sans requete supplementaire quand scopeAvecStatutPaiement() a ete utilise.
     */
    public function detailRetard(): ?array
    {
        [$echeances, $sessionId] = $this->echeancesScolarite();

        $aujourdhui = today()->toDateString();
        $depassees = $echeances
            ->filter(fn ($e) => substr((string) $e->date_limite, 0, 10) < $aujourdhui && (float) $e->montant - $e->montant_paye > 0)
            ->sortBy(fn ($e) => (string) $e->date_limite)
            ->values();

        if ($depassees->isEmpty()) {
            // Regle du 10 : aucun paiement de scolarite apres le 10 du premier mois de la session.
            $limite = self::dateLimiteSansPaiement($sessionId);
            $aucunPaiement = $echeances->isNotEmpty() && $echeances->every(fn ($e) => $e->montant_paye <= 0);
            if (! $limite || ! $aucunPaiement || $aujourdhui <= $limite) {
                return null;
            }
            $premiere = $echeances->sortBy(fn ($e) => (string) $e->date_limite)->first();

            return [
                'motif' => 'aucun_paiement',
                'echeance' => $premiere->libelle,
                'date_limite' => $limite,
                'nombre_echeances' => 0,
                'montant_du' => (float) $premiere->montant,
            ];
        }

        $premiere = $depassees->first();

        return [
            'motif' => 'echeance_depassee',
            'echeance' => $premiere->libelle,
            'date_limite' => substr((string) $premiere->date_limite, 0, 10),
            'nombre_echeances' => $depassees->count(),
            'montant_du' => $depassees->sum(fn ($e) => (float) $e->montant - $e->montant_paye),
        ];
    }

    /**
     * Statut global de paiement de l'eleve, calcule sur les seules echeances de SCOLARITE
     * (types de frais dont le nom commence par "scolarit"), dans l'ordre de priorite :
     * - en_retard : au moins une echeance avec reste a payer et date limite depassee
     *               (meme si un paiement partiel a deja eu lieu), ou AUCUN paiement apres le 10
     *               du premier mois de la session (regle du 10, voir dateLimiteSansPaiement)
     * - partiel   : paiement partiel sur une echeance due (date limite <= aujourd'hui ;
     *               les echeances depassees sont deja en retard, reste donc celle du jour)
     * - a_jour    : toutes les echeances sont soldees, ou au moins une echeance est due
     *               et toutes les echeances dues sont soldees
     * - partiel   : (suite) sinon, des qu'un paiement existe, meme sur une echeance pas encore due
     *               (ex : Trimestre 1 paye d'avance, Trimestres 2 et 3 non payes)
     * - a_echoir  : aucun paiement
     */
    public function getStatutPaiementAttribute()
    {
        [$echeances, $sessionId] = $this->echeancesScolarite();

        return self::calculerStatutPaiement($echeances, self::dateLimiteSansPaiement($sessionId));
    }

    /**
     * Echeances de scolarite de l'eleve (avec la somme de leurs paiements) et session concernee.
     * Sans requete si scopeAvecStatutPaiement() a ete utilise. Seuls les frais de scolarite
     * comptent : les frais d'inscription / reinscription sont payes une fois et n'influencent pas
     * le statut.
     */
    private function echeancesScolarite(): array
    {
        if ($this->relationLoaded('fraisScolarite')) {
            return [$this->fraisScolarite->flatMap->echeances, $this->fraisScolarite->first()?->session_scolaire_id];
        }

        $frais = $this->fraisScolarite()->get(['frais_eleves.id', 'frais_eleves.session_scolaire_id']);
        // withSum = somme de tous les paiements de chaque echeance, en une seule requete.
        $echeances = EcheanceEleve::whereIn('frais_eleve_id', $frais->pluck('id'))
            ->withSum('paiements', 'montant')
            ->get();

        return [$echeances, $frais->first()?->session_scolaire_id];
    }

    /**
     * Regle du 10 : un eleve qui n'a encore RIEN paye sur sa scolarite est en retard a partir du 11
     * du premier mois de la session (ex. session debutant le 01/10 : en retard des le 11/10), et le
     * reste jusqu'a son premier paiement. Retourne cette date limite (Y-m-d), memorisee par session
     * pour ne pas refaire de requete eleve par eleve.
     */
    public static function dateLimiteSansPaiement(?int $sessionId): ?string
    {
        static $parSession = [];
        if (! $sessionId) {
            return null;
        }
        if (! array_key_exists($sessionId, $parSession)) {
            $debut = SessionScolaire::whereKey($sessionId)->value('date_debut');
            $parSession[$sessionId] = $debut
                ? \Carbon\Carbon::parse($debut)->startOfMonth()->addDays(9)->toDateString()
                : null;
        }
        return $parSession[$sessionId];
    }

    /**
     * Statut de paiement a partir des echeances de scolarite (montant_paye disponible), dans l'ordre
     * decrit plus haut, plus la regle du 10 ($dateLimiteSansPaiement) : aucun paiement apres cette
     * date = en_retard. Partage par l'accesseur et les statistiques par classe.
     */
    public static function calculerStatutPaiement($echeances, ?string $dateLimiteSansPaiement): string
    {
        if ($echeances->isEmpty()) {
            return 'aucun_frais';
        }

        $aujourdhui = today()->toDateString();
        $lignes = $echeances->map(fn ($e) => [
            'paye' => $e->montant_paye,
            'reste' => (float) $e->montant - $e->montant_paye,
            'limite' => substr((string) $e->date_limite, 0, 10),
        ]);

        if ($lignes->contains(fn ($l) => $l['reste'] > 0 && $l['limite'] < $aujourdhui)) {
            return 'en_retard';
        }

        if ($dateLimiteSansPaiement && $aujourdhui > $dateLimiteSansPaiement
            && $lignes->every(fn ($l) => $l['paye'] <= 0)) {
            return 'en_retard';
        }

        $dues = $lignes->filter(fn ($l) => $l['limite'] <= $aujourdhui);

        if ($dues->contains(fn ($l) => $l['paye'] > 0 && $l['reste'] > 0)) {
            return 'partiel';
        }

        // Tout est solde (meme si tout est paye d'avance), ou toutes les echeances dues le sont.
        if ($lignes->every(fn ($l) => $l['reste'] <= 0)
            || ($dues->isNotEmpty() && $dues->every(fn ($l) => $l['reste'] <= 0))) {
            return 'a_jour';
        }

        if ($lignes->contains(fn ($l) => $l['paye'] > 0)) {
            return 'partiel';
        }

        return 'a_echoir';
    }
}

