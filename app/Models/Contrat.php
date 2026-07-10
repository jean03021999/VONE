<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Contrat extends Model
{
    protected $fillable = [
        'enseignant_id', 'type', 'date_debut', 'date_fin',
        'salaire_base', 'taux_horaire_heures_sup', 'statut',
    ];

    public function enseignant()
    {
        return $this->belongsTo(Enseignant::class);
    }
}
