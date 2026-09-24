<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Salaire extends Model
{
    protected $fillable = [
        'enseignant_id', 'etablissement_id', 'mois', 'annee', 'type_remuneration',
        'salaire_base', 'nb_heures', 'taux_horaire', 'nb_heures_supp', 'taux_heure_supp',
        'montant_net', 'moyen_paiement', 'date_paiement', 'statut', 'reference', 'observation',
    ];

    protected $casts = [
        'mois' => 'integer',
        'annee' => 'integer',
        'date_paiement' => 'date:Y-m-d',
    ];

    public function enseignant()
    {
        return $this->belongsTo(Enseignant::class);
    }

    public function etablissement()
    {
        return $this->belongsTo(Etablissement::class);
    }

    // Fixe : salaire de base + heures supp. Horaire : heures x taux + heures supp.
    public function getMontantCalculeAttribute()
    {
        $heuresSupp = (float) ($this->nb_heures_supp ?? 0) * (float) ($this->taux_heure_supp ?? 0);

        if ($this->type_remuneration === 'horaire') {
            return (float) ($this->nb_heures ?? 0) * (float) ($this->taux_horaire ?? 0) + $heuresSupp;
        }

        return (float) ($this->salaire_base ?? 0) + $heuresSupp;
    }
}
