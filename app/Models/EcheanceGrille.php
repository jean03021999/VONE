<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EcheanceGrille extends Model
{
    protected $table = 'echeances_grille';

    protected $fillable = ['grille_tarifaire_id', 'libelle', 'montant', 'date_limite'];

    public function grilleTarifaire()
    {
        return $this->belongsTo(GrilleTarifaire::class);
    }
}
