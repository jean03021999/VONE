<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Eleve extends Model
{
    use SoftDeletes;

    protected $with = ['inscriptionActive.classe'];

    protected $fillable = [
        'etablissement_id',
        'nom', 'prenom', 'matricule', 'date_naissance', 'lieu_naissance',
        'photo_path', 'statut_dossier',
    ];

    public function etablissement()
    {
        return $this->belongsTo(Etablissement::class);
    }

    public function inscriptions()
    {
        return $this->hasMany(Inscription::class);
    }

    public function inscriptionActive()
    {
        return $this->hasOne(Inscription::class)
            ->whereHas('sessionScolaire', fn ($q) => $q->where('est_active', true))
            ->where('statut', 'active');
    }

    public function getClasseIdAttribute()
    {
        return $this->inscriptionActive?->classe_id;
    }

    public function getSessionScolaireIdAttribute()
    {
        return $this->inscriptionActive?->session_scolaire_id;
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

        foreach ($echeances as $echeance) {
            if ($echeance->statut !== 'payee') {
                return 'a_echoir';
            }
        }

        return 'a_jour';
    }
}

