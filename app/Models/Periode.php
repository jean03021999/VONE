<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Periode extends Model
{
    protected $fillable = [
        'etablissement_id', 'session_scolaire_id', 'libelle',
        'date_debut', 'date_fin', 'statut',
    ];

    public function etablissement()
    {
        return $this->belongsTo(Etablissement::class);
    }

    public function sessionScolaire()
    {
        return $this->belongsTo(SessionScolaire::class);
    }

    public function evaluations()
    {
        return $this->hasMany(Evaluation::class);
    }
}
