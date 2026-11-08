<?php

use Illuminate\Support\Facades\Route;

/*
| Rutas de Staffing. Quién entra lo decide cada página con su Policy o sus permisos, no la ruta: la asignación docente
| del jefe (StaffingRankingPolicy; `?grupo=<id>` abre la pantalla con ese grupo, lo usa el tablero de choques), las
| solicitudes de cambio (tarea 3.11) y «Mi espacio» del docente (oferta de sus departamentos y su carga).
*/

Route::middleware('auth')->name('staffing.')->group(function () {
    Route::livewire('/staffing/assign', 'staffing::pages.assign')->name('assign');
    Route::livewire('/staffing/change-requests', 'staffing::pages.change-requests')->name('change-requests');
    Route::livewire('/mi-espacio/oferta', 'staffing::pages.offer')->name('offer');
    Route::livewire('/mi-espacio/mi-carga', 'staffing::pages.my-load')->name('my-load');
});
