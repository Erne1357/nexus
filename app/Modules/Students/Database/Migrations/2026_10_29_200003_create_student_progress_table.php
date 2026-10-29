<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Avance reticular de cada alumno en su plan, que recalcula el job RecomputeStudentProgress al terminar una
 * importación (decisión de modelado 5). Uno por alumno. `subjects_pending` son las materias del plan que no
 * ha aprobado y `subjects_available` las que de esas ya puede cursar, con toda su seriación aprobada: con el
 * índice GIN, contar elegibles por materia es una consulta de contención (tarea 1.5). Tabla derivada: se
 * reescribe, sin borrado lógico.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('student_progress', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->foreignUuid('student_id')->unique()->constrained();
            $table->foreignUuid('study_plan_id')->constrained();
            $table->unsignedInteger('credits_approved')->default(0);
            $table->unsignedInteger('subjects_approved')->default(0);
            $table->jsonb('subjects_pending')->default('[]');
            $table->jsonb('subjects_available')->default('[]');
            $table->timestampTz('computed_at');
            $table->timestampsTz();

            $table->index('study_plan_id');
        });

        DB::statement('create index student_progress_subjects_available_index on student_progress using gin (subjects_available jsonb_path_ops)');
    }

    public function down(): void
    {
        Schema::dropIfExists('student_progress');
    }
};
