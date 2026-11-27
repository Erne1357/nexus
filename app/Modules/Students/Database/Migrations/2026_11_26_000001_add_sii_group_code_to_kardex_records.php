<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * El grupo del SII con el que el alumno cursó la materia en el periodo (`historia_alumno.grupo` o
 * `seleccion_materias.grupo`). El mapa del Inicio lo usa para mostrarle a un alumno solo las clases de sus grupos
 * (MP-04). Nulo en lo que no viene del SII o todavía no se rellena (`imports:backfill-kardex-groups`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kardex_records', function (Blueprint $table) {
            $table->string('sii_group_code', 20)->nullable()->after('sii_subject_code');
        });
    }

    public function down(): void
    {
        Schema::table('kardex_records', function (Blueprint $table) {
            $table->dropColumn('sii_group_code');
        });
    }
};
