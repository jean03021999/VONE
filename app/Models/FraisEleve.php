<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FraisEleve extends Model
{
    protected $fillable = [
        'eleve_id', 'type_frais_id', 'session_scolaire_id',
        'montant_total', 'montant_original', 'motif_personnalisation',
    ];

    public function eleve()
    {
        return $this->belongsTo(Eleve::class);
    }

    public function typeFrais()
    {
        return $this->belongsTo(TypeFrais::class);
    }

    public function echeances()
    {
        return $this->hasMany(EcheanceEleve::class);
    }
}
