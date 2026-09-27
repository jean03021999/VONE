<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GrilleTarifaire extends Model
{
    protected $table = 'grilles_tarifaires';

    protected $fillable = [
        'etablissement_id', 'session_scolaire_id', 'classe_id', 'type_frais_id', 'montant',
        'applicable_a', 'actif',
    ];

    protected $casts = [
        'actif' => 'boolean',
    ];

    public function classe()
    {
        return $this->belongsTo(Classe::class, 'classe_id');
    }

    public function typeFrais()
    {
        return $this->belongsTo(TypeFrais::class);
    }

    public function echeances()
    {
        return $this->hasMany(EcheanceGrille::class);
    }
}

