<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Historial: una fila por grupo que el docente impartió (confirmado el 30 de octubre de 2026), así que dos
 * grupos de la misma materia en el mismo periodo son dos filas. `term_code` es el código del SII (año y 1, 2
 * o 3, como `kardex_records`) y no llave a `terms`, porque el historial trae periodos que Nexus no tiene.
 * Única por docente, materia, periodo, campus y grupo; el campus nulo cuenta como un valor más
 * (`nulls not distinct`) y los borrados siguen ocupando la clave.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('teacher_subject_history', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->foreignUuid('teacher_id')->constrained();
            $table->foreignUuid('subject_id')->constrained();
            $table->string('term_code');
            $table->foreignUuid('campus_id')->nullable()->constrained();
            $table->string('group_code');
            $table->string('source');
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index(['subject_id', 'term_code']);
            $table->index('campus_id');
        });

        DB::statement("alter table teacher_subject_history add constraint teacher_subject_history_term_code_check check (term_code ~ '^[0-9]{4}[123]$')");
        DB::statement("alter table teacher_subject_history add constraint teacher_subject_history_source_check check (source in ('import', 'nexus'))");
        DB::statement('alter table teacher_subject_history add constraint teacher_subject_history_unique unique nulls not distinct (teacher_id, subject_id, term_code, campus_id, group_code)');
    }

    public function down(): void
    {
        Schema::dropIfExists('teacher_subject_history');
    }
};
