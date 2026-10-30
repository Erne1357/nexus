<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Evaluación docente de un periodo, con la escala de 1 a 5 del TecNM; un puntaje fuera de 0 a 5 es error
 * (confirmado el 30 de octubre de 2026). Usa `term_code` del SII y no `term_id`, porque las evaluaciones
 * vienen de periodos que `terms` no tiene (como `kardex_records`). Única por docente, periodo y tipo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('teacher_evaluations', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->foreignUuid('teacher_id')->constrained();
            $table->string('term_code');
            $table->string('kind');
            $table->decimal('score', 3, 2);
            $table->string('source')->nullable();
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->unique(['teacher_id', 'term_code', 'kind']);
        });

        DB::statement("alter table teacher_evaluations add constraint teacher_evaluations_term_code_check check (term_code ~ '^[0-9]{4}[123]$')");
        DB::statement("alter table teacher_evaluations add constraint teacher_evaluations_kind_check check (kind in ('estudiantil', 'departamental'))");
        DB::statement('alter table teacher_evaluations add constraint teacher_evaluations_score_check check (score between 0 and 5)');
    }

    public function down(): void
    {
        Schema::dropIfExists('teacher_evaluations');
    }
};
