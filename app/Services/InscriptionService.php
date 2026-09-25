<?php

namespace App\Services;

use App\Models\Classe;
use App\Models\Eleve;
use App\Models\HistoriqueClasseEleve;
use App\Models\Inscription;
use Illuminate\Support\Facades\Auth;
use Exception;

class InscriptionService
{
    public function inscrire(Eleve $eleve, Classe $classe, ?string $dateInscription = null): Inscription
    {
        $inscriptionExistante = Inscription::where('eleve_id', $eleve->id)
            ->where('session_scolaire_id', $classe->session_scolaire_id)
            ->where('statut', 'active')
            ->exists();

        if ($inscriptionExistante) {
            throw new Exception('Une inscription active existe deja pour cet eleve sur cette session.');
        }

        $inscription = Inscription::create([
            'eleve_id' => $eleve->id,
            'session_scolaire_id' => $classe->session_scolaire_id,
            'classe_id' => $classe->id,
            'type_inscription' => 'nouvelle',
            'statut' => 'active',
            'date_inscription' => $dateInscription ?? now()->toDateString(),
        ]);

        // L'eleve recoit tout de suite les frais (scolarite...) des grilles deja en place pour sa classe.
        (new FraisService())->appliquerGrillesAInscription($inscription);

        return $inscription;
    }

    public function reinscrire(Eleve $eleve, Classe $classe): Inscription
    {
        $inscriptionAnterieure = Inscription::where('eleve_id', $eleve->id)
            ->orderByDesc('date_inscription')
            ->first();

        if (! $inscriptionAnterieure) {
            throw new Exception('Aucune inscription anterieure trouvee pour cet eleve.');
        }

        $nouvelleInscription = Inscription::create([
            'eleve_id' => $eleve->id,
            'session_scolaire_id' => $classe->session_scolaire_id,
            'classe_id' => $classe->id,
            'type_inscription' => 'reinscription',
            'statut' => 'active',
            'date_inscription' => now()->toDateString(),
        ]);

        HistoriqueClasseEleve::create([
            'inscription_id' => $nouvelleInscription->id,
            'ancienne_classe_id' => $inscriptionAnterieure->classe_id,
            'nouvelle_classe_id' => $classe->id,
            'motif' => 'Reinscription',
            'date_changement' => now()->toDateString(),
            'user_id' => Auth::id(),
        ]);

        (new FraisService())->appliquerGrillesAInscription($nouvelleInscription);

        return $nouvelleInscription;
    }

    public function changerClasse(Eleve $eleve, Classe $nouvelleClasse, string $motif): Inscription
    {
        $inscriptionActive = $eleve->inscriptionActive;

        if (! $inscriptionActive) {
            throw new Exception('Aucune inscription active trouvee pour cet eleve sur la session en cours.');
        }

        $ancienneClasseId = $inscriptionActive->classe_id;

        $inscriptionActive->update(['classe_id' => $nouvelleClasse->id]);

        HistoriqueClasseEleve::create([
            'inscription_id' => $inscriptionActive->id,
            'ancienne_classe_id' => $ancienneClasseId,
            'nouvelle_classe_id' => $nouvelleClasse->id,
            'motif' => $motif,
            'date_changement' => now()->toDateString(),
            'user_id' => Auth::id(),
        ]);

        return $inscriptionActive;
    }

    public function corrigerClasse(Eleve $eleve, Classe $classeCorrigee): Inscription
    {
        $inscriptionActive = $eleve->inscriptionActive;

        if (! $inscriptionActive) {
            throw new Exception('Aucune inscription active trouvee pour cet eleve sur la session en cours.');
        }

        $inscriptionActive->update(['classe_id' => $classeCorrigee->id]);

        return $inscriptionActive;
    }
}
