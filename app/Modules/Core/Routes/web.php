<?php

use App\Modules\Core\Http\Controllers\AccessInvitationPage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
| Rutas de Core. El login, el logout y `PUT /user/password` los registra Fortify
| (config/fortify.php); aquí, la página de inicio, la pantalla de cambio de contraseña y la lista de notificaciones, el CRUD de
| Campus, el de Periodos, la ficha de un periodo, «Personas y puestos» con su ficha y el Organigrama. Quién entra a cada página lo decide la página con su policy, no la ruta.
*/

/*
| El enlace de la invitación de acceso (AC-08): el GET muestra el botón y el POST, al mismo enlace firmado, activa el
| acceso. Sirve con o sin sesión (en una computadora compartida puede haber otra abierta) y no inicia ninguna. La firma
| la revisa AccessInvitationPage, que responde igual a todo enlace que no sirve. El límite es por IP y holgado, porque
| una red del Tec sale por una sola IP y las invitaciones salen juntas al validar.
*/
Route::middleware('throttle:60,1')->group(function () {
    Route::get('/access/{user}/{hash}', [AccessInvitationPage::class, 'show'])->name('access.accept');
    Route::post('/access/{user}/{hash}', [AccessInvitationPage::class, 'accept'])->name('access.accept.store');
});

Route::middleware('auth')->group(function () {
    Route::livewire('/', 'core::pages.home')->name('home');
    Route::view('/user/password', 'core::auth.change-password')->name('user-password.edit');
    Route::livewire('/campus', 'core::pages.campuses')->name('core.campuses');
    Route::livewire('/notifications', 'core::pages.notifications')->name('core.notifications');
    Route::livewire('/terms', 'core::pages.terms')->name('core.terms');
    Route::livewire('/terms/{term}', 'core::pages.term')->name('core.terms.show');
    Route::livewire('/people', 'core::pages.people')->name('core.people');
    Route::livewire('/people/{user}', 'core::pages.person')->name('core.people.show');
    Route::livewire('/org-chart', 'core::pages.org-chart')->name('core.org-chart');
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
