<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Una fila del kárdex puede no tener periodo: la historia del SII trae materias aprobadas con periodos que no existen
 * (`0`, `19001`), y pasan sin periodo para que Servicios Escolares capture el bueno en lugar de perderse (decidido el
 * 10 de noviembre de 2026). Sigue siendo una sola fila por alumno, materia y periodo, también sin periodo: la llave
 * única trata los vacíos como iguales (`nulls not distinct`). Al revertir se borran las filas sin periodo.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('alter table kardex_records alter column term_code drop not null');
        DB::statement('alter table kardex_records drop constraint kardex_records_student_id_subject_id_term_code_unique');
        DB::statement('alter table kardex_records add constraint kardex_records_student_id_subject_id_term_code_unique unique nulls not distinct (student_id, subject_id, term_code)');
    }

    public function down(): void
    {
        DB::statement('delete from kardex_records where term_code is null');
        DB::statement('alter table kardex_records drop constraint kardex_records_student_id_subject_id_term_code_unique');
        DB::statement('alter table kardex_records add constraint kardex_records_student_id_subject_id_term_code_unique unique (student_id, subject_id, term_code)');
        DB::statement('alter table kardex_records alter column term_code set not null');
    }
};
