<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Grupos (docs/dominio/modelo-de-datos.md, `groups`; tarea 3.1): la oferta concreta de una materia en un periodo y
 * campus, con su carrera dueña y el plan y semestre reticular donde la acomoda el editor (HC-03 se revisa dentro de
 * cada plan y semestre). `department_id` se copia de la materia para consultar por departamento. `teacher_id` es el
 * docente vigente: lo escribe solo `SetGroupTeacher` (la asignación de Staffing guarda su historial).
 *
 * La clave sigue la nomenclatura del SII (confirmado el 7 de noviembre de 2026) y es única por periodo y materia en
 * todo el Tec entre los grupos no cancelados, como en el SII, que identifica un grupo por periodo, materia y grupo: un
 * grupo cancelado suelta su clave. El plan debe ser de la carrera
 * dueña (llave compuesta a `study_plans (id, career_id)`). No se borra: se cancela.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('groups', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->foreignUuid('term_id')->constrained();
            $table->foreignUuid('campus_id')->constrained();
            $table->foreignUuid('subject_id')->constrained();
            $table->foreignUuid('department_id')->constrained();
            $table->foreignUuid('owner_career_id')->constrained('careers');
            $table->uuid('study_plan_id');
            $table->smallInteger('semester');
            $table->string('code', 16);
            $table->foreignUuid('teacher_id')->nullable()->constrained();
            $table->integer('capacity');
            $table->string('status')->default('planned');
            $table->foreignUuid('proposal_item_id')->nullable()->constrained();
            $table->text('notes')->nullable();
            $table->timestampsTz();

            $table->foreign(['study_plan_id', 'owner_career_id'], 'groups_study_plan_career_foreign')
                ->references(['id', 'career_id'])->on('study_plans');
            $table->foreign(['subject_id', 'department_id'], 'groups_subject_department_foreign')
                ->references(['id', 'department_id'])->on('subjects')->cascadeOnUpdate();
            $table->index(['term_id', 'campus_id', 'owner_career_id', 'study_plan_id', 'semester']);
            $table->index(['term_id', 'campus_id', 'department_id']);
            $table->index('teacher_id');
            $table->index('proposal_item_id');
        });

        DB::statement('alter table groups add constraint groups_semester_check check (semester >= 1)');
        DB::statement('alter table groups add constraint groups_capacity_check check (capacity > 0)');
        DB::statement("alter table groups add constraint groups_status_check check (status in ('planned', 'confirmed', 'cancelled'))");
        DB::statement("create unique index groups_code_unique on groups (term_id, subject_id, code) where status <> 'cancelled'");
    }

    public function down(): void
    {
        Schema::dropIfExists('groups');
    }
};
