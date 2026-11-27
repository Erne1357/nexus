<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Choques del periodo (docs/dominio/modelo-de-datos.md, `schedule_conflicts`; tareas 3.1, 3.2 y 3.4): tabla derivada
 * que recalcula el validador al guardar una sesión, un docente o un salón, y que alimenta el tablero de choques.
 *
 * `rule` es el id de la regla (`HC-03`, `SC-01`); `subject_type` y `subject_id` dicen de qué se trata (el grupo, el
 * docente o el salón); `group_id`, `department_id` y `career_id` (la carrera dueña del grupo) permiten contar los
 * choques por departamento y por carrera sin unir con los grupos. `resolved_at` marca el choque que dejó de existir
 * en un recálculo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('schedule_conflicts', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->foreignUuid('term_id')->constrained();
            $table->foreignUuid('campus_id')->constrained();
            $table->foreignUuid('department_id')->nullable()->constrained();
            $table->foreignUuid('career_id')->nullable()->constrained();
            $table->foreignUuid('group_id')->nullable()->constrained();
            $table->string('rule', 8);
            $table->string('severity');
            $table->string('subject_type');
            $table->uuid('subject_id');
            $table->jsonb('related')->default('{}');
            $table->text('message');
            $table->timestampTz('resolved_at')->nullable();
            $table->timestampsTz();

            $table->index(['term_id', 'campus_id', 'department_id']);
            $table->index(['term_id', 'campus_id', 'career_id']);
            $table->index('group_id');
            $table->index(['subject_type', 'subject_id']);
        });

        DB::statement("alter table schedule_conflicts add constraint schedule_conflicts_rule_check check (rule ~ '^(HC|SC)-[0-9]{2}$')");
        DB::statement("alter table schedule_conflicts add constraint schedule_conflicts_severity_check check (severity in ('hard', 'soft'))");
        DB::statement("alter table schedule_conflicts add constraint schedule_conflicts_subject_type_check check (subject_type in ('group', 'teacher', 'room'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('schedule_conflicts');
    }
};
