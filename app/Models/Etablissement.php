<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Etablissement extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'nom', 'code', 'type', 'pays', 'ville', 'adresse',
        'telephone', 'email', 'logo_path', 'langue_principale',
        'devise', 'statut', 'date_fin_essai',
    ];

    public function sessionsScolaires()
    {
        return $this->hasMany(SessionScolaire::class);
    }

    public function classes()
    {
        return $this->hasMany(Classe::class);
    }

    public function eleves()
    {
        return $this->hasMany(Eleve::class);
    }
}
