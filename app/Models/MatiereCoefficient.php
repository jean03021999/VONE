<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MatiereCoefficient extends Model
{
    protected $fillable = ['matiere_id', 'filiere_id', 'niveau', 'coefficient', 'compte_dans_moyenne'];

    protected $casts = ['compte_dans_moyenne' => 'boolean'];

    public function matiere()
    {
        return $this->belongsTo(Matiere::class);
    }

    public function filiere()
    {
        return $this->belongsTo(Filiere::class);
    }
}
