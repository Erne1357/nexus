<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Las horas fuera del ITCJ son únicas por docente, periodo y origen sin distinguir mayúsculas (S16, supuesto por
 * confirmar), como lo revisan AddExternalLoad y UpdateExternalLoad: el índice único pasa a `lower(source)`.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('alter table teacher_external_loads drop constraint teacher_external_loads_teacher_id_term_id_source_unique');
        DB::statement('create unique index teacher_external_loads_teacher_term_source_unique on teacher_external_loads (teacher_id, term_id, lower(source))');
    }

    public function down(): void
    {
        DB::statement('drop index teacher_external_loads_teacher_term_source_unique');
        DB::statement('alter table teacher_external_loads add constraint teacher_external_loads_teacher_id_term_id_source_unique unique (teacher_id, term_id, source)');
    }
};
