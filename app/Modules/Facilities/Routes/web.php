<?php

use Illuminate\Support\Facades\Route;

/*
| Rutas de Facilities: la administración de edificios y salones. Quién entra lo decide la policy de
| cada modelo, no la ruta.
*/

Route::middleware('auth')->prefix('facilities')->name('facilities.')->group(function () {
    Route::livewire('/buildings', 'facilities::pages.buildings')->name('buildings');
    Route::livewire('/rooms', 'facilities::pages.rooms')->name('rooms');
});
