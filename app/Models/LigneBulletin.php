<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LigneBulletin extends Model
{
    protected $table = 'lignes_bulletin';

    protected $fillable = [
        'bulletin_id', 'matiere_id', 'coefficient', 'moyenne_matiere', 'valeur_ponderee',
    ];

    public function bulletin()
    {
        return $this->belongsTo(Bulletin::class);
    }

    public function matiere()
    {
        return $this->belongsTo(Matiere::class);
    }
}
