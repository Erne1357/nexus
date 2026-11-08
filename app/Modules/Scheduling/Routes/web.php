<?php

use Illuminate\Support\Facades\Route;

/*
| Rutas de Scheduling: los acuerdos entre departamentos (préstamos de salón y grupos compartidos). Quién entra lo decide
| la página (InterdepartmentalAgreementPolicy), no la ruta.
*/

Route::middleware('auth')->prefix('scheduling')->name('scheduling.')->group(function () {
    Route::livewire('/agreements', 'scheduling::pages.agreements')->name('agreements');
});
