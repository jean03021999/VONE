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
     * Statut global de paiement de l'eleve, calcule sur les seules echeances de SCOLARITE
     * (types de frais dont le nom commence par "scolarit"), dans l'ordre de priorite :
     * - en_retard : au moins une echeance avec reste a payer et date limite depassee
     *               (meme si un paiement partiel a deja eu lieu)
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
        // Seuls les frais de scolarite comptent : les frais d'inscription / reinscription sont
        // payes une fois et ne doivent pas influencer ce statut. "scolarit%" couvre "Scolarite"
        // et "Scolarité" (l'accent vient apres le prefixe).
        $fraisScolarite = $this->fraisEleves()
            ->whereHas('typeFrais', fn ($q) => $q->where('nom', 'ILIKE', 'scolarit%'))
            ->pluck('id');

        // withSum = somme de tous les paiements de chaque echeance, en une seule requete.
        $echeances = EcheanceEleve::whereIn('frais_eleve_id', $fraisScolarite)
            ->withSum('paiements', 'montant')
            ->get();

        if ($echeances->isEmpty()) {
            return 'aucun_frais';
        }

        $aujourdhui = today()->toDateString();
        $lignes = $echeances->map(fn ($e) => [
            'paye' => (float) $e->paiements_sum_montant,
            'reste' => (float) $e->montant - (float) $e->paiements_sum_montant,
            'limite' => substr((string) $e->date_limite, 0, 10),
        ]);

        if ($lignes->contains(fn ($l) => $l['reste'] > 0 && $l['limite'] < $aujourdhui)) {
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

