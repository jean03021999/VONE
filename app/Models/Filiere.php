<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Filiere extends Model
{
    protected $fillable = ['etablissement_id', 'nom', 'niveau_a_partir_de'];

    public function etablissement()
    {
        return $this->belongsTo(Etablissement::class);
    }

    public function classes()
    {
        return $this->hasMany(Classe::class);
    }

    public function coefficients()
    {
        return $this->hasMany(MatiereCoefficient::class);
    }
}
