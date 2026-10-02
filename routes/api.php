<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\EleveController;
use App\Http\Controllers\ClasseController;
use App\Http\Controllers\EleveImportController;
use App\Http\Controllers\EnseignantController;
use App\Http\Controllers\MatiereController;
use App\Http\Controllers\FraisController;
use App\Http\Controllers\EmploiDuTempsController;
use App\Http\Controllers\PeriodeController;
use App\Http\Controllers\EvaluationController;
use App\Http\Controllers\BulletinController;
use App\Http\Controllers\AffectationController;
use App\Http\Controllers\UtilisateurController;
use App\Http\Controllers\AbonnementController;
use App\Http\Controllers\StatsPubliquesController;
use App\Http\Controllers\SalaireController;
use App\Http\Controllers\ParametresController;
use App\Http\Controllers\ArreteCaisseController;
use App\Http\Controllers\CaisseController;
use App\Http\Controllers\RapportFinancierController;
use App\Http\Controllers\RelanceController;

Route::get('/user', function (Request $request) {
    $user = $request->user();
    $role = $user->roles()
        ->where('roles.etablissement_id', $user->etablissement_id)
        ->first();

    // Etablissement et session active : affiches dans l'en-tete de l'application.
    $session = \App\Models\SessionScolaire::where('etablissement_id', $user->etablissement_id)
        ->where('est_active', true)
        ->first();

    return response()->json([
        'user' => $user,
        'role' => $role ? strtoupper($role->nom) : null,
        'permissions' => $role ? $role->permissions()->pluck('nom') : [],
        // Fiche complete : en-tete de l'application et des documents imprimes (logo, coordonnees,
        // agrement, slogan) et alerte de capacite d'accueil.
        'etablissement' => $user->etablissement ? $user->etablissement->only([
            'id', 'nom', 'ville', 'quartier', 'adresse', 'telephone', 'telephone_secondaire', 'whatsapp_relance', 'email',
            'agrement', 'slogan', 'capacite_accueil', 'logo_url',
        ]) : null,
        'session' => $session?->libelle,
    ]);
})->middleware('auth:sanctum');

Route::post('/auth/login', [AuthController::class, 'login']);
Route::post('/auth/verifier-otp', [AuthController::class, 'verifierOtp']);
Route::post('/auth/renvoyer-otp', [AuthController::class, 'renvoyerOtp']);
Route::post('/auth/mot-de-passe-oublie', [AuthController::class, 'motDePasseOublie']);
Route::post('/auth/reinitialiser-mot-de-passe', [AuthController::class, 'reinitialiserMotDePasse']);

Route::get('/stats-publiques', [StatsPubliquesController::class, 'index']);

Route::middleware(['auth:sanctum'])->group(function () {
    Route::get('/eleves', [EleveController::class, 'index'])->middleware('permission:eleves.voir');
    Route::get('/eleves/{id}', [EleveController::class, 'show'])->middleware('permission:eleves.voir');
    Route::post('/eleves', [EleveController::class, 'store'])->middleware('permission:eleves.creer');
    Route::put('/eleves/{id}', [EleveController::class, 'update'])->middleware('permission:eleves.modifier');
    Route::delete('/eleves/{id}', [EleveController::class, 'destroy'])->middleware('permission:eleves.modifier');
    Route::post('/eleves/import/analyser', [EleveImportController::class, 'analyser'])->middleware('permission:eleves.importer');
    Route::post('/eleves/import/executer', [EleveImportController::class, 'executer'])->middleware('permission:eleves.importer');
    Route::get('/eleves/import/modele', [EleveImportController::class, 'telechargerModele']);
});

Route::middleware(['auth:sanctum'])->group(function () {
    Route::get('/classes', [ClasseController::class, 'index'])->middleware('permission:classes.voir');
    Route::post('/classes', [ClasseController::class, 'store'])->middleware('permission:classes.gerer');
    Route::put('/classes/{id}', [ClasseController::class, 'update'])->middleware('permission:classes.gerer');
    Route::delete('/classes/{id}', [ClasseController::class, 'destroy'])->middleware('permission:classes.gerer');
});

