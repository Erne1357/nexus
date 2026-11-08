<?php

use Illuminate\Support\Facades\Route;

/*
| Rutas de Staffing: la asignación docente del jefe. Quién entra lo decide la página (StaffingRankingPolicy), no la
| ruta. `?grupo=<id>` abre la pantalla con ese grupo (lo usa el tablero de choques).
*/

Route::middleware('auth')->prefix('staffing')->name('staffing.')->group(function () {
    Route::livewire('/assign', 'staffing::pages.assign')->name('assign');
});
