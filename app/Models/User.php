<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use Notifiable, HasApiTokens;

    protected $fillable = [
        'etablissement_id', 'name', 'email', 'telephone', 'password', 'statut',
        'created_by', 'updated_by',
    ];

    protected $hidden = [
        'password', 'remember_token',
    ];

    public function etablissement()
    {
        return $this->belongsTo(Etablissement::class);
    }

    public function roles()
    {
        return $this->belongsToMany(Role::class, 'user_role');
    }

    public function appareilsConfiance()
    {
        return $this->hasMany(AppareilConfiance::class);
    }

    public function otpCodes()
    {
        return $this->hasMany(OtpCode::class);
    }
}

