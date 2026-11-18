<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Evaluaciones «como si» de la proyección de demanda (DM-20 a DM-25; docs/dominio/modelo-de-datos.md,
 * `forecast_evaluations`): una por periodo, modalidad y campus (nulo = todos los de la modalidad). `method` es la
 * versión de la fórmula que da Demand; `totals` guarda las métricas globales.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('forecast_evaluations', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->foreignUuid('term_id')->constrained();
            $table->foreignUuid('modality_id')->constrained();
            $table->foreignUuid('campus_id')->nullable()->constrained();
            $table->string('status');
            $table->string('method');
            $table->integer('history_terms');
            $table->jsonb('totals')->nullable();
            $table->foreignUuid('requested_by')->nullable()->constrained('users');
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('finished_at')->nullable();
            $table->text('error')->nullable();
            $table->timestampsTz();

            $table->index(['modality_id', 'status', 'finished_at']);
        });

        DB::statement("alter table forecast_evaluations add constraint forecast_evaluations_status_check check (status in ('queued', 'running', 'done', 'failed'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('forecast_evaluations');
    }
};
