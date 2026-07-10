<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EmploiDuTemps extends Model
{
    protected $table = "emploi_du_temps";

    protected $fillable = [
        'affectation_id', 'jour', 'heure_debut', 'heure_fin',
    ];

    public function affectation()
    {
        return $this->belongsTo(Affectation::class);
    }
}
