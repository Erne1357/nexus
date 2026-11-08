<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Ranking visible por grupo (docs/dominio/modelo-de-datos.md, `staffing_rankings`; tarea 3.8): los docentes que se
 * ofrecen para un grupo, con su lugar, su puntaje y el desglose que lo explica. Es una tabla derivada: se recalcula
 * cuando cambia cualquiera de sus entradas, así que se borra en cascada con el grupo o el docente. `term_id` y
 * `campus_id` son copia del grupo para filtrar sin unir. Cada docente aparece una vez por grupo y cada lugar lo ocupa
 * uno solo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('staffing_rankings', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->foreignUuid('term_id')->constrained();
            $table->foreignUuid('campus_id')->constrained();
            $table->foreignUuid('group_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('teacher_id')->constrained()->cascadeOnDelete();
            $table->smallInteger('rank');
            $table->decimal('score', 8, 2);
            $table->jsonb('breakdown');
            $table->timestampTz('computed_at');
            $table->timestampsTz();

            $table->unique(['group_id', 'teacher_id']);
            $table->unique(['group_id', 'rank']);
            $table->index(['term_id', 'campus_id']);
            $table->index(['teacher_id', 'term_id']);
        });

        DB::statement('alter table staffing_rankings add constraint staffing_rankings_rank_check check (rank >= 1)');
    }

    public function down(): void
    {
        Schema::dropIfExists('staffing_rankings');
    }
};
