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

    /**
     * Ordre pedagogique : Maternelle (Petite/Moyenne/Grande Section) puis
     * Primaire+College+Lycee, au lieu de l'ordre d'insertion en base.
     * A niveau egal, tri alphabetique par nom (A, B, ...).
     *
     * Reconnait a la fois l'ancien schema de niveau ("6eme annee", 1-12 a plat,
     * cree avant la migration vers la nomenclature guineenne officielle) et le
     * nouveau ("6ème Année", avec primaire a 6 ans et series au lycee/terminale).
     * Les deux schemas ne se recouvrent pas terme a terme (l'ancien fait demarrer
     * le college a la 6eme annee alors que le nouveau y place la fin du primaire),
     * donc chaque schema est classe dans son propre bloc plutot que fusionne.
     */
    public function scopeOrdonneesPedagogiquement($query)
    {
        $ordre = [
            'Petite Section', 'Moyenne Section', 'Grande Section',
            '1ère Année', '2ème Année', '3ème Année', '4ème Année', '5ème Année', '6ème Année',
            '7ème Année', '8ème Année', '9ème Année', '10ème Année',
            '11ème Année - Série Scientifique', '11ème Année - Série Littéraire',
            '12ème Année - Série Scientifique', '12ème Année - Série Littéraire',
            'Terminale - Sciences Mathématiques', 'Terminale - Sciences Sociales', 'Terminale - Sciences Expérimentales',
        ];

        $whens = [];
        $bindings = [];
        foreach ($ordre as $rang => $niveau) {
            $whens[] = 'WHEN ? THEN ' . $rang;
            $bindings[] = $niveau;
        }
        $sql = 'CASE niveau ' . implode(' ', $whens) . ' ELSE 999 END';

        return $query->orderByRaw($sql, $bindings)->orderBy('nom');
    }
}


