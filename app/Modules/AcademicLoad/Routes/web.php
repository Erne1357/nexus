<?php

use App\Modules\AcademicLoad\Http\Controllers\DownloadPublishedPdf;
use App\Modules\AcademicLoad\Http\Controllers\DownloadStaffExport;
use App\Modules\AcademicLoad\Http\Middleware\RedirectCampusToModality;
use Illuminate\Support\Facades\Route;

/*
| Rutas de AcademicLoad: la propuesta de carrera del coordinador y la bandeja del departamento. Quién entra lo decide la página
| (CareerProposalPolicy), no la ruta.
|
| La consulta pública de lo publicado (tarea 4.4) va FUERA del grupo `auth`: la ven alumnos y docentes sin cuenta. Solo
| lee de PublishedScheduleQuery. Los alumnos de un campus comparten IP, por eso el límite es alto (supuesto por confirmar).
| Lo publicado es por modalidad (M1): `/horarios/{periodo}/{modalidad}` con la clave de la modalidad en minúsculas y
| `?campus=` como filtro. Las ligas viejas con la clave de un campus en lugar de la modalidad redirigen a la nueva
| (RedirectCampusToModality).
*/

Route::middleware('auth')->prefix('academic-load')->name('academic-load.')->group(function () {
    Route::livewire('/proposal', 'academic-load::pages.proposal')->name('proposal');
    Route::livewire('/inbox', 'academic-load::pages.inbox')->name('inbox');
});

Route::middleware('throttle:published-schedule')->prefix('horarios')->name('published.')->group(function () {
    Route::livewire('/', 'academic-load::pages.published.index')->name('index');

    Route::middleware(RedirectCampusToModality::class)->group(function () {
        Route::livewire('/{periodo}/{modalidad}/carrera/{carrera}', 'academic-load::pages.published.semester')->name('semester');
        Route::livewire('/{periodo}/{modalidad}/docente/{docente}', 'academic-load::pages.published.teacher')->name('teacher');
        Route::livewire('/{periodo}/{modalidad}/salon/{salon}', 'academic-load::pages.published.room')->name('room');
    });
});

/*
| Descargas públicas de los PDF de lo publicado (tarea 4.5), sin sesión: el controlador busca el sujeto en lo publicado
| antes de encolar nada y tiene su propio tope para encolar (DownloadPublishedPdf::ENQUEUES_PER_MINUTE).
*/
Route::middleware(['throttle:published-schedule', RedirectCampusToModality::class])->prefix('horarios/{periodo}/{modalidad}')->name('published.')->group(function () {
    Route::get('/carrera/{carrera}/pdf', [DownloadPublishedPdf::class, 'semester'])->name('semester.pdf');
    Route::get('/docente/{docente}/pdf', [DownloadPublishedPdf::class, 'teacher'])->name('teacher.pdf');
    Route::get('/salon/{salon}/pdf', [DownloadPublishedPdf::class, 'room'])->name('room.pdf');
});

/*
| Exportes del personal de lo publicado (tareas 4.5 y 4.6): PDF por departamento, Excel de la modalidad y SII. Con sesión; el
| permiso de cada uno lo revisa PublishedExportPolicy antes de encolar.
*/
Route::middleware(['auth', 'throttle:published-schedule', RedirectCampusToModality::class])->get('horarios/{periodo}/{modalidad}/exportes/{tipo}', DownloadStaffExport::class)->name('published.export');
