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
        'date_fin_essai' => 'date:Y-m-d',
    ];

    protected $appends = ['logo_url'];

    /** Periode d'essai offerte a l'installation (un mois). */
    public const JOURS_ESSAI = 30;

    /** Rappel a l'ecole pendant les derniers jours de l'essai. */
    public const JOURS_RAPPEL_ESSAI = 7;

    /**
     * Etat de l'abonnement pour l'application : jours restants d'essai (date de fin comprise),
     * rappel pendant les 7 derniers jours, lecture seule une fois l'essai termine ou le compte
     * suspendu (consultation et impression restent possibles, aucun enregistrement).
     */
    public function etatAbonnement(): array
    {
        $fin = $this->date_fin_essai ? \Carbon\Carbon::parse($this->date_fin_essai)->startOfDay() : null;
        $joursRestants = $this->statut === 'essai' && $fin
            ? max(0, (int) today()->diffInDays($fin, false) + 1)
            : null;
        $essaiTermine = $this->statut === 'essai' && $fin && $joursRestants === 0;

        return [
            'statut' => $this->statut,
            'date_fin_essai' => $fin?->toDateString(),
            'jours_restants' => $joursRestants,
            'rappel' => $joursRestants !== null && $joursRestants > 0 && $joursRestants <= self::JOURS_RAPPEL_ESSAI,
            'lecture_seule' => $essaiTermine || $this->statut === 'suspendu',
        ];
    }

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
