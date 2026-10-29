<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Historial académico: una fila por alumno, materia y periodo, también contra una borrada. Repetir una materia
 * en otro periodo es otra fila. `term_code` lleva el código del SII (año y 1, 2 o 3: Ene-Jun, verano y
 * Ago-Dic) y no es llave a `terms`, porque el kardex trae periodos de décadas anteriores. La materia puede
 * no estar en el plan del alumno (equivalencias, un plan anterior). Calificación de 0 a 100 o vacía.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kardex_records', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->foreignUuid('student_id')->constrained();
            $table->foreignUuid('subject_id')->constrained();
            $table->string('term_code');
            $table->decimal('grade', 5, 2)->nullable();
            $table->string('result');
            $table->smallInteger('attempt')->default(1);
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->unique(['student_id', 'subject_id', 'term_code']);
            $table->index('subject_id');
        });

        DB::statement("alter table kardex_records add constraint kardex_records_term_code_check check (term_code ~ '^[0-9]{4}[123]$')");
        DB::statement('alter table kardex_records add constraint kardex_records_grade_check check (grade between 0 and 100)');
        DB::statement("alter table kardex_records add constraint kardex_records_result_check check (result in ('aprobada', 'reprobada', 'cursando', 'baja'))");
        DB::statement('alter table kardex_records add constraint kardex_records_attempt_check check (attempt >= 1)');
    }

    public function down(): void
    {
        Schema::dropIfExists('kardex_records');
    }
};
