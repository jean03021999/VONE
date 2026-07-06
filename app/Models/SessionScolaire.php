<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SessionScolaire extends Model
{
    protected $table = 'sessions_scolaires';

    protected $fillable = [
        'etablissement_id', 'libelle', 'date_debut', 'date_fin',
        'statut', 'est_active',
    ];

    public function etablissement()
    {
        return $this->belongsTo(Etablissement::class);
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
