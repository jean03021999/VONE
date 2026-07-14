<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TypeFrais extends Model
{
    protected $table = 'types_frais';

    protected $fillable = ['etablissement_id', 'nom'];

    public function etablissement()
    {
        return $this->belongsTo(Etablissement::class);
    }
}

