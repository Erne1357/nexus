<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Carga académica (docs/dominio/modelo-de-datos.md, "AcademicLoad"): la unidad de cierre es el departamento
 * en un campus (ADR-0008), así que hay una por periodo, campus y departamento. Nace en `draft` al abrir el
 * periodo en el campus (S-A6, supuesto por confirmar); el CHECK acepta solo los ocho estados del proceso.
 * Quién y cuándo cerró, aprobó y publicó quedan nulos hasta que pase; `current_version` es la del último
 * snapshot publicado (0: ninguno). No es catálogo: no lleva `is_active` ni borrado lógico.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('academic_loads', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->foreignUuid('term_id')->constrained();
            $table->foreignUuid('campus_id')->constrained();
            $table->foreignUuid('department_id')->constrained();
            $table->string('status')->default('draft');
            $table->foreignUuid('closed_by')->nullable()->constrained('users');
            $table->timestampTz('closed_at')->nullable();
            $table->foreignUuid('approved_by')->nullable()->constrained('users');
            $table->timestampTz('approved_at')->nullable();
            $table->foreignUuid('published_by')->nullable()->constrained('users');
            $table->timestampTz('published_at')->nullable();
            $table->integer('current_version')->default(0);
            $table->timestampsTz();

            $table->unique(['term_id', 'campus_id', 'department_id']);
            $table->index('campus_id');
            $table->index('department_id');
        });

        DB::statement("alter table academic_loads add constraint academic_loads_status_check check (status in ('draft', 'proposed', 'staffing', 'adjusting', 'closed', 'approved', 'published', 'reanalysis'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('academic_loads');
    }
};
