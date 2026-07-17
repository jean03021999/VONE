<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Bulletin extends Model
{
    protected $fillable = [
        'etablissement_id', 'eleve_id', 'periode_id', 'est_annuel',
        'moyenne', 'rang', 'effectif_classe', 'version', 'statut',
        'remplacee_par_id', 'date_remplacement', 'genere_par',
    ];

    public function eleve()
    {
        return $this->belongsTo(Eleve::class);
    }

    public function periode()
    {
        return $this->belongsTo(Periode::class);
    }

    public function lignes()
    {
        return $this->hasMany(LigneBulletin::class);
    }

    public function remplacePar()
    {
        return $this->belongsTo(Bulletin::class, 'remplacee_par_id');
    }

    public function generateur()
    {
        return $this->belongsTo(User::class, 'genere_par');
    }
}
