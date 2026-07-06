<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AppareilConfiance extends Model
{
    protected $table = 'appareils_confiance';

    protected $fillable = [
        'user_id', 'token_appareil', 'nom_appareil', 'date_expiration',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
