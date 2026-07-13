<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class HistoriqueValidation extends Model
{
    protected $table = 'historique_validations';

    protected $fillable = [
        'evaluation_id', 'user_id', 'statut_precedent', 'nouveau_statut', 'commentaire',
    ];

    public function evaluation()
    {
        return $this->belongsTo(Evaluation::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