Route::middleware(['auth:sanctum'])->group(function () {
    Route::get('/enseignants', [EnseignantController::class, 'index'])->middleware('permission:enseignants.voir');
    Route::get('/enseignants/{id}', [EnseignantController::class, 'show'])->middleware('permission:enseignants.voir');
    Route::post('/enseignants', [EnseignantController::class, 'store'])->middleware('permission:enseignants.creer');
    Route::put('/enseignants/{id}', [EnseignantController::class, 'update'])->middleware('permission:enseignants.creer');
    Route::delete('/enseignants/{id}', [EnseignantController::class, 'destroy'])->middleware('permission:enseignants.creer');
    Route::put('/enseignants/{id}/contrat', [EnseignantController::class, 'updateContrat'])->middleware('permission:enseignants.contrats.gerer');
});

Route::middleware(['auth:sanctum'])->group(function () {
    Route::get('/matieres', [MatiereController::class, 'index'])->middleware('permission:enseignants.voir');
    Route::post('/matieres', [MatiereController::class, 'store'])->middleware('permission:matieres.gerer');
    Route::post('/filieres', [MatiereController::class, 'storeFiliere'])->middleware('permission:matieres.gerer');
    Route::put('/matieres/{id}', [MatiereController::class, 'update'])->middleware('permission:matieres.gerer');
    Route::delete('/matieres/{id}', [MatiereController::class, 'destroy'])->middleware('permission:matieres.gerer');
    Route::put('/coefficients/{id}', [MatiereController::class, 'updateCoefficient'])->middleware('permission:matieres.gerer');
    Route::delete('/coefficients/{id}', [MatiereController::class, 'destroyCoefficient'])->middleware('permission:matieres.gerer');
    Route::put('/filieres/{id}', [MatiereController::class, 'updateFiliere'])->middleware('permission:matieres.gerer');
    Route::delete('/filieres/{id}', [MatiereController::class, 'destroyFiliere'])->middleware('permission:matieres.gerer');
});

Route::middleware(['auth:sanctum'])->group(function () {
    Route::get('/frais/types', [FraisController::class, 'typesFrais'])->middleware('permission:frais.voir');
    Route::post('/frais/types', [FraisController::class, 'storeTypeFrais'])->middleware('permission:frais.creer');
    Route::get('/frais/grilles', [FraisController::class, 'grilles'])->middleware('permission:frais.voir');
    Route::post('/frais/grilles', [FraisController::class, 'storeGrille'])->middleware('permission:frais.creer');
    Route::post('/frais/grilles/{id}/synchroniser', [FraisController::class, 'synchroniserGrille'])->middleware('permission:frais.creer');
    Route::post('/frais/grilles/{id}/basculer', [FraisController::class, 'basculerGrille'])->middleware('permission:frais.creer');
    Route::put('/frais/grilles/{id}', [FraisController::class, 'updateGrille'])->middleware('permission:frais.creer');
    Route::delete('/frais/grilles/{id}', [FraisController::class, 'destroyGrille'])->middleware('permission:frais.creer');
    Route::put('/frais/types/{id}', [FraisController::class, 'updateTypeFrais'])->middleware('permission:frais.creer');
    Route::delete('/frais/types/{id}', [FraisController::class, 'destroyTypeFrais'])->middleware('permission:frais.creer');
    Route::post('/frais/paiements/annuler', [FraisController::class, 'annulerPaiements'])->middleware('permission:frais.paiement.enregistrer');
    Route::post('/frais/appliquer-inscription', [FraisController::class, 'appliquerInscription'])->middleware('permission:frais.creer');
    Route::post('/frais/annuler-inscription', [FraisController::class, 'annulerInscription'])->middleware('permission:frais.paiement.enregistrer');
    Route::get('/frais/eleves/{eleveId}', [FraisController::class, 'suiviEleve'])->middleware('permission:frais.voir');
    Route::post('/frais/paiements', [FraisController::class, 'enregistrerPaiement'])->middleware('permission:frais.paiement.enregistrer');
    Route::get('/frais/paiements/recent', [FraisController::class, 'paiementsRecent'])->middleware('permission:frais.voir');
    Route::get('/frais/paiements', [FraisController::class, 'paiements'])->middleware('permission:frais.voir');
    Route::get('/frais/stats-par-classe', [FraisController::class, 'statsParClasse'])->middleware('permission:frais.voir');
    Route::get('/frais/relances', [RelanceController::class, 'index'])->middleware('permission:frais.voir');
    Route::post('/frais/relances', [RelanceController::class, 'store'])->middleware('permission:frais.paiement.enregistrer');
    Route::get('/frais/relances/eleve/{eleveId}', [RelanceController::class, 'historique'])->middleware('permission:frais.voir');
});

