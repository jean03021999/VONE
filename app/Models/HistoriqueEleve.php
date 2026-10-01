<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class HistoriqueEleve extends Model
{
    protected $table = 'historique_eleves';

    protected $fillable = ['eleve_id', 'action', 'description', 'montant', 'motif', 'user_id'];

    protected $casts = ['montant' => 'float'];

    public function auteur()
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
