<?php

use Illuminate\Support\Facades\Route;

/*
| Rutas de Academic: la administración de sus catálogos. Quién entra lo decide la policy de cada
| modelo, no la ruta.
*/

Route::middleware('auth')->prefix('academic')->name('academic.')->group(function () {
    Route::livewire('/departments', 'academic::pages.departments')->name('departments');
    Route::livewire('/careers', 'academic::pages.careers')->name('careers');
    Route::livewire('/subjects', 'academic::pages.subjects')->name('subjects');
    Route::livewire('/plans', 'academic::pages.plans')->name('plans');
});
