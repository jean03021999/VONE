<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ArreteCaisse extends Model
{
    protected $table = 'arretes_caisse';

    protected $fillable = [
        'etablissement_id', 'date_arrete', 'entrees', 'sorties', 'nombre_versements', 'especes_veille',
        'especes_theoriques', 'especes_comptees', 'ecart', 'billetage', 'observation', 'arrete_par',
    ];

    protected $casts = [
        'date_arrete' => 'date:Y-m-d',
        'entrees' => 'array',
        'sorties' => 'array',
        'billetage' => 'array',
        'especes_veille' => 'float',
        'especes_theoriques' => 'float',
        'especes_comptees' => 'float',
        'ecart' => 'float',
    ];

    public function auteur()
    {
        return $this->belongsTo(User::class, 'arrete_par');
    }
}
