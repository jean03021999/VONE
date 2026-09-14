<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class HistoriqueClasseEleve extends Model
{
    protected $table = 'historique_classes_eleves';

    protected $fillable = [
        'inscription_id', 'ancienne_classe_id', 'nouvelle_classe_id',
        'motif', 'date_changement', 'user_id',
    ];

    public function inscription()
    {
        return $this->belongsTo(Inscription::class);
    }

    public function ancienneClasse()
    {
        return $this->belongsTo(Classe::class, 'ancienne_classe_id');
    }

    public function nouvelleClasse()
    {
        return $this->belongsTo(Classe::class, 'nouvelle_classe_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
