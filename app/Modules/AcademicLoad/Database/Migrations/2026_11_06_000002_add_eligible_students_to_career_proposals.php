<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * «Alumnos posibles» de la propuesta (docs/dominio/modelo-de-datos.md, "AcademicLoad"): los alumnos activos
 * distintos de la carrera y el campus que son elegibles para al menos una materia de la propuesta, congelados
 * al armarla (confirmado el 4 de noviembre de 2026). Las propuestas que ya existen lo calculan aquí, una vez,
 * sobre sus propias filas y con los filtros de EligibleStudentsQuery::distinctFor escritos en SQL: el avance con su
 * plan actual, la carrera y el campus, activo y con estatus «activo», y sin los alumnos borrados, que el modelo
 * Student deja fuera con SoftDeletes y este SQL tiene que dejar fuera a mano.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('career_proposals', function (Blueprint $table) {
            $table->unsignedInteger('eligible_students')->default(0)->after('career_id');
        });

        DB::statement('alter table career_proposals add constraint career_proposals_eligible_students_check check (eligible_students >= 0)');

        DB::statement(<<<'SQL'
            update career_proposals set eligible_students = (
                select count(distinct students.id)
                from students
                join student_progress on student_progress.student_id = students.id
                    and student_progress.study_plan_id = students.study_plan_id
                cross join lateral jsonb_array_elements_text(student_progress.subjects_available) as available(subject_id)
                where students.career_id = career_proposals.career_id
                    and students.campus_id = career_proposals.campus_id
                    and students.is_active = true
                    and students.status = 'activo'
                    and students.deleted_at is null
                    and available.subject_id in (
                        select proposal_items.subject_id::text from proposal_items
                        where proposal_items.career_proposal_id = career_proposals.id
                    )
            )
        SQL);
    }

    public function down(): void
    {
        Schema::table('career_proposals', function (Blueprint $table) {
            $table->dropColumn('eligible_students');
        });
    }
};
