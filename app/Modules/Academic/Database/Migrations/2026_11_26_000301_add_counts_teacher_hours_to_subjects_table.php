<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PL-02 (tarea 1.73): si las horas de la materia cuentan en la carga del docente. En falso (las tutorías) cuentan 0 h
 * para el docente en todo cálculo de sus horas (HC-08, la banda de la selección, la carga por docente, el ranking y el
 * motor); al alumno sus créditos le siguen contando y el grupo sigue ocupando el horario del docente. Con valor por
 * omisión verdadero, compatible con el release anterior.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subjects', fn (Blueprint $table) => $table->boolean('counts_teacher_hours')->default(true));
    }

    public function down(): void
    {
        Schema::table('subjects', fn (Blueprint $table) => $table->dropColumn('counts_teacher_hours'));
    }
};
