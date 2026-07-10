<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Affectation extends Model
{
    protected $fillable = [
        'enseignant_id', 'classe_id', 'matiere_id',
        'volume_horaire_hebdomadaire', 'est_classe_examen',
    ];

    public function enseignant()
    {
        return $this->belongsTo(Enseignant::class);
    }

    public function classe()
    {
        return $this->belongsTo(Classe::class, 'classe_id');
    }

    public function matiere()
    {
        return $this->belongsTo(Matiere::class);
    }

    public function creneaux()
    {
        return $this->hasMany(EmploiDuTemps::class);
    }

    public function getMontantHeuresSupAttribute()
    {
        if (!$this->est_classe_examen || !$this->enseignant->contratActif) {
            return 0;
        }
        $taux = $this->enseignant->contratActif->taux_horaire_heures_sup ?? 0;
        return $taux * $this->volume_horaire_hebdomadaire;
    }
}
