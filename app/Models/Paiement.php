<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Paiement extends Model
{
    protected $fillable = [
        'eleve_id', 'echeance_eleve_id', 'libelle', 'montant', 'moyen_paiement', 'date_paiement',
        'reference', 'caissier_id', 'observation', 'annule_le', 'annule_par', 'motif_annulation',
    ];

    protected $casts = [
        'annule_le' => 'datetime',
    ];

    /** Paiement encaisse avant LAKOLI et repris a l'import Excel : hors caisse. */
    public const MOYEN_REPRISE = 'reprise';

    /** Paiements comptant reellement (hors annules). */
    public function scopeValides($query)
    {
        return $query->whereNull('annule_le');
    }

    /** Argent passe par la caisse de LAKOLI : paiements valides, hors reprises de l'existant. */
    public function scopeEncaisses($query)
    {
        return $query->whereNull('annule_le')->where('moyen_paiement', '!=', self::MOYEN_REPRISE);
    }

    public function annulateur()
    {
        return $this->belongsTo(User::class, 'annule_par');
    }

    // withTrashed : un doublon supprime (archive) garde ses paiements annules visibles au journal.
    public function eleve()
    {
        return $this->belongsTo(Eleve::class)->withTrashed();
    }

    public function caissier()
    {
        return $this->belongsTo(User::class, 'caissier_id');
    }

    public function echeanceEleve()
    {
        return $this->belongsTo(EcheanceEleve::class);
    }
}


