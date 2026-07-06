<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Role extends Model
{
    protected $fillable = [
        'etablissement_id', 'nom', 'description', 'est_modele',
    ];

    public function etablissement()
    {
        return $this->belongsTo(Etablissement::class);
    }

    public function permissions()
    {
        return $this->belongsToMany(Permission::class, 'role_permission');
    }

    public function users()
    {
        return $this->belongsToMany(User::class, 'user_role');
    }
}
