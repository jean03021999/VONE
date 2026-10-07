<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Etablissement extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'nom', 'code', 'sigle', 'type', 'pays', 'ville', 'adresse',
        'telephone', 'email', 'logo_path', 'langue_principale',
        'devise', 'statut', 'date_fin_essai',
        'quartier', 'region', 'prefecture', 'coordonnees_gps', 'telephone_secondaire', 'whatsapp_relance',
        'cycles', 'capacite_accueil', 'agrement', 'slogan',
    ];

    protected $casts = [
        'cycles' => 'array',
    ];

    protected $appends = ['logo_url'];

    // Horodatage dans l'URL : le navigateur recharge le logo apres un changement.
    public function getLogoUrlAttribute(): ?string
    {
        return $this->logo_path
            ? url("/api/etablissements/{$this->id}/logo") . '?v=' . $this->updated_at?->timestamp
            : null;
    }

    public function sessionsScolaires()
    {
        return $this->hasMany(SessionScolaire::class);
    }

    public function classes()
    {
        return $this->hasMany(Classe::class);
    }

    public function eleves()
    {
        return $this->hasMany(Eleve::class);
    }
}
