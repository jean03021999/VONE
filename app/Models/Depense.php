<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Depense extends Model
{
    protected $fillable = [
        'etablissement_id', 'date_depense', 'categorie', 'libelle', 'montant', 'moyen_paiement',
        'beneficiaire', 'reference', 'observation', 'enregistre_par', 'annule_le', 'annule_par',
        'motif_annulation',
    ];

    protected $casts = [
        'date_depense' => 'date:Y-m-d',
        'annule_le' => 'datetime',
    ];

    /** Depenses comptant reellement (hors annulees). */
    public function scopeValides($query)
    {
        return $query->whereNull('annule_le');
    }

    public function auteur()
    {
        return $this->belongsTo(User::class, 'enregistre_par');
    }

    public function annulateur()
    {
        return $this->belongsTo(User::class, 'annule_par');
    }
}
