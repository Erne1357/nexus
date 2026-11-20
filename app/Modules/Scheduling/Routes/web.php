<?php

use Illuminate\Support\Facades\Route;

/*
| Rutas de Scheduling: el Inicio con el mapa del campus, el editor de horarios, el tablero de choques, los acuerdos entre departamentos, el re-análisis
| de la carga (tarea 8.2, pantalla) y la pestaña «Paralelos» de la bandeja del departamento (tarea 8.8). Quién entra
| lo decide la página (permisos de Scheduling, InterdepartmentalAgreementPolicy o ParallelsPolicy) en mount() y en
| cada método, no la ruta.
*/

/*
| El Inicio (`/`, nombre `home`) es el mapa del campus (tarea 1.24.1): para todo el que tiene sesión, sin permiso propio
| (D6 del usuario, 20 de noviembre de 2026). Lo registra Scheduling porque es el módulo que puede leer a la vez salones,
| docentes y sesiones; ocultar «Carga académica» nunca lo bloquea.
*/
Route::middleware('auth')->group(function () {
    Route::livewire('/', 'scheduling::pages.map')->name('home');
});

Route::middleware('auth')->prefix('scheduling')->name('scheduling.')->group(function () {
    Route::livewire('/editor', 'scheduling::pages.schedule-editor')->name('editor');
    Route::livewire('/conflicts', 'scheduling::pages.conflicts')->name('conflicts');
    Route::livewire('/agreements', 'scheduling::pages.agreements')->name('agreements');
    Route::livewire('/parallels', 'scheduling::pages.parallels')->name('parallels');
    Route::livewire('/reanalysis', 'scheduling::pages.reanalysis')->name('reanalysis');
});
