<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
| Rutas de Core. El login, el logout y `PUT /user/password` los registra Fortify
| (config/fortify.php); aquí, la página de inicio, la pantalla de cambio de contraseña y el CRUD de
| Campus. Quién entra a cada página lo decide la página con su policy, no la ruta.
*/

Route::middleware('auth')->group(function () {
    Route::livewire('/', 'core::pages.home')->name('home');
    Route::view('/user/password', 'core::auth.change-password')->name('user-password.edit');
    Route::livewire('/campus', 'core::pages.campuses')->name('core.campuses');
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
