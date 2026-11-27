<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * HC-08 (tarea 1.50) se mide contra las horas frente a grupo (PL-01): `teaching_hours` son las del docente en el periodo,
 * `band_limit_hours` el tope de su banda (frente a grupo + 2 más la tolerancia, o el tope de honorarios) y
 * `honorarios_cap_hours` el tope de honorarios cuando es el que aplica, para que «Carga por docente» muestre la banda y el
 * aviso sin recalcular. Columnas nulas (la fila vieja no las trae hasta el siguiente
 * recálculo), compatibles con el release anterior.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('teacher_term_loads', function (Blueprint $table) {
            $table->smallInteger('teaching_hours')->nullable();
            $table->smallInteger('band_limit_hours')->nullable();
            $table->smallInteger('honorarios_cap_hours')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('teacher_term_loads', function (Blueprint $table) {
            $table->dropColumn(['teaching_hours', 'band_limit_hours', 'honorarios_cap_hours']);
        });
    }
};