Route::middleware(['auth:sanctum'])->group(function () {
    Route::get('/utilisateurs', [UtilisateurController::class, 'index']);
    Route::post('/utilisateurs', [UtilisateurController::class, 'store']);
    Route::put('/utilisateurs/{id}', [UtilisateurController::class, 'update']);
    Route::post('/utilisateurs/{id}/statut', [UtilisateurController::class, 'basculerStatut']);
    Route::post('/utilisateurs/{id}/mot-de-passe', [UtilisateurController::class, 'reinitialiserMotDePasse']);
    Route::get('/abonnement', [AbonnementController::class, 'show'])->middleware('permission:abonnement.voir');
});

Route::middleware(['auth:sanctum'])->group(function () {
    Route::get('/emploi-du-temps/{classeId}', [EmploiDuTempsController::class, 'index'])->middleware('permission:emploi_du_temps.voir');
    Route::get('/emploi-du-temps', [EmploiDuTempsController::class, 'classesPlanifiees'])->middleware('permission:emploi_du_temps.voir');
    // Export Excel reserve a ceux qui gerent l'emploi du temps (consultation seule = affichage uniquement).
    Route::get('/emploi-du-temps/{classeId}/export', [EmploiDuTempsController::class, 'exporter'])->middleware('permission:emploi_du_temps.gerer');
    Route::post('/emploi-du-temps', [EmploiDuTempsController::class, 'store'])->middleware('permission:emploi_du_temps.gerer');
    Route::delete('/emploi-du-temps/{id}', [EmploiDuTempsController::class, 'destroy'])->middleware('permission:emploi_du_temps.gerer');
});


Route::middleware(['auth:sanctum'])->group(function () {
    Route::get('/periodes', [PeriodeController::class, 'index']);
    Route::post('/periodes', [PeriodeController::class, 'store'])->middleware('permission:periodes.gerer');
    Route::put('/periodes/{id}', [PeriodeController::class, 'update'])->middleware('permission:periodes.gerer');
    Route::delete('/periodes/{id}', [PeriodeController::class, 'destroy'])->middleware('permission:periodes.gerer');

    Route::get('/mes-affectations', [EvaluationController::class, 'mesAffectations'])->middleware('permission:notes.voir');
    Route::get('/evaluations', [EvaluationController::class, 'index'])->middleware('permission:notes.voir');
    Route::post('/evaluations', [EvaluationController::class, 'store'])->middleware('permission:notes.saisir');
    Route::get('/evaluations/{id}', [EvaluationController::class, 'show'])->middleware('permission:notes.voir');
    Route::put('/evaluations/{id}/notes', [EvaluationController::class, 'saisirNotes'])->middleware('permission:notes.saisir');
    Route::post('/evaluations/{id}/soumettre', [EvaluationController::class, 'soumettre'])->middleware('permission:notes.soumettre');
    Route::post('/evaluations/{id}/valider', [EvaluationController::class, 'valider'])->middleware('permission:notes.valider');
    Route::post('/evaluations/{id}/rejeter', [EvaluationController::class, 'rejeter'])->middleware('permission:notes.valider');
    Route::post('/evaluations/{id}/reprendre', [EvaluationController::class, 'reprendre'])->middleware('permission:notes.saisir');
    Route::post('/evaluations/{id}/publier', [EvaluationController::class, 'publier'])->middleware('permission:notes.publier');
});

Route::middleware(['auth:sanctum'])->group(function () {
    Route::post('/bulletins/generer', [BulletinController::class, 'genererPourClasse'])->middleware('permission:bulletins.generer');
    Route::get('/bulletins/par-classe', [BulletinController::class, 'parClasse'])->middleware('permission:bulletins.voir');
    Route::get('/bulletins/{id}', [BulletinController::class, 'show'])->middleware('permission:bulletins.voir');
});

Route::middleware(['auth:sanctum'])->group(function () {
    Route::get('/affectations', [AffectationController::class, 'index'])->middleware('permission:enseignants.voir');
    Route::post('/affectations', [AffectationController::class, 'store'])->middleware('permission:affectations.gerer');
    Route::delete('/affectations/{id}', [AffectationController::class, 'destroy'])->middleware('permission:affectations.gerer');
});

