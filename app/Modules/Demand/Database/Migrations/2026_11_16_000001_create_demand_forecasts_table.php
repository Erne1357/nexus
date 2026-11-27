<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * La proyección de demanda (tarea 5.1; docs/dominio/modelo-de-datos.md, «Demand»): una fila por periodo, modalidad,
 * campus, carrera y materia, que precarga la propuesta del coordinador y alimenta el tablero del departamento. Es una
 * tabla derivada que reemplaza el job de la 5.4 (decisión de modelado 5), sin borrado lógico ni bitácora.
 *
 * El campus es nulo solo en una modalidad sin salones, que lleva una sola columna, como `proposal_items` (MS5); por eso
 * la unicidad trata los nulos como iguales. Conteos no negativos y tasas de 0 a 1, nulas sin historia (CHECK). Mientras
 * las tablas de los módulos no se muevan a su esquema (ADR-0022, punto 6), nace en `public` como las demás.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('demand_forecasts', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->foreignUuid('term_id')->constrained();
            $table->foreignUuid('modality_id')->constrained();
            $table->foreignUuid('campus_id')->nullable()->constrained();
            $table->foreignUuid('career_id')->constrained();
            $table->foreignUuid('subject_id')->constrained();
            $table->integer('eligible_students')->default(0);
            $table->integer('pending_prerequisite_students')->default(0);
            $table->integer('current_students')->default(0);
            $table->integer('new_students')->nullable();
            $table->decimal('non_enrollment_rate', 5, 4)->nullable();
            $table->decimal('failure_rate', 5, 4)->nullable();
            $table->smallInteger('history_terms_used')->default(0);
            $table->integer('expected_repeaters')->default(0);
            $table->integer('expected_students')->default(0);
            $table->integer('suggested_groups')->default(0);
            $table->string('method');
            $table->string('formula_version', 20);
            $table->boolean('needs_review')->default(false);
            $table->timestampTz('computed_at');
            $table->timestampsTz();

            $table->index(['term_id', 'modality_id', 'career_id']);
            $table->index('subject_id');
            $table->index('campus_id');
        });

        DB::statement('alter table demand_forecasts add constraint demand_forecasts_key_unique unique nulls not distinct (term_id, modality_id, campus_id, career_id, subject_id)');

        foreach (['eligible_students', 'pending_prerequisite_students', 'current_students', 'new_students', 'history_terms_used', 'expected_repeaters', 'expected_students', 'suggested_groups'] as $column) {
            DB::statement("alter table demand_forecasts add constraint demand_forecasts_{$column}_check check ({$column} >= 0)");
        }

        foreach (['non_enrollment_rate', 'failure_rate'] as $column) {
            DB::statement("alter table demand_forecasts add constraint demand_forecasts_{$column}_check check ({$column} between 0 and 1)");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('demand_forecasts');
    }
};
