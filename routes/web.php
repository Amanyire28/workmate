<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
*/

// SPA catch-all — all routes are handled by Vue Router client-side.
// The Blade shell mounts the Vue application at #app.
// /api/* is excluded so unknown API URLs get a JSON 404, not the Vue page.
Route::get('/{any}', function () {
    return view('app');
})->where('any', '(?!api(?:/|$)).*');
