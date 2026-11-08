<?php

use Illuminate\Support\Facades\Route;

/*
| Rutas de Staffing: «Mi espacio» del docente (oferta de sus departamentos y su carga). Quién entra lo decide la
| página con los permisos personales de StaffingPermission, no la ruta.
*/

Route::middleware('auth')->prefix('mi-espacio')->group(function () {
    Route::livewire('/oferta', 'staffing::pages.offer')->name('staffing.offer');
    Route::livewire('/mi-carga', 'staffing::pages.my-load')->name('staffing.my-load');
});
