<?php

use Illuminate\Support\Facades\Route;

/*
| Rutas de Solver: la pantalla de corridas del motor (tarea 6.10). Quién entra lo decide la página (permisos de ver
| corridas y SolverRunPolicy) en mount() y en cada método, no la ruta. La campana (SolverRunFinished) lleva aquí con
| `?corrida=`.
*/

Route::middleware('auth')->prefix('solver')->name('solver.')->group(function () {
    Route::livewire('/runs', 'solver::pages.runs')->name('runs');
});
