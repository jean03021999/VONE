<?php

use Illuminate\Support\Facades\Route;

// Application web (React) copiee dans public/ lors d'une installation sur site : le meme serveur
// sert l'application et l'API (/api), accessibles depuis tous les postes du reseau de l'ecole.
// Toute adresse hors /api (tableau de bord, parametres...) renvoie la page de l'application, qui
// affiche elle-meme le bon ecran. Sans application copiee, comportement Laravel par defaut.
Route::get('/{chemin?}', function () {
    $page = public_path('index.html');

    return is_file($page)
        ? response()->file($page, ['Cache-Control' => 'no-cache'])
        : view('welcome');
})->where('chemin', '^(?!api(/|$)).*$');
