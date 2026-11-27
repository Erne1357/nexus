<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * El semestre que la migración del SII sugiere para un pendiente de una retícula viva (D18, confirmado el 10 de
 * noviembre de 2026): el más frecuente entre las materias con el mismo nombre de la misma carrera. Solo es una
 * sugerencia: «Por validar» la muestra elegida y Servicios Escolares la confirma o la cambia. Nulo sin sugerencia.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('study_plan_subject_pendings', function (Blueprint $table) {
            $table->smallInteger('suggested_semester')->nullable()->after('position');
        });

        DB::statement('alter table study_plan_subject_pendings add constraint study_plan_subject_pendings_suggested_semester_check check (suggested_semester >= 1)');
    }

    public function down(): void
    {
        Schema::table('study_plan_subject_pendings', function (Blueprint $table) {
            $table->dropColumn('suggested_semester');
        });
    }
};
