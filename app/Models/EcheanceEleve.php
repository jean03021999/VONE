<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EcheanceEleve extends Model
{
    protected $table = 'echeances_eleves';

    protected $fillable = ['frais_eleve_id', 'libelle', 'montant', 'montant_initial', 'date_limite'];

    // Montant d'avant remise (FraisService::appliquerRemise), fixe a la creation de l'echeance.
    protected static function booted(): void
    {
        static::creating(function (EcheanceEleve $echeance) {
            $echeance->montant_initial ??= $echeance->montant;
        });
    }

    public function fraisEleve()
    {
        return $this->belongsTo(FraisEleve::class);
    }

    // Paiements valides seulement : un paiement annule ne compte plus dans le montant paye, le
    // solde ni les statuts (toutes les sommes passent par cette relation).
    public function paiements()
    {
        return $this->hasMany(Paiement::class, 'echeance_eleve_id')->whereNull('annule_le');
    }

    /**
     * Total paye sur l'echeance. Sans requete si la somme a ete prechargee
     * (withSum('paiements', 'montant')) ou si les paiements sont deja charges ;
     * sinon une requete, toujours a jour (utilise sous verrou lors d'un paiement).
     */
    public function getMontantPayeAttribute()
    {
        if (array_key_exists('paiements_sum_montant', $this->attributes)) {
            return (float) $this->attributes['paiements_sum_montant'];
        }
        if ($this->relationLoaded('paiements')) {
            return (float) $this->paiements->sum('montant');
        }

        return (float) $this->paiements()->sum('montant');
    }

    public function getSoldeAttribute()
    {
        return $this->montant - $this->montant_paye;
    }

    public function getStatutAttribute()
    {
        // Somme de tous les paiements de l'echeance, calculee une seule fois (1 requete).
        $paye = $this->montant_paye;

        if ($paye >= $this->montant) {
            return 'payee';
        }
        if ($paye > 0) {
            return 'partiellement_payee';
        }
        // today() (et non now()) : une echeance due aujourd'hui n'est pas encore en retard.
        if (today()->greaterThan($this->date_limite)) {
            return 'en_retard';
        }
        return 'a_echoir';
    }
}
