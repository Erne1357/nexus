<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * El avance de una evaluación de la proyección mientras corre (tarea 5.9b; docs/dominio/modelo-de-datos.md,
 * `forecast_evaluations`): `progress` de 0 a 100 (CHECK) y `stage`, la etapa en español que la pantalla muestra
 * («Calculando la proyección», «Comparando con el kárdex», «Guardando resultados»; EvaluateForecast).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('forecast_evaluations', function (Blueprint $table) {
            $table->smallInteger('progress')->default(0);
            $table->string('stage')->nullable();
        });

        DB::statement('alter table forecast_evaluations add constraint forecast_evaluations_progress_check check (progress between 0 and 100)');
    }

    public function down(): void
    {
        DB::statement('alter table forecast_evaluations drop constraint if exists forecast_evaluations_progress_check');

        Schema::table('forecast_evaluations', function (Blueprint $table) {
            $table->dropColumn(['progress', 'stage']);
        });
    }
};
