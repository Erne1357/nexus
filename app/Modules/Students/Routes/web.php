<?php

use Illuminate\Support\Facades\Route;

/*
| Rutas de Students: el listado de alumnos. Quién entra lo decide StudentPolicy, no la ruta.
*/

Route::middleware('auth')->prefix('students')->name('students.')->group(function () {
    Route::livewire('/', 'students::pages.students')->name('index');
});
