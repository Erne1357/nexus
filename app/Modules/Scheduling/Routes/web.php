<?php

use Illuminate\Support\Facades\Route;

/*
| Rutas de Scheduling: el editor de horarios, el tablero de choques y los acuerdos entre departamentos. Quién entra lo
| decide la página (permisos de Scheduling o InterdepartmentalAgreementPolicy) en mount() y en cada método, no la ruta.
*/

Route::middleware('auth')->prefix('scheduling')->name('scheduling.')->group(function () {
    Route::livewire('/editor', 'scheduling::pages.schedule-editor')->name('editor');
    Route::livewire('/conflicts', 'scheduling::pages.conflicts')->name('conflicts');
    Route::livewire('/agreements', 'scheduling::pages.agreements')->name('agreements');
});
