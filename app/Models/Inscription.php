<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Inscription extends Model
{
    protected $fillable = [
        'eleve_id', 'session_scolaire_id', 'classe_id',
        'type_inscription', 'statut', 'date_inscription',
    ];

    public function eleve()
    {
        return $this->belongsTo(Eleve::class);
    }

    public function sessionScolaire()
    {
        return $this->belongsTo(SessionScolaire::class);
    }

    public function classe()
    {
        return $this->belongsTo(Classe::class);
    }

    public function historiqueClasses()
    {
        return $this->hasMany(HistoriqueClasseEleve::class);
    }

    public function fraisEleves()
    {
        return $this->hasMany(FraisEleve::class);
    }

    public function scopeActive($query)
    {
        return $query->where('statut', 'active');
    }

    public function scopeSessionCourante($query)
    {
        return $query->whereHas('sessionScolaire', fn ($q) => $q->where('est_active', true));
    }
}