Route::middleware(['auth:sanctum', 'permission:enseignants.salaires.voir'])->group(function () {
    Route::get('/salaires', [SalaireController::class, 'index']);
    Route::get('/salaires/{id}', [SalaireController::class, 'show']);
});

Route::middleware(['auth:sanctum', 'permission:enseignants.salaires.gerer'])->group(function () {
    Route::post('/salaires', [SalaireController::class, 'store']);
    Route::post('/salaires/{id}/payer', [SalaireController::class, 'payer']);
    Route::delete('/salaires/{id}', [SalaireController::class, 'destroy']);
    Route::put('/salaires/{id}', [SalaireController::class, 'update']);
    Route::post('/salaires/{id}/annuler', [SalaireController::class, 'annuler']);
});

// Parametres : profil et securite du compte connecte pour tous ; fiche etablissement et sessions
// reservees a la direction (verifie dans le controleur).
Route::get('/etablissements/{id}/logo', [ParametresController::class, 'logo']);
Route::get('/utilisateurs/{id}/photo', [ParametresController::class, 'photo'])->middleware('signed')->name('utilisateurs.photo');

Route::middleware(['auth:sanctum'])->prefix('parametres')->group(function () {
    Route::get('/', [ParametresController::class, 'index']);
    Route::put('/etablissement', [ParametresController::class, 'updateEtablissement']);
    Route::post('/etablissement/logo', [ParametresController::class, 'enregistrerLogo']);
    Route::delete('/etablissement/logo', [ParametresController::class, 'supprimerLogo']);
    Route::put('/profil', [ParametresController::class, 'updateProfil']);
    Route::post('/profil/photo', [ParametresController::class, 'enregistrerPhoto']);
    Route::delete('/profil/photo', [ParametresController::class, 'supprimerPhoto']);
    Route::put('/mot-de-passe', [ParametresController::class, 'changerMotDePasse']);
    Route::delete('/connexions/autres', [ParametresController::class, 'fermerAutresConnexions']);
    Route::delete('/connexions/{id}', [ParametresController::class, 'fermerConnexion']);
    Route::delete('/appareils', [ParametresController::class, 'oublierAppareils']);
    Route::delete('/appareils/{id}', [ParametresController::class, 'oublierAppareil']);
    Route::post('/sessions', [ParametresController::class, 'creerSession']);
    Route::post('/sessions/{id}/activer', [ParametresController::class, 'activerSession']);
});

// Caisse : solde (encaissements - salaires verses - depenses) et depenses de l'etablissement.
Route::middleware(['auth:sanctum'])->prefix('caisse')->group(function () {
    Route::get('/synthese', [CaisseController::class, 'synthese'])->middleware('permission:frais.voir');
    Route::get('/sorties', [CaisseController::class, 'sorties'])->middleware('permission:frais.voir');
    Route::post('/depenses', [CaisseController::class, 'storeDepense'])->middleware('permission:frais.paiement.enregistrer');
    Route::post('/depenses/{id}/annuler', [CaisseController::class, 'annulerDepense'])->middleware('permission:frais.paiement.enregistrer');
    Route::get('/depenses', [CaisseController::class, 'depenses'])->middleware('permission:frais.voir');
    Route::put('/depenses/{id}', [CaisseController::class, 'updateDepense'])->middleware('permission:frais.paiement.enregistrer');
    Route::post('/depenses/{id}/justificatifs', [CaisseController::class, 'ajouterJustificatifs'])->middleware('permission:frais.paiement.enregistrer');
    Route::delete('/justificatifs/{id}', [CaisseController::class, 'supprimerJustificatif'])->middleware('permission:frais.paiement.enregistrer');
    Route::get('/arretes', [ArreteCaisseController::class, 'index'])->middleware('permission:frais.voir');
    Route::get('/arretes/preparer', [ArreteCaisseController::class, 'preparer'])->middleware('permission:frais.voir');
    Route::post('/arretes', [ArreteCaisseController::class, 'store'])->middleware('permission:frais.paiement.enregistrer');
    Route::get('/evolution', [RapportFinancierController::class, 'evolution'])->middleware('permission:frais.voir');
});
Route::get('/caisse/justificatifs/{id}/fichier', [CaisseController::class, 'fichierJustificatif'])->middleware('signed')->name('justificatifs.fichier');
