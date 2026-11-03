<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FicheTarifaire extends Model
{
    protected $table = 'fiches_tarifaires';

    protected $fillable = [
        'etablissement_id', 'session_scolaire_id', 'nom', 'mode_paiement', 'mode_saisie',
        'montant_saisi', 'mois', 'autres_frais',
    ];

    protected $casts = [
        'mois' => 'array',
        'autres_frais' => 'array',
    ];

    public function classes()
    {
        return $this->belongsToMany(Classe::class, 'fiche_tarifaire_classe')->withTimestamps();
    }

    public function grilles()
    {
        return $this->hasMany(GrilleTarifaire::class);
    }

    public function sessionScolaire()
    {
        return $this->belongsTo(SessionScolaire::class);
    }
}
