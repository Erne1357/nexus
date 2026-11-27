<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Origen de cada materia del perfil (D-02): `manual` (el jefe la declaró) o `history` (se derivó del historial de los
 * últimos 6 semestres). Las que ya existen son manuales. `qualified_by` pasa a admitir nulo: una derivada no la
 * declaró nadie.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('teacher_subject_qualifications', function (Blueprint $table) {
            $table->string('source')->default('manual');
        });

        DB::statement("alter table teacher_subject_qualifications add constraint teacher_subject_qualifications_source_check check (source in ('manual', 'history'))");
        DB::statement('alter table teacher_subject_qualifications alter column qualified_by drop not null');
    }

    public function down(): void
    {
        DB::statement('alter table teacher_subject_qualifications drop constraint teacher_subject_qualifications_source_check');

        Schema::table('teacher_subject_qualifications', function (Blueprint $table) {
            $table->dropColumn('source');
        });
    }
};
