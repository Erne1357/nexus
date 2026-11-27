<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Filas de la propuesta de carrera (docs/dominio/modelo-de-datos.md, `proposal_items`): una por materia de la
 * retícula de los planes activos de la carrera, con el plan y el semestre de donde se tomó. `eligible_students`
 * es el conteo de elegibles al armar la propuesta; `expected_students` y `requested_groups` los corrige el
 * coordinador. Semestre desde 1 y conteos no negativos (CHECK); cero grupos es no pedir la materia.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('proposal_items', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->foreignUuid('career_proposal_id')->constrained();
            $table->foreignUuid('subject_id')->constrained();
            $table->foreignUuid('study_plan_id')->constrained();
            $table->smallInteger('semester');
            $table->integer('eligible_students')->default(0);
            $table->integer('expected_students')->default(0);
            $table->integer('requested_groups')->default(0);
            $table->text('notes')->nullable();
            $table->timestampsTz();

            $table->unique(['career_proposal_id', 'subject_id']);
            $table->index('subject_id');
        });

        DB::statement('alter table proposal_items add constraint proposal_items_semester_check check (semester >= 1)');
        DB::statement('alter table proposal_items add constraint proposal_items_eligible_students_check check (eligible_students >= 0)');
        DB::statement('alter table proposal_items add constraint proposal_items_expected_students_check check (expected_students >= 0)');
        DB::statement('alter table proposal_items add constraint proposal_items_requested_groups_check check (requested_groups >= 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('proposal_items');
    }
};
