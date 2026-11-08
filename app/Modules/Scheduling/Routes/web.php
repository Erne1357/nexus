<?php

use Illuminate\Support\Facades\Route;

/*
| Rutas de Scheduling: el editor de horarios. Quién entra lo decide la página («Ver los horarios»,
| SchedulingPermission::ScheduleView), no la ruta.
*/

Route::middleware('auth')->prefix('scheduling')->name('scheduling.')->group(function () {
    Route::livewire('/editor', 'scheduling::pages.schedule-editor')->name('editor');
});
