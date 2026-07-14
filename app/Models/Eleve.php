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

    public function fraisEleves()
    {
        return $this->hasMany(FraisEleve::class);
    }

    public function getStatutPaiementAttribute()
    {
        $echeances = EcheanceEleve::whereIn('frais_eleve_id', $this->fraisEleves()->pluck('id'))->get();

        if ($echeances->isEmpty()) {
            return 'aucun_frais';
        }

        foreach ($echeances as $echeance) {
            if ($echeance->statut === 'en_retard') {
                return 'en_retard';
            }
        }

        return 'a_jour';
    }
}

