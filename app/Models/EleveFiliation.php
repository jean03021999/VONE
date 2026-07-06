<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EleveFiliation extends Model
{
    protected $fillable = [
        'eleve_id', 'type_lien', 'nom_complet', 'telephone', 'lien_avec_eleve',
    ];

    public function eleve()
    {
        return $this->belongsTo(Eleve::class);
    }
}
