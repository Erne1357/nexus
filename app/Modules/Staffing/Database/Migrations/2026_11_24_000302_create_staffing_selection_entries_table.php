<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Un renglón por docente en la cola de una selección (tarea 1.33, diseño 6.1). `teacher_id` es nulo mientras el nombre
 * del CSV no se empata con un docente de Nexus; los criterios del CSV son nulos en los renglones agregados.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('staffing_selection_entries', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->foreignUuid('selection_id')->constrained('staffing_selections')->cascadeOnDelete();
            $table->foreignUuid('teacher_id')->nullable()->constrained();
            $table->string('source', 10)->default('csv');
            $table->string('csv_name')->nullable();
            $table->integer('csv_line')->nullable();
            $table->smallInteger('base_hours')->default(0);
            $table->boolean('auto_eval')->nullable();
            $table->boolean('prior_compliance')->nullable();
            $table->boolean('partials_1')->nullable();
            $table->boolean('partials_2')->nullable();
            $table->decimal('seniority_years', 5, 2)->nullable();
            $table->date('hired_on')->nullable();
            $table->smallInteger('plaza_tier')->default(0);
            $table->smallInteger('failed_criteria')->default(0);
            $table->smallInteger('tier')->default(0);
            $table->integer('position')->default(0);
            $table->integer('slot_index')->default(0);
            $table->timestampTz('slot_starts_at')->nullable();
            $table->timestampTz('slot_ends_at')->nullable();
            $table->timestampTz('extra_slot_starts_at')->nullable();
            $table->timestampTz('extra_slot_ends_at')->nullable();
            $table->string('status', 20)->default('waiting');
            $table->timestampTz('closed_at')->nullable();
            $table->smallInteger('closed_hours')->nullable();
            $table->timestampTz('out_of_band_acknowledged_at')->nullable();
            $table->timestampsTz();

            $table->unique(['selection_id', 'teacher_id']);
            $table->index(['selection_id', 'position']);
        });

        DB::statement("alter table staffing_selection_entries add constraint staffing_selection_entries_source_check check (source in ('csv','appended'))");
        DB::statement("alter table staffing_selection_entries add constraint staffing_selection_entries_status_check check (status in ('waiting','done','pending_extra','unresolved'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('staffing_selection_entries');
    }
};
