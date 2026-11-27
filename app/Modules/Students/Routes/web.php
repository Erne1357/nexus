<?php

use App\Modules\Students\Http\Controllers\MyCredentialPhoto;
use App\Modules\Students\Http\Controllers\StudentPhoto;
use App\Modules\Students\Http\Controllers\VerifyCredential;
use Illuminate\Support\Facades\Route;

/*
| Rutas de Students: el listado de alumnos y la ficha de cada uno, que decide StudentPolicy, y «Mis datos» del alumno
| (AC-15), que decide su permiso personal. Quién entra no lo decide la ruta. La verificación del QR de la credencial (AC-20)
| es pública: la protege el token.
*/

Route::middleware('auth')->group(function () {
    Route::livewire('/my-record', 'students::pages.my-record')->name('students.my-record');
    // La foto de la credencial del propio alumno (AC-20): sin id, solo la suya.
    Route::get('/my-record/foto', MyCredentialPhoto::class)->name('students.my-record.photo');
});

// La verificación pública del QR de la credencial (AC-20): sin sesión, con token firmado de 30 s y límite de peticiones.
// Fuera del grupo `web`: un escaneo anónimo no abre sesión ni deja cookies, y a quien tiene sesión no lo redirige nada.
Route::middleware('throttle:credential-verify')
    ->withoutMiddleware('web')
    ->get('/credencial/verificar/{token}', VerifyCredential::class)
    ->where('token', '[A-Za-z0-9_-]{20,100}')
    ->name('credential.verify');

Route::middleware('auth')->prefix('students')->name('students.')->group(function () {
    Route::livewire('/', 'students::pages.students')->name('index');
    // La foto del alumno para quien lo consulta (CO-06): firmada, vence a las 2 horas.
    Route::get('/{student}/foto/{version}', StudentPhoto::class)->middleware('signed')->name('photo');
    Route::livewire('/{student}', 'students::pages.student')->name('show');
});
