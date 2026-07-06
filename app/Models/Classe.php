<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Classe extends Model
{
    protected $table = 'classes';

    protected $fillable = [
        'etablissement_id', 'session_scolaire_id', 'nom', 'niveau',
    ];

    public function etablissement()
    {
        return $this->belongsTo(Etablissement::class);
    }

    public function sessionScolaire()
    {
        return $this->belongsTo(SessionScolaire::class);
    }

    public function eleves()
    {
        return $this->hasMany(Eleve::class, 'classe_id');
    }
}
