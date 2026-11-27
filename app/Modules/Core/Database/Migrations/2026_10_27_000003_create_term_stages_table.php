<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Calendario del proceso: ventana de fechas de cada etapa del periodo. `campus_id` nulo aplica
 * a todos los campus. Una etapa tiene una sola ventana por periodo y campus, y una sola para
 * todos los campus (`campus_id` nulo cuenta como un valor más para la unicidad). Una ventana no
 * termina antes de empezar.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('term_stages', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->foreignUuid('term_id')->constrained();
            $table->foreignUuid('campus_id')->nullable()->constrained();
            $table->string('stage');
            $table->date('starts_on');
            $table->date('ends_on');
            $table->timestampsTz();

            $table->unique(['term_id', 'campus_id', 'stage'])->nullsNotDistinct();
            $table->index('campus_id');
        });

        DB::statement('alter table term_stages add constraint term_stages_dates_check check (ends_on >= starts_on)');
    }

    public function down(): void
    {
        Schema::dropIfExists('term_stages');
    }
};
