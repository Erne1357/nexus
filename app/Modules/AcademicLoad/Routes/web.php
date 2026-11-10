<?php

use App\Modules\AcademicLoad\Http\Controllers\DownloadPublishedPdf;
use Illuminate\Support\Facades\Route;

/*
| Rutas de AcademicLoad: la propuesta de carrera del coordinador y la bandeja del departamento. Quién entra lo decide la página
| (CareerProposalPolicy), no la ruta.
|
| La consulta pública de lo publicado (tarea 4.4) va FUERA del grupo `auth`: la ven alumnos y docentes sin cuenta. Solo
| lee de PublishedScheduleQuery. Los alumnos de un campus comparten IP, por eso el límite es alto (supuesto por confirmar).
*/

Route::middleware('auth')->prefix('academic-load')->name('academic-load.')->group(function () {
    Route::livewire('/proposal', 'academic-load::pages.proposal')->name('proposal');
    Route::livewire('/inbox', 'academic-load::pages.inbox')->name('inbox');
});

Route::middleware('throttle:published-schedule')->prefix('horarios')->name('published.')->group(function () {
    Route::livewire('/', 'academic-load::pages.published.index')->name('index');
    Route::livewire('/{periodo}/{campus}/carrera/{carrera}', 'academic-load::pages.published.semester')->name('semester');
    Route::livewire('/{periodo}/{campus}/docente/{docente}', 'academic-load::pages.published.teacher')->name('teacher');
    Route::livewire('/{periodo}/{campus}/salon/{salon}', 'academic-load::pages.published.room')->name('room');
});

/*
| Descargas públicas de los PDF de lo publicado (tarea 4.5), sin sesión: el controlador busca el sujeto en lo publicado
| antes de encolar nada. El limitador `published-exports` (AcademicLoadServiceProvider) frena a quien encola de más.
*/
Route::middleware('throttle:published-exports')->prefix('horarios/{periodo}/{campus}')->name('published.')->group(function () {
    Route::get('/carrera/{carrera}/pdf', [DownloadPublishedPdf::class, 'semester'])->name('semester.pdf');
    Route::get('/docente/{docente}/pdf', [DownloadPublishedPdf::class, 'teacher'])->name('teacher.pdf');
    Route::get('/salon/{salon}/pdf', [DownloadPublishedPdf::class, 'room'])->name('room.pdf');
});
