<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Evaluation extends Model
{
    protected $fillable = [
        'code', 'affectation_id', 'periode_id', 'type',
        'libelle', 'date_evaluation', 'bareme', 'statut',
    ];

    protected static function booted()
    {
        static::creating(function ($evaluation) {
            if (empty($evaluation->code)) {
                $evaluation->code = 'EVAL-' . date('Y') . '-' . str_pad((static::max('id') + 1), 6, '0', STR_PAD_LEFT);
            }
        });
    }

    public function affectation()
    {
        return $this->belongsTo(Affectation::class);
    }

    public function periode()
    {
        return $this->belongsTo(Periode::class);
    }

    public function notes()
    {
        return $this->hasMany(Note::class);
    }

    public function historiqueValidations()
    {
        return $this->hasMany(HistoriqueValidation::class);
    }
}
