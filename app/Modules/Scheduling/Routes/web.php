<?php

use Illuminate\Support\Facades\Route;

/*
| Rutas de Scheduling: grupos, horarios y choques. Quién entra lo decide la página (permisos de Scheduling) en
| mount() y en cada método, no la ruta.
*/

Route::middleware('auth')->prefix('scheduling')->name('scheduling.')->group(function () {
    Route::livewire('/conflicts', 'scheduling::pages.conflicts')->name('conflicts');
});
