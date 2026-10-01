<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Relance extends Model
{
    protected $fillable = ['etablissement_id', 'eleve_id', 'canal', 'motif', 'montant_du', 'note', 'fait_par'];

    protected $casts = ['montant_du' => 'float'];

    public function eleve()
    {
        return $this->belongsTo(Eleve::class);
    }

    public function auteur()
    {
        return $this->belongsTo(User::class, 'fait_par');
    }
}
