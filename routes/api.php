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

Route::get('/user', function (Request $request) {
    $user = $request->user();
    $role = $user->roles()
        ->where('roles.etablissement_id', $user->etablissement_id)
        ->first();

    return response()->json([
        'user' => $user,
        'role' => $role ? strtoupper($role->nom) : null,
        'permissions' => $role ? $role->permissions()->pluck('nom') : [],
    ]);
})->middleware('auth:sanctum');

Route::post('/auth/login', [AuthController::class, 'login']);
Route::post('/auth/verifier-otp', [AuthController::class, 'verifierOtp']);
Route::post('/auth/renvoyer-otp', [AuthController::class, 'renvoyerOtp']);
Route::post('/auth/mot-de-passe-oublie', [AuthController::class, 'motDePasseOublie']);
Route::post('/auth/reinitialiser-mot-de-passe', [AuthController::class, 'reinitialiserMotDePasse']);

Route::get('/stats-publiques', [StatsPubliquesController::class, 'index']);

Route::middleware(['auth:sanctum', 'permission:eleves.voir'])->get('/test-permission', function () {
    return response()->json(['message' => 'Acces autorise, vous avez la permission eleves.voir']);
});

Route::middleware(['auth:sanctum', 'permission:paiements.supprimer'])->get('/test-permission-refusee', function () {
    return response()->json(['message' => 'Ceci ne devrait jamais s afficher']);
});

Route::middleware(['auth:sanctum'])->group(function () {
    Route::get('/eleves', [EleveController::class, 'index'])->middleware('permission:eleves.voir');
    Route::get('/eleves/{id}', [EleveController::class, 'show'])->middleware('permission:eleves.voir');
    Route::post('/eleves', [EleveController::class, 'store'])->middleware('permission:eleves.creer');
    Route::put('/eleves/{id}', [EleveController::class, 'update'])->middleware('permission:eleves.modifier');
    Route::post('/eleves/import/analyser', [EleveImportController::class, 'analyser'])->middleware('permission:eleves.importer');
    Route::post('/eleves/import/executer', [EleveImportController::class, 'executer'])->middleware('permission:eleves.importer');
    Route::get('/eleves/import/modele', [EleveImportController::class, 'telechargerModele']);
});

Route::middleware(['auth:sanctum'])->group(function () {
    Route::get('/classes', [ClasseController::class, 'index'])->middleware('permission:classes.voir');
    Route::post('/classes', [ClasseController::class, 'store'])->middleware('permission:classes.gerer');
});

Route::middleware(['auth:sanctum'])->group(function () {
    Route::get('/enseignants', [EnseignantController::class, 'index'])->middleware('permission:enseignants.voir');
    Route::get('/enseignants/{id}', [EnseignantController::class, 'show'])->middleware('permission:enseignants.voir');
    Route::post('/enseignants', [EnseignantController::class, 'store'])->middleware('permission:enseignants.creer');
    Route::put('/enseignants/{id}', [EnseignantController::class, 'update'])->middleware('permission:enseignants.creer');
});

Route::middleware(['auth:sanctum'])->group(function () {
    Route::get('/matieres', [MatiereController::class, 'index'])->middleware('permission:enseignants.voir');
    Route::post('/matieres', [MatiereController::class, 'store'])->middleware('permission:matieres.gerer');
    Route::post('/filieres', [MatiereController::class, 'storeFiliere'])->middleware('permission:matieres.gerer');
});

Route::middleware(['auth:sanctum'])->group(function () {
    Route::get('/frais/types', [FraisController::class, 'typesFrais'])->middleware('permission:frais.voir');
    Route::post('/frais/types', [FraisController::class, 'storeTypeFrais'])->middleware('permission:frais.creer');
    Route::get('/frais/grilles', [FraisController::class, 'grilles'])->middleware('permission:frais.voir');
    Route::post('/frais/grilles', [FraisController::class, 'storeGrille'])->middleware('permission:frais.creer');
    Route::post('/frais/grilles/{id}/synchroniser', [FraisController::class, 'synchroniserGrille'])->middleware('permission:frais.creer');
    Route::post('/frais/appliquer-inscription', [FraisController::class, 'appliquerInscription'])->middleware('permission:frais.creer');
    Route::get('/frais/eleves/{eleveId}', [FraisController::class, 'suiviEleve'])->middleware('permission:frais.voir');
    Route::post('/frais/paiements', [FraisController::class, 'enregistrerPaiement'])->middleware('permission:frais.paiement.enregistrer');
    Route::get('/frais/paiements/recent', [FraisController::class, 'paiementsRecent'])->middleware('permission:frais.voir');
    Route::get('/frais/paiements', [FraisController::class, 'paiements'])->middleware('permission:frais.voir');
    Route::get('/frais/stats-par-classe', [FraisController::class, 'statsParClasse'])->middleware('permission:frais.voir');
});

Route::middleware(['auth:sanctum'])->group(function () {
    Route::get('/utilisateurs', [UtilisateurController::class, 'index']);
    Route::get('/abonnement', [AbonnementController::class, 'show'])->middleware('permission:abonnement.voir');
});

Route::middleware(['auth:sanctum'])->group(function () {
    Route::get('/emploi-du-temps/{classeId}', [EmploiDuTempsController::class, 'index'])->middleware('permission:emploi_du_temps.voir');
    Route::post('/emploi-du-temps', [EmploiDuTempsController::class, 'store'])->middleware('permission:emploi_du_temps.gerer');
    Route::delete('/emploi-du-temps/{id}', [EmploiDuTempsController::class, 'destroy'])->middleware('permission:emploi_du_temps.gerer');
});


Route::middleware(['auth:sanctum'])->group(function () {
    Route::get('/periodes', [PeriodeController::class, 'index']);
    Route::post('/periodes', [PeriodeController::class, 'store'])->middleware('permission:periodes.gerer');

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
