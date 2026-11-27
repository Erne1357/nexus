<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Horas que un docente da fuera de Nexus en un periodo (por ejemplo TecNM Virtual); se restan de sus horas
 * disponibles. De 1 a 40 y una fila por docente, periodo y origen, también contra una borrada (S16, supuesto
 * por confirmar).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('teacher_external_loads', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->foreignUuid('teacher_id')->constrained();
            $table->foreignUuid('term_id')->constrained();
            $table->string('source');
            $table->unsignedSmallInteger('hours');
            $table->text('notes')->nullable();
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->unique(['teacher_id', 'term_id', 'source']);
            $table->index('term_id');
        });

        DB::statement('alter table teacher_external_loads add constraint teacher_external_loads_hours_check check (hours between 1 and 40)');
    }

    public function down(): void
    {
        Schema::dropIfExists('teacher_external_loads');
    }
};
