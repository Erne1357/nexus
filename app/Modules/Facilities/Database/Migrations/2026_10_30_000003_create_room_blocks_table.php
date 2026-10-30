<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Bloqueos de un salón (mantenimiento, reservado). `term_id` nulo aplica a todos los periodos; `day` va de
 * 1 (lunes) a 6 (sábado), como `campuses.settings.days`; la hora de fin es posterior a la de inicio.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('room_blocks', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->foreignUuid('room_id')->constrained();
            $table->foreignUuid('term_id')->nullable()->constrained();
            $table->smallInteger('day');
            $table->time('starts_at');
            $table->time('ends_at');
            $table->string('reason');
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index(['room_id', 'term_id']);
            $table->index('term_id');
        });

        DB::statement('alter table room_blocks add constraint room_blocks_day_check check (day between 1 and 6)');
        DB::statement('alter table room_blocks add constraint room_blocks_time_check check (ends_at > starts_at)');
    }

    public function down(): void
    {
        Schema::dropIfExists('room_blocks');
    }
};
