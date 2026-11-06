<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Lugares de retícula pendientes (D18 y D19 de la migración del SII): una materia del SII sin semestre y sin
 * materia con el mismo nombre de la que tomarlo no entra a `study_plan_subjects`, que nunca guarda un lugar sin
 * semestre; queda aquí hasta que el jefe de Servicios Escolares le asigna uno. Una vez por plan y materia. La
 * especialidad es del mismo plan (FK compuesta, como en `study_plan_subjects`). Un pendiente resuelto conserva su
 * fila con `resolved_at` y `resolved_by`: sin borrado lógico (supuesto por confirmar).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('study_plan_subject_pendings', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->foreignUuid('study_plan_id')->constrained();
            $table->foreignUuid('subject_id')->constrained();
            $table->uuid('specialty_id')->nullable();
            $table->string('source')->default('sii');
            $table->text('note')->nullable();
            $table->timestampTz('resolved_at')->nullable();
            $table->foreignUuid('resolved_by')->nullable()->constrained('users');
            $table->timestampsTz();

            $table->unique(['study_plan_id', 'subject_id']);
            $table->index(['study_plan_id', 'resolved_at']);
            $table->foreign(['study_plan_id', 'specialty_id'])->references(['study_plan_id', 'id'])->on('specialties');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('study_plan_subject_pendings');
    }
};
