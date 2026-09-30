<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class JustificatifDepense extends Model
{
    protected $table = 'justificatifs_depense';

    protected $fillable = ['depense_id', 'fichier_path', 'nom_original', 'type_mime', 'taille', 'ajoute_par'];

    protected $hidden = ['fichier_path'];

    protected $appends = ['url'];

    public function depense()
    {
        return $this->belongsTo(Depense::class);
    }

    public function auteur()
    {
        return $this->belongsTo(User::class, 'ajoute_par');
    }

    // Lien signe (valable 1 jour) : le fichier s'ouvre dans un nouvel onglet sans jeton, seulement
    // pour qui l'a recu d'une reponse authentifiee.
    public function getUrlAttribute(): string
    {
        return \Illuminate\Support\Facades\URL::temporarySignedRoute('justificatifs.fichier', today()->addDays(2), ['id' => $this->id]);
    }
}
