<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Retícula: la posición de una materia en un plan (semestre, especialidad y si es obligatoria). Una vez por
 * plan y materia, también contra una fila borrada: quitar una materia es borrado lógico y volver a ponerla
 * restaura su fila (confirmado el 29 de octubre de 2026). La especialidad tiene que ser del mismo plan: la
 * llave compuesta la busca en `specialties` por (`study_plan_id`, `id`). La llave también acepta una
 * especialidad borrada, así que quitar una especialidad tiene que resolver antes sus materias (tarea 1.8).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('study_plan_subjects', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->foreignUuid('study_plan_id')->constrained();
            $table->foreignUuid('subject_id')->constrained();
            $table->smallInteger('semester');
            $table->uuid('specialty_id')->nullable();
            $table->boolean('is_required')->default(true);
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->unique(['study_plan_id', 'subject_id']);
            $table->index('subject_id');
            $table->index(['study_plan_id', 'specialty_id']);
            $table->foreign(['study_plan_id', 'specialty_id'])->references(['study_plan_id', 'id'])->on('specialties');
        });

        DB::statement('alter table study_plan_subjects add constraint study_plan_subjects_semester_check check (semester >= 1)');
    }

    public function down(): void
    {
        Schema::dropIfExists('study_plan_subjects');
    }
};
