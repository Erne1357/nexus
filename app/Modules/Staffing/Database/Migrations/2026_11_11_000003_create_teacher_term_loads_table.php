<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Horas de un docente en un periodo (docs/dominio/modelo-de-datos.md, `teacher_term_loads`; tarea 3.8): tabla derivada
 * para tableros y para validar HC-08. `limit_hours` es el tope (horas de base más la tolerancia, o el tope de
 * honorarios) y `remaining_hours` puede ser negativo cuando el docente rebasa el tope. Una fila por docente y periodo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('teacher_term_loads', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->foreignUuid('teacher_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('term_id')->constrained();
            $table->smallInteger('base_hours');
            $table->smallInteger('external_hours');
            $table->smallInteger('assigned_hours');
            $table->smallInteger('limit_hours');
            $table->smallInteger('remaining_hours');
            $table->smallInteger('groups_count');
            $table->timestampTz('computed_at');
            $table->timestampsTz();

            $table->unique(['teacher_id', 'term_id']);
        });

        foreach (['base_hours', 'external_hours', 'assigned_hours', 'limit_hours', 'groups_count'] as $column) {
            DB::statement("alter table teacher_term_loads add constraint teacher_term_loads_{$column}_check check ({$column} >= 0)");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('teacher_term_loads');
    }
};
