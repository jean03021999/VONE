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

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

Route::post('/auth/login', [AuthController::class, 'login']);
Route::post('/auth/verifier-otp', [AuthController::class, 'verifierOtp']);
Route::post('/auth/renvoyer-otp', [AuthController::class, 'renvoyerOtp']);
Route::post('/auth/mot-de-passe-oublie', [AuthController::class, 'motDePasseOublie']);
Route::post('/auth/reinitialiser-mot-de-passe', [AuthController::class, 'reinitialiserMotDePasse']);

Route::middleware(['auth:sanctum', 'permission:eleves.voir'])->get('/test-permission', function () {
    return response()->json(['message' => 'Acces autorise, vous avez la permission eleves.voir']);
});

Route::middleware(['auth:sanctum', 'permission:paiements.supprimer'])->get('/test-permission-refusee', function () {
    return response()->json(['message' => 'Ceci ne devrait jamais s afficher']);
});

Route::middleware(['auth:sanctum'])->group(function () {
    Route::get('/eleves', [EleveController::class, 'index'])->middleware('permission:eleves.voir');
    Route::get('/eleves/{id}', [EleveController::class, 'show'])->middleware('permission:eleves.voir');
    Route::post('/eleves', [EleveController::class, 'store'])->middleware('permission:eleves.gerer');
    Route::put('/eleves/{id}', [EleveController::class, 'update'])->middleware('permission:eleves.gerer');
    Route::post('/eleves/import/analyser', [EleveImportController::class, 'analyser'])->middleware('permission:eleves.gerer');
    Route::post('/eleves/import/executer', [EleveImportController::class, 'executer'])->middleware('permission:eleves.gerer');
    Route::get('/eleves/import/modele', [EleveImportController::class, 'telechargerModele']);
});

Route::middleware(['auth:sanctum'])->group(function () {
    Route::get('/classes', [ClasseController::class, 'index']);
    Route::post('/classes', [ClasseController::class, 'store'])->middleware('permission:enseignants.gerer');
});

Route::middleware(['auth:sanctum'])->group(function () {
    Route::get('/enseignants', [EnseignantController::class, 'index'])->middleware('permission:enseignants.voir');
    Route::get('/enseignants/{id}', [EnseignantController::class, 'show'])->middleware('permission:enseignants.voir');
    Route::post('/enseignants', [EnseignantController::class, 'store'])->middleware('permission:enseignants.gerer');
    Route::put('/enseignants/{id}', [EnseignantController::class, 'update'])->middleware('permission:enseignants.gerer');
});

Route::middleware(['auth:sanctum'])->group(function () {
    Route::get('/matieres', [MatiereController::class, 'index'])->middleware('permission:enseignants.voir');
    Route::post('/matieres', [MatiereController::class, 'store'])->middleware('permission:enseignants.gerer');
    Route::post('/filieres', [MatiereController::class, 'storeFiliere'])->middleware('permission:enseignants.gerer');
});

Route::middleware(['auth:sanctum'])->group(function () {
    Route::get('/frais/types', [FraisController::class, 'typesFrais']);
    Route::post('/frais/types', [FraisController::class, 'storeTypeFrais'])->middleware('permission:eleves.gerer');
    Route::get('/frais/grilles', [FraisController::class, 'grilles']);
    Route::post('/frais/grilles', [FraisController::class, 'storeGrille'])->middleware('permission:eleves.gerer');
    Route::get('/frais/eleves/{eleveId}', [FraisController::class, 'suiviEleve']);
    Route::post('/frais/paiements', [FraisController::class, 'enregistrerPaiement'])->middleware('permission:eleves.gerer');
});

Route::middleware(['auth:sanctum'])->group(function () {
    Route::get('/emploi-du-temps/{classeId}', [EmploiDuTempsController::class, 'index']);
    Route::post('/emploi-du-temps', [EmploiDuTempsController::class, 'store'])->middleware('permission:enseignants.gerer');
    Route::delete('/emploi-du-temps/{id}', [EmploiDuTempsController::class, 'destroy'])->middleware('permission:enseignants.gerer');
});


Route::middleware(['auth:sanctum'])->group(function () {
    Route::get('/periodes', [PeriodeController::class, 'index']);
    Route::post('/periodes', [PeriodeController::class, 'store'])->middleware('permission:enseignants.gerer');

    Route::get('/mes-affectations', [EvaluationController::class, 'mesAffectations']);
    Route::get('/evaluations', [EvaluationController::class, 'index']);
    Route::post('/evaluations', [EvaluationController::class, 'store'])->middleware('permission:notes.saisir');
    Route::get('/evaluations/{id}', [EvaluationController::class, 'show']);
    Route::put('/evaluations/{id}/notes', [EvaluationController::class, 'saisirNotes'])->middleware('permission:notes.saisir');
    Route::post('/evaluations/{id}/soumettre', [EvaluationController::class, 'soumettre'])->middleware('permission:notes.soumettre');
    Route::post('/evaluations/{id}/valider', [EvaluationController::class, 'valider'])->middleware('permission:notes.valider');
    Route::post('/evaluations/{id}/rejeter', [EvaluationController::class, 'rejeter'])->middleware('permission:notes.valider');
    Route::post('/evaluations/{id}/reprendre', [EvaluationController::class, 'reprendre'])->middleware('permission:notes.saisir');
    Route::post('/evaluations/{id}/publier', [EvaluationController::class, 'publier'])->middleware('permission:notes.publier');
});
