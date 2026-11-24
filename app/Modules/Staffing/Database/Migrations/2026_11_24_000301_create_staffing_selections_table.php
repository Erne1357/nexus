<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Selección de carga por turnos (tarea 1.33, diseño 6.1): una por periodo, modalidad, y departamento (el campus es el lugar de los grupos, no un alcance: M1, MS3). Solo una
 * viva a la vez: la cancelada no cuenta para la unicidad.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('staffing_selections', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->foreignUuid('term_id')->constrained();
            $table->foreignUuid('modality_id')->constrained();
            $table->foreignUuid('department_id')->constrained();
            $table->string('status', 20)->default('draft');
            $table->timestampTz('starts_at')->nullable();
            $table->time('window_starts')->default('09:00');
            $table->time('window_ends')->default('14:00');
            $table->jsonb('weekdays')->default(DB::raw("'[1,2,3,4,5]'::jsonb"));
            $table->smallInteger('slot_minutes')->default(30);
            $table->timestampTz('published_at')->nullable();
            $table->timestampTz('first_round_ends_at')->nullable();
            $table->timestampTz('extra_round_starts_at')->nullable();
            $table->timestampTz('extra_round_ends_at')->nullable();
            $table->timestampTz('finished_at')->nullable();
            $table->timestampTz('cancelled_at')->nullable();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
        });

        DB::statement("alter table staffing_selections add constraint staffing_selections_status_check check (status in ('draft','published','running','extra_round','finished','cancelled'))");
        DB::statement('alter table staffing_selections add constraint staffing_selections_window_check check (window_starts < window_ends)');
        DB::statement('alter table staffing_selections add constraint staffing_selections_slot_minutes_check check (slot_minutes > 0)');
        DB::statement("create unique index staffing_selections_live_unique on staffing_selections (term_id, modality_id, department_id) where status <> 'cancelled'");
    }

    public function down(): void
    {
        Schema::dropIfExists('staffing_selections');
    }
};
