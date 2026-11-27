<?php

use App\Modules\Core\Http\Controllers\AccessInvitationPage;
use App\Modules\Core\Http\Controllers\ChangePasswordPage;
use App\Modules\Core\Http\Controllers\ForgotPasswordPage;
use App\Modules\Core\Http\Controllers\MicrosoftMailCallback;
use App\Modules\Core\Http\Controllers\ResetPasswordPage;
use App\Modules\Core\Http\Controllers\StaffPhoto;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
| Rutas de Core. El login, el logout y `PUT /user/password` los registra Fortify
| (config/fortify.php); aquí, la invitación de acceso, el restablecimiento de contraseña por correo, la página «Resumen» (el Inicio de antes), la pantalla de cambio de contraseña y la lista de notificaciones, el CRUD de
| Campus, el de Modalidades, el de Periodos, la ficha de un periodo, «Personas y puestos» con su ficha y el Organigrama. Quién entra a cada página lo decide la página con su policy, no la ruta.
*/

/*
| El enlace de la invitación de acceso (AC-08): el GET muestra el formulario para elegir la contraseña y el POST, al
| mismo enlace firmado, activa el acceso con ella. Sirve con o sin sesión (en una computadora compartida puede haber otra
| abierta); al activar, inicia la del invitado. La firma la revisa AccessInvitationPage, que responde igual a todo
| enlace que no sirve. El límite es por IP y holgado, porque una red del Tec sale por una sola IP y las invitaciones
| salen juntas al validar.
*/
Route::middleware('throttle:60,1')->group(function () {
    Route::get('/access/{user}/{hash}', [AccessInvitationPage::class, 'show'])->name('access.accept');
    Route::post('/access/{user}/{hash}', [AccessInvitationPage::class, 'accept'])->name('access.accept.store');
});

/*
| «¿Olvidaste tu contraseña?» por el correo institucional (AC-16 a AC-18): pedir el enlace (con el usuario, no el correo)
| y, con el enlace, elegir la contraseña nueva; el GET del enlace solo muestra el formulario. Como el de la invitación,
| sirve con o sin sesión y no inicia ninguna. Pedir el enlace tiene su límite por usuario y por IP
| (`password-reset-link`, FortifyServiceProvider); lo demás, el mismo límite holgado por IP.
*/
Route::post('/forgot-password', [ForgotPasswordPage::class, 'send'])->middleware('throttle:password-reset-link')->name('password.email');

Route::middleware('throttle:60,1')->group(function () {
    Route::get('/forgot-password', [ForgotPasswordPage::class, 'show'])->name('password.request');
    Route::get('/reset-password', [ResetPasswordPage::class, 'show'])->name('password.reset');
    Route::post('/reset-password', [ResetPasswordPage::class, 'update'])->name('password.update');
});

/*
| El aviso de privacidad del TecNM (AC-23, tarea 1.65): página pública, sin sesión, con el mismo tope holgado que la
| consulta de lo publicado (240 por minuto por usuario con sesión o por IP sin ella), porque los alumnos de un campus
| comparten IP. EnsurePasswordIsChanged la deja ver a quien todavía debe cambiar su contraseña.
*/
Route::view('/aviso-de-privacidad', 'core::privacy-notice.show')->middleware('throttle:240,1')->name('privacy-notice');

Route::middleware('auth')->group(function () {
    // La foto vigente de una persona (CO-04): solo con la URL firmada que arma StaffPhotoUrl y vence a las 2 horas.
    Route::get('/personal/{user}/foto/{version}', StaffPhoto::class)->middleware('signed')->name('core.staff-photo');
    Route::get('/personal/{user}/foto/{version}/mini', StaffPhoto::class)->middleware('signed')->defaults('size', 'mini')->name('core.staff-photo-mini');
    // El Inicio (`/`, nombre `home`) es el mapa del campus y lo registra Scheduling (tarea 1.24.1); lo que era el Inicio
    // —saludo, bloque del puesto, calendario y tarjetas— queda como «Resumen» en Mi espacio.
    Route::livewire('/resumen', 'core::pages.home')->name('core.summary');
    Route::get('/user/password', ChangePasswordPage::class)->name('user-password.edit');
    Route::livewire('/campus', 'core::pages.campuses')->name('core.campuses');
    Route::livewire('/modalidades', 'core::pages.modalities')->name('core.modalities');
    Route::livewire('/notifications', 'core::pages.notifications')->name('core.notifications');
    Route::livewire('/terms', 'core::pages.terms')->name('core.terms');
    Route::livewire('/terms/{term}', 'core::pages.term')->name('core.terms.show');
    Route::livewire('/people', 'core::pages.people')->name('core.people');
    Route::livewire('/people/{user}', 'core::pages.person')->name('core.people.show');
    Route::livewire('/org-chart', 'core::pages.org-chart')->name('core.org-chart');
    // Panel de configuración (1.22): cada paquete agrega sus rutas debajo de su marca, con el prefijo /configuracion.
    // 1.22-B Puestos y permisos
    Route::livewire('/configuracion/puestos-y-permisos', 'core::pages.permissions')->name('core.permissions');
    // 1.22-D Bitácora
    Route::livewire('/configuracion/bitacora', 'core::pages.activity')->name('core.activity');
    // 1.22-E Avisos y atajos del login
    Route::livewire('/configuracion/avisos-y-atajos', 'core::pages.login-content')->name('core.login-content');
    // 1.22-F Parámetros
    Route::livewire('/configuracion/parametros', 'core::pages.settings')->name('core.settings');
    // 1.22-G Correos y colas
    Route::livewire('/configuracion/correos-y-colas', 'core::pages.mail-and-queues')->name('core.mail-and-queues');
    // 1.29 El regreso de Microsoft al conectar la cuenta de envío (Redirect URI de la app de Azure; ADR-0032).
    Route::get('/configuracion/correo/microsoft/callback', MicrosoftMailCallback::class)->name('core.mail-connection.callback');
});

/*
| Reemplaza la ruta con la que MaryUI guarda en la sesión si la barra lateral está colapsada: la
| suya guarda cualquier texto y <x-main> lo imprime dentro de x-data, donde Alpine lo ejecuta. Esta
| solo guarda 'true' o 'false'. Se registra después que la de MaryUI (los paquetes arrancan antes
| que los módulos), así que la sustituye con el mismo método, URI y nombre.
*/
Route::prefix(config('mary.route_prefix'))->get('/mary/toogle-sidebar', function (Request $request): void {
    $request->session()->put('mary-sidebar-collapsed', $request->boolean('collapsed') ? 'true' : 'false');
})->name('mary.toogle-sidebar');
