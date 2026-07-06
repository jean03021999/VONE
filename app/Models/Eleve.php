<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Eleve extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'etablissement_id', 'classe_id', 'session_scolaire_id',
        'nom', 'prenom', 'matricule', 'date_naissance', 'lieu_naissance',
        'photo_path', 'statut_dossier',
    ];

    public function etablissement()
    {
        return $this->belongsTo(Etablissement::class);
    }

    public function classe()
    {
        return $this->belongsTo(Classe::class, 'classe_id');
    }

    public function sessionScolaire()
    {
        return $this->belongsTo(SessionScolaire::class);
    }

    public function filiations()
    {
        return $this->hasMany(EleveFiliation::class);
    }

    public function paiements()
    {
        return $this->hasMany(Paiement::class);
    }

    public function getStatutPaiementAttribute()
    {
        return $this->paiements()->exists() ? 'a_jour' : 'en_retard';
    }
}
