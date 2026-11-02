<?php

use Illuminate\Support\Facades\Route;

/*
| Rutas de AcademicLoad: la propuesta de carrera del coordinador. Quién entra lo decide la página
| (CareerProposalPolicy), no la ruta.
*/

Route::middleware('auth')->prefix('academic-load')->name('academic-load.')->group(function () {
    Route::livewire('/proposal', 'academic-load::pages.proposal')->name('proposal');
});
