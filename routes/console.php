<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Borra los exportes de lo publicado que una versión nueva dejó sin uso y los temporales a medias (tarea 4.5).
Schedule::command('academic-load:prune-exports')->dailyAt('03:30');

// Avanza las selecciones de carga por turnos: estados, avisos, ronda extra y cierre (tarea 1.33).
Schedule::command('staffing:advance-selections')->everyMinute()->withoutOverlapping();
