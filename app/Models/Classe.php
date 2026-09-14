<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class Classe extends Model
{
    protected $table = 'classes';

    protected $fillable = [
        'etablissement_id', 'session_scolaire_id', 'nom', 'niveau', 'filiere_id',
    ];

    public function etablissement()
    {
        return $this->belongsTo(Etablissement::class);
    }

    public function sessionScolaire()
    {
        return $this->belongsTo(SessionScolaire::class);
    }

    /**
     * Eleves actuellement inscrits dans cette classe, sur la session scolaire active,
     * via une inscription non annulee. Remplace l'ancienne relation directe sur
     * eleves.classe_id (voir elevesHistoriques() pour l'ancien comportement).
     */
    public function eleves()
    {
        return $this->hasManyThrough(
            Eleve::class,
            Inscription::class,
            'classe_id', // FK sur inscriptions -> classes.id
            'id',        // FK sur eleves -> eleves.id (cle primaire, pas de colonne inscription_id sur eleves)
            'id',        // cle locale sur classes
            'eleve_id'   // FK sur inscriptions -> eleves.id
        )
            ->where('inscriptions.statut', 'active')
            ->whereExists(function ($query) {
                $query->select(DB::raw(1))
                    ->from('sessions_scolaires')
                    ->whereColumn('sessions_scolaires.id', 'inscriptions.session_scolaire_id')
                    ->where('sessions_scolaires.est_active', true);
            });
    }

    /**
     * Ancienne relation directe sur eleves.classe_id, conservee telle quelle
     * (colonne encore presente en base) pour ne rien casser tant que les
     * controleurs n'ont pas ete migres vers inscriptions.
     */
    public function elevesHistoriques()
    {
        return $this->hasMany(Eleve::class, 'classe_id');
    }

    public function filiere()
    {
        return $this->belongsTo(Filiere::class);
    }
}


