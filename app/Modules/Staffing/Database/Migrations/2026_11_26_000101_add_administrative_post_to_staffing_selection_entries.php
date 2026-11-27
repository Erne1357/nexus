<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SEL-07 (tarea 1.54): `administrative_post` es la marca del puesto administrativo con el que el docente pasa primero en la
 * orden (el nombre de su puesto o «Plaza de apoyo»), fijada en cada recálculo del borrador. Columna nula, compatible con
 * el release anterior.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('staffing_selection_entries', function (Blueprint $table) {
            $table->string('administrative_post')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('staffing_selection_entries', function (Blueprint $table) {
            $table->dropColumn('administrative_post');
        });
    }
};
