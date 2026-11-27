<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Una fila por materia, carrera y campus de una evaluación (docs/dominio/modelo-de-datos.md,
 * `forecast_evaluation_rows`): lo proyectado contra lo que de verdad se cursó. `baseline` son los elegibles, la regla
 * de R1, para ver si la proyección la mejora.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('forecast_evaluation_rows', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->foreignUuid('evaluation_id')->constrained('forecast_evaluations')->cascadeOnDelete();
            $table->foreignUuid('career_id')->constrained();
            $table->foreignUuid('campus_id')->nullable()->constrained();
            $table->foreignUuid('subject_id')->constrained();
            $table->integer('eligible');
            $table->integer('projected');
            $table->integer('suggested_groups');
            $table->integer('actual_students');
            $table->integer('actual_groups')->nullable();
            $table->integer('baseline');
            $table->string('forecast_method');
            $table->timestampsTz();

            $table->index(['career_id', 'campus_id']);
        });

        DB::statement('alter table forecast_evaluation_rows add constraint forecast_evaluation_rows_unique unique nulls not distinct (evaluation_id, career_id, campus_id, subject_id)');
        DB::statement('alter table forecast_evaluation_rows add constraint forecast_evaluation_rows_counts_check check (eligible >= 0 and projected >= 0 and suggested_groups >= 0 and actual_students >= 0 and (actual_groups is null or actual_groups >= 0) and baseline >= 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('forecast_evaluation_rows');
    }
};
