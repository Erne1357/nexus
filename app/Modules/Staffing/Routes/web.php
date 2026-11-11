<?php

use Illuminate\Support\Facades\Route;

/*
| Rutas de Staffing. Quién entra lo decide cada página con su Policy o sus permisos, no la ruta: la asignación docente
| del jefe (StaffingRankingPolicy; `?grupo=<id>` abre la pantalla con ese grupo, lo usa el tablero de choques), la
| carga por docente del departamento (TeacherTermLoadPolicy), las solicitudes de cambio (tarea 3.11), el cierre de la
| carga y su avance (tareas 4.1 y 4.2) y «Mi espacio» del docente (oferta de sus departamentos y su carga).
*/

Route::middleware('auth')->name('staffing.')->group(function () {
    Route::livewire('/staffing/assign', 'staffing::pages.assign')->name('assign');
    Route::livewire('/carga-por-docente', 'staffing::pages.teacher-loads')->name('teacher-loads');
    Route::livewire('/staffing/change-requests', 'staffing::pages.change-requests')->name('change-requests');
    Route::livewire('/staffing/closing', 'staffing::pages.closing')->name('closing');
    Route::livewire('/staffing/closing-board', 'staffing::pages.closing-board')->name('closing-board');
    Route::livewire('/mi-espacio/oferta', 'staffing::pages.offer')->name('offer');
    Route::livewire('/mi-espacio/mi-carga', 'staffing::pages.my-load')->name('my-load');
});
