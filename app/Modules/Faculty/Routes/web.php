<?php

use Illuminate\Support\Facades\Route;

/*
| Rutas de Faculty: los docentes. Quién entra lo decide TeacherPolicy en cada página, no la ruta.
*/

Route::middleware('auth')->prefix('faculty')->name('faculty.')->group(function () {
    Route::livewire('/teachers', 'faculty::pages.teachers')->name('teachers');
    Route::livewire('/teachers/{teacher}', 'faculty::pages.teacher')->name('teachers.show');
    Route::livewire('/teachers/{teacher}/availability', 'faculty::pages.teacher-availability')->name('teachers.availability');
});
