<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PL-03 y HC-22 (tarea 1.80): quien tiene una plaza administrativa (apoyo y asistencia) no lleva banda de ±2, sino el
 * tope duro de 42 h entre clases, externas y horas administrativas. `administrative_hours` son las de sus plazas (nulas:
 * no es de plaza administrativa) y `administrative_cap_hours` el tope vigente (`staffing.administrative_max_hours`), para
 * que «Carga por docente» lo muestre sin recalcular. Columnas nulas (la fila vieja no las trae
 * hasta el siguiente recálculo), compatibles con el release anterior.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('teacher_term_loads', function (Blueprint $table) {
            $table->smallInteger('administrative_hours')->nullable();
            $table->smallInteger('administrative_cap_hours')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('teacher_term_loads', function (Blueprint $table) {
            $table->dropColumn(['administrative_hours', 'administrative_cap_hours']);
        });
    }
};
