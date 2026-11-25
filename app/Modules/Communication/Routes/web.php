<?php

use Illuminate\Support\Facades\Route;

/*
| Rutas de Communication (CO-01): las pantallas del Departamento de Comunicación y Difusión. Quién entra lo decide cada
| página, no la ruta.
*/

Route::middleware('auth')->prefix('comunicacion')->name('communication.')->group(function () {
    Route::livewire('/personal', 'communication::pages.staff')->name('staff');
});
