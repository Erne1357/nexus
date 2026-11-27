<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La especialidad que el SII le asignó al alumno (1.69, `alumnos.especialidad`). Nullable (expand): la mayoría no la
 * tiene hasta el 7.º semestre. La llave foránea es compuesta (plan del alumno, especialidad), como en
 * `study_plan_subjects`, así que no puede apuntar a una especialidad de otro plan; con `specialty_id` nulo no se
 * revisa (MATCH SIMPLE). Es el respaldo de la especialidad que sale del kardex (reglas-de-negocio.md, "Retícula del alumno").
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('students', function (Blueprint $table): void {
            $table->uuid('specialty_id')->nullable();
            $table->index(['study_plan_id', 'specialty_id']);
            $table->foreign(['study_plan_id', 'specialty_id'])->references(['study_plan_id', 'id'])->on('specialties');
        });
    }

    public function down(): void
    {
        Schema::table('students', function (Blueprint $table): void {
            $table->dropForeign(['study_plan_id', 'specialty_id']);
            $table->dropIndex(['study_plan_id', 'specialty_id']);
            $table->dropColumn('specialty_id');
        });
    }
};
