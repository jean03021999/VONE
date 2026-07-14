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
        if ($this->solde <= 0) {
            return 'payee';
        }
        if ($this->montant_paye > 0) {
            return 'partiellement_payee';
        }
        if (now()->greaterThan($this->date_limite)) {
            return 'en_retard';
        }
        return 'a_echoir';
    }
}
