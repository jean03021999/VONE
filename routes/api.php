<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Auth\AuthController;

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

use App\Http\Controllers\EleveController;

Route::middleware(['auth:sanctum'])->group(function () {
    Route::get('/eleves', [EleveController::class, 'index'])->middleware('permission:eleves.voir');
    Route::get('/eleves/{id}', [EleveController::class, 'show'])->middleware('permission:eleves.voir');
    Route::post('/eleves', [EleveController::class, 'store'])->middleware('permission:eleves.gerer');
    Route::put('/eleves/{id}', [EleveController::class, 'update'])->middleware('permission:eleves.gerer');
});

use App\Http\Controllers\ClasseController;

Route::middleware(['auth:sanctum'])->get('/classes', [ClasseController::class, 'index']);

use App\Http\Controllers\EleveImportController;

Route::middleware(['auth:sanctum'])->group(function () {
    Route::post('/eleves/import/analyser', [EleveImportController::class, 'analyser'])->middleware('permission:eleves.gerer');
    Route::post('/eleves/import/executer', [EleveImportController::class, 'executer'])->middleware('permission:eleves.gerer');
});

Route::middleware(['auth:sanctum'])->get('/eleves/import/modele', [App\Http\Controllers\EleveImportController::class, 'telechargerModele']);

use App\Http\Controllers\EnseignantController;

Route::middleware(['auth:sanctum'])->group(function () {
    Route::get('/enseignants', [EnseignantController::class, 'index'])->middleware('permission:enseignants.voir');
    Route::get('/enseignants/{id}', [EnseignantController::class, 'show'])->middleware('permission:enseignants.voir');
    Route::post('/enseignants', [EnseignantController::class, 'store'])->middleware('permission:enseignants.gerer');
    Route::put('/enseignants/{id}', [EnseignantController::class, 'update'])->middleware('permission:enseignants.gerer');
});

use App\Http\Controllers\MatiereController;

Route::middleware(['auth:sanctum'])->group(function () {
    Route::get('/matieres', [MatiereController::class, 'index'])->middleware('permission:enseignants.voir');
    Route::post('/matieres', [MatiereController::class, 'store'])->middleware('permission:enseignants.gerer');
    Route::post('/filieres', [MatiereController::class, 'storeFiliere'])->middleware('permission:enseignants.gerer');
});
