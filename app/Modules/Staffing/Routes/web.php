<?php

use Illuminate\Support\Facades\Route;

/*
| Rutas de Staffing: la carga por docente del departamento. Quién entra lo decide la página (TeacherTermLoadPolicy), no la ruta.
*/

Route::middleware('auth')->name('staffing.')->group(function () {
    Route::livewire('/carga-por-docente', 'staffing::pages.teacher-loads')->name('teacher-loads');
});
