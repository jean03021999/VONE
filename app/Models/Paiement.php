<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Paiement extends Model
{
    protected $fillable = [
        'eleve_id', 'libelle', 'montant', 'moyen_paiement', 'date_paiement',
    ];

    public function eleve()
    {
        return $this->belongsTo(Eleve::class);
    }
}
