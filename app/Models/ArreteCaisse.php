<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ArreteCaisse extends Model
{
    protected $table = 'arretes_caisse';

    protected $fillable = [
        'etablissement_id', 'date_arrete', 'entrees', 'sorties', 'nombre_versements', 'especes_veille',
        'especes_theoriques', 'especes_comptees', 'ecart', 'billetage', 'observation', 'arrete_par',
    ];

    protected $casts = [
        'date_arrete' => 'date:Y-m-d',
        'entrees' => 'array',
        'sorties' => 'array',
        'billetage' => 'array',
        'especes_veille' => 'float',
        'especes_theoriques' => 'float',
        'especes_comptees' => 'float',
        'ecart' => 'float',
    ];

    public function auteur()
    {
        return $this->belongsTo(User::class, 'arrete_par');
    }

    /**
     * Refuse (422) toute operation de caisse (paiement, annulation, depense, salaire) datee du
     * dernier jour arrete ou d'avant : les especes attendues d'un arrete cumulent tous les jours
     * precedents, une operation antidatee fausserait donc aussi les arretes suivants. Pour corriger,
     * rouvrir le dernier arrete (ArreteCaisseController::rouvrir), puis l'arreter a nouveau.
     */
    public static function exigerJourOuvert(int $etablissementId, $date): void
    {
        if (!$date) {
            return;
        }
        $jour = substr((string) ($date instanceof \DateTimeInterface ? $date->format('Y-m-d') : $date), 0, 10);
        $dernier = self::dernierJourArrete($etablissementId);
        if ($dernier && $jour <= $dernier) {
            abort(422, 'La caisse est arrêtée jusqu\'au ' . \Carbon\Carbon::parse($dernier)->format('d/m/Y')
                . ' : aucune opération ne peut être datée de cette journée ou d\'avant. Pour corriger, rouvrez le dernier arrêté dans « Arrêté de caisse », puis arrêtez la caisse à nouveau.');
        }
    }

    /** Date (Y-m-d) du dernier arrete de l'etablissement, ou null. */
    public static function dernierJourArrete(int $etablissementId): ?string
    {
        $date = self::where('etablissement_id', $etablissementId)->max('date_arrete');

        return $date ? substr((string) $date, 0, 10) : null;
    }
}
