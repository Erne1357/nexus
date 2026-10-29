<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Seriación por plan: la misma materia puede seriarse distinto en planes distintos (decisión de modelado 2).
 * La materia y su requisito tienen que estar en la retícula de ese plan: las dos llaves compuestas los buscan
 * en `study_plan_subjects` por (`study_plan_id`, `subject_id`). Una materia no es requisito de sí misma. Una
 * vez por plan, materia y requisito, también contra una fila borrada; quitarla es borrado lógico (confirmado el
 * 29 de octubre de 2026).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subject_prerequisites', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->foreignUuid('study_plan_id')->constrained();
            $table->uuid('subject_id');
            $table->uuid('prerequisite_subject_id');
            $table->string('kind');
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->unique(['study_plan_id', 'subject_id', 'prerequisite_subject_id']);
            $table->index(['study_plan_id', 'prerequisite_subject_id']);
            $table->foreign(['study_plan_id', 'subject_id'])->references(['study_plan_id', 'subject_id'])->on('study_plan_subjects');
            $table->foreign(['study_plan_id', 'prerequisite_subject_id'])->references(['study_plan_id', 'subject_id'])->on('study_plan_subjects');
        });

        DB::statement('alter table subject_prerequisites add constraint subject_prerequisites_not_itself_check check (subject_id <> prerequisite_subject_id)');
    }

    public function down(): void
    {
        Schema::dropIfExists('subject_prerequisites');
    }
};
