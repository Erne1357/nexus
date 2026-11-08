<?php

use Illuminate\Support\Facades\Route;

/*
| Rutas de Scheduling: grupos, horarios, choques y acuerdos entre departamentos. Quién entra lo decide la página
| (permisos de Scheduling o InterdepartmentalAgreementPolicy) en mount() y en cada método, no la ruta.
*/

Route::middleware('auth')->prefix('scheduling')->name('scheduling.')->group(function () {
    Route::livewire('/conflicts', 'scheduling::pages.conflicts')->name('conflicts');
    Route::livewire('/agreements', 'scheduling::pages.agreements')->name('agreements');
});
