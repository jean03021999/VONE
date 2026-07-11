<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MatiereCoefficient extends Model
{
    protected $fillable = ['matiere_id', 'filiere_id', 'niveau', 'coefficient'];

    public function matiere()
    {
        return $this->belongsTo(Matiere::class);
    }

    public function filiere()
    {
        return $this->belongsTo(Filiere::class);
    }
}
