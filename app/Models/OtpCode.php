<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OtpCode extends Model
{
    protected $table = 'otp_codes';

    protected $fillable = [
        'user_id', 'code', 'type', 'expire_at', 'utilise',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
