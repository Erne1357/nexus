<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Revisión de un catálogo de un departamento en un periodo (docs/dominio/modelo-de-datos.md,
 * "CatalogValidation"). El jefe valida docentes, materias, planes y carreras una vez por periodo para todo
 * el Tec (`campus_id` nulo) y los salones de su departamento en cada campus (D2, confirmado el 1 de
 * noviembre de 2026): un CHECK exige campus si y solo si el catálogo es `rooms`. Única por periodo,
 * campus, departamento y catálogo, con el campus nulo como un valor más. CHECK de catálogo y de estado.
 * No es catálogo: no lleva `is_active` ni borrado lógico. Las observaciones por registro
 * (`catalog_review_flags`) llegan con la tarea 2.4.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('catalog_reviews', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->foreignUuid('term_id')->constrained();
            $table->foreignUuid('campus_id')->nullable()->constrained();
            $table->foreignUuid('department_id')->constrained();
            $table->string('catalog');
            $table->string('status')->default('pending');
            $table->foreignUuid('validated_by')->nullable()->constrained('users');
            $table->timestampTz('validated_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestampsTz();

            $table->unique(['term_id', 'campus_id', 'department_id', 'catalog'])->nullsNotDistinct();
            $table->index('campus_id');
            $table->index('department_id');
        });

        DB::statement("alter table catalog_reviews add constraint catalog_reviews_catalog_check check (catalog in ('teachers', 'rooms', 'subjects', 'study_plans', 'careers'))");
        DB::statement("alter table catalog_reviews add constraint catalog_reviews_status_check check (status in ('pending', 'in_review', 'validated'))");
        DB::statement("alter table catalog_reviews add constraint catalog_reviews_campus_check check ((catalog = 'rooms') = (campus_id is not null))");
    }

    public function down(): void
    {
        Schema::dropIfExists('catalog_reviews');
    }
};
