<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use Notifiable, HasApiTokens;

    protected $fillable = [
        'etablissement_id', 'name', 'email', 'telephone', 'photo_path', 'password', 'statut',
        'created_by', 'updated_by',
    ];

    protected $hidden = [
        'password', 'remember_token', 'photo_path',
    ];

    protected $appends = ['photo_url'];

    // Lien signe : la photo s'affiche dans un <img> sans jeton, mais seulement pour qui a recu le
    // lien d'une reponse authentifiee. Expiration calee sur le jour (7 a 8 jours) : le lien reste
    // identique toute la journee et le navigateur garde la photo en cache.
    public function getPhotoUrlAttribute(): ?string
    {
        if (!$this->photo_path) {
            return null;
        }

        return \Illuminate\Support\Facades\URL::temporarySignedRoute(
            'utilisateurs.photo',
            today()->addDays(8),
            ['id' => $this->id, 'v' => $this->updated_at?->timestamp]
        );
    }

    public function etablissement()
    {
        return $this->belongsTo(Etablissement::class);
    }

    public function roles()
    {
        return $this->belongsToMany(Role::class, 'user_role');
    }

    /** Le role de l'utilisateur dans son etablissement donne-t-il cette permission ? (comme VerifierPermission) */
    public function aPermission(string $permission): bool
    {
        return $this->etablissement_id !== null && $this->roles()
            ->where('roles.etablissement_id', $this->etablissement_id)
            ->whereHas('permissions', fn ($q) => $q->where('nom', $permission))
            ->exists();
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

