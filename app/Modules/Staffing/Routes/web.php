<?php

use Illuminate\Support\Facades\Route;

/*
| Rutas de Staffing: «Mi espacio» del docente (oferta de sus departamentos y su carga) y las solicitudes de cambio
| (tarea 3.11). Quién entra lo decide la página con los permisos de StaffingPermission, no la ruta.
*/

Route::middleware('auth')->prefix('mi-espacio')->group(function () {
    Route::livewire('/oferta', 'staffing::pages.offer')->name('staffing.offer');
    Route::livewire('/mi-carga', 'staffing::pages.my-load')->name('staffing.my-load');
});

Route::middleware('auth')->prefix('staffing')->group(function () {
    Route::livewire('/change-requests', 'staffing::pages.change-requests')->name('staffing.change-requests');
});
