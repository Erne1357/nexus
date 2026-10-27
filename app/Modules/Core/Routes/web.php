<?php

use Illuminate\Support\Facades\Route;

/*
| Rutas de Core. El login, el logout y `PUT /user/password` los registra Fortify
| (config/fortify.php); aquí solo la pantalla de cambio de contraseña.
*/

Route::middleware('auth')->group(function () {
    Route::view('/user/password', 'core::auth.change-password')->name('user-password.edit');
});
