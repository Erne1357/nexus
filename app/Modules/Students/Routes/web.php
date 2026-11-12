<?php

use Illuminate\Support\Facades\Route;

/*
| Rutas de Students: el listado de alumnos y la ficha de cada uno, que decide StudentPolicy, y «Mis datos» del alumno
| (AC-15), que decide su permiso personal. Quién entra no lo decide la ruta.
*/

Route::middleware('auth')->group(function () {
    Route::livewire('/my-record', 'students::pages.my-record')->name('students.my-record');
});

Route::middleware('auth')->prefix('students')->name('students.')->group(function () {
    Route::livewire('/', 'students::pages.students')->name('index');
    Route::livewire('/{student}', 'students::pages.student')->name('show');
});
