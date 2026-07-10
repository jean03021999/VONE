<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Enseignant extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'etablissement_id', 'nom', 'prenom', 'matricule', 'date_naissance',
        'lieu_naissance', 'telephone', 'email', 'photo_path', 'diplome',
    ];

    public function etablissement()
    {
        return $this->belongsTo(Etablissement::class);
    }

    public function contrats()
    {
        return $this->hasMany(Contrat::class);
    }

    public function contratActif()
    {
        return $this->hasOne(Contrat::class)->where('statut', 'actif');
    }

    public function affectations()
    {
        return $this->hasMany(Affectation::class);
    }
}
