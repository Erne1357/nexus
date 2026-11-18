<?php

use Illuminate\Support\Facades\Route;

/*
| Rutas de Scheduling: el editor de horarios, el tablero de choques, los acuerdos entre departamentos y la pestaña
| «Paralelos» de la bandeja del departamento (tarea 8.8). Quién entra lo decide la página (permisos de Scheduling,
| InterdepartmentalAgreementPolicy o ParallelsPolicy) en mount() y en cada método, no la ruta.
*/

Route::middleware('auth')->prefix('scheduling')->name('scheduling.')->group(function () {
    Route::livewire('/editor', 'scheduling::pages.schedule-editor')->name('editor');
    Route::livewire('/conflicts', 'scheduling::pages.conflicts')->name('conflicts');
    Route::livewire('/agreements', 'scheduling::pages.agreements')->name('agreements');
    Route::livewire('/parallels', 'scheduling::pages.parallels')->name('parallels');
});
