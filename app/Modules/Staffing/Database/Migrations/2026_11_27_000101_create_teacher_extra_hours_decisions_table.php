<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PL-04 (tarea 1.81): la decisión del jefe de departamento sobre las horas extra (honorarios) de un docente en un periodo:
 * autorizadas como honorarios o no autorizadas (el docente firma el oficio y no se le pagan). `hours` son las horas extra
 * sobre las que decidió: si después suben, la decisión se reabre (el estado «por autorizar» se calcula, no se guarda).
 * Una fila por docente y periodo. Además, `teacher_term_loads` guarda las horas extra y el tope con honorarios vigente;
 * columnas nulas, compatibles con el release anterior.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('teacher_extra_hours_decisions', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->foreignUuid('teacher_id')->constrained();
            $table->foreignUuid('term_id')->constrained();
            $table->string('decision');
            $table->unsignedSmallInteger('hours');
            $table->foreignUuid('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('decided_at');
            $table->timestampsTz();

            $table->unique(['teacher_id', 'term_id']);
            $table->index('term_id');
        });

        DB::statement("alter table teacher_extra_hours_decisions add constraint teacher_extra_hours_decisions_decision_check check (decision in ('authorized', 'unauthorized'))");

        Schema::table('teacher_term_loads', function (Blueprint $table) {
            $table->smallInteger('extra_hours')->nullable();
            $table->smallInteger('with_honorarios_max_hours')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('teacher_term_loads', function (Blueprint $table) {
            $table->dropColumn(['extra_hours', 'with_honorarios_max_hours']);
        });

        Schema::dropIfExists('teacher_extra_hours_decisions');
    }
};
