<?php

use Illuminate\Support\Facades\Route;

/*
| Rutas de Staffing: la asignación docente del jefe (`?grupo=<id>` abre la pantalla con ese grupo; lo usa el tablero de
| choques) y la carga por docente del departamento. Quién entra lo deciden las páginas (StaffingRankingPolicy,
| TeacherTermLoadPolicy), no la ruta.
*/

Route::middleware('auth')->name('staffing.')->group(function () {
    Route::livewire('/carga-por-docente', 'staffing::pages.teacher-loads')->name('teacher-loads');
});

Route::middleware('auth')->prefix('staffing')->name('staffing.')->group(function () {
    Route::livewire('/assign', 'staffing::pages.assign')->name('assign');
});
