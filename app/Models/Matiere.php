<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Matiere extends Model
{
    protected $fillable = ['etablissement_id', 'nom'];

    public function etablissement()
    {
        return $this->belongsTo(Etablissement::class);
    }

    public function affectations()
    {
        return $this->hasMany(Affectation::class);
    }
}
