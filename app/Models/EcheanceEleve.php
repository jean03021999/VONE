<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EcheanceEleve extends Model
{
    protected $table = 'echeances_eleves';

    protected $fillable = ['frais_eleve_id', 'libelle', 'montant', 'date_limite'];

    public function fraisEleve()
    {
        return $this->belongsTo(FraisEleve::class);
    }

    public function paiements()
    {
        return $this->hasMany(Paiement::class, 'echeance_eleve_id');
    }

    public function getMontantPayeAttribute()
    {
        return $this->paiements()->sum('montant');
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
