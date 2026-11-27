<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Requisitos que esperan (migración de la seriación del SII; decisión del usuario del 10 de noviembre de 2026,
 * confirmada): un requisito del SII con una materia en «Por validar» no puede entrar a `subject_prerequisites`, cuyas
 * llaves piden las dos materias en la retícula del plan. Se guarda aquí, ligado al plan, y entra solo a la seriación
 * cuando Servicios Escolares le asigna semestre a la materia (y a la otra, si también esperaba). Por eso las llaves van
 * a `subjects` y no a `study_plan_subjects`. Una vez por plan, materia y requisito; una materia no se pide a sí misma
 * y `kind` solo acepta los dos tipos de la seriación (CHECK). Uno que ya entró conserva su fila con `applied_at`: sin
 * borrado lógico, como los lugares pendientes (supuesto por confirmar). `note` dice por qué uno sigue esperando aunque
 * sus dos materias ya estén en la retícula (cerraría un ciclo de seriación).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('study_plan_prerequisite_pendings', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->foreignUuid('study_plan_id')->constrained();
            $table->foreignUuid('subject_id')->constrained();
            $table->foreignUuid('prerequisite_subject_id')->constrained('subjects');
            $table->string('kind');
            $table->string('source')->default('sii');
            $table->string('sii_grid', 50)->nullable();
            $table->text('note')->nullable();
            $table->timestampTz('applied_at')->nullable();
            $table->timestampsTz();

            $table->unique(['study_plan_id', 'subject_id', 'prerequisite_subject_id']);
            $table->index(['study_plan_id', 'prerequisite_subject_id']);
            $table->index(['study_plan_id', 'applied_at']);
        });

        DB::statement('alter table study_plan_prerequisite_pendings add constraint study_plan_prerequisite_pendings_not_itself_check check (subject_id <> prerequisite_subject_id)');
        DB::statement("alter table study_plan_prerequisite_pendings add constraint study_plan_prerequisite_pendings_kind_check check (kind in ('seriacion', 'correquisito'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('study_plan_prerequisite_pendings');
    }
};
