<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Salón por defecto o sugerido de una materia en un campus. Única por materia, campus y salón, también contra
 * una fila borrada; la llave compuesta (`room_id`, `campus_id`) a `rooms` exige que el salón sea del campus.
 * Prioridad desde 1 (la menor se prefiere). Solo modelo: la llenan la fase 3 y el historial.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subject_room_defaults', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->foreignUuid('subject_id')->constrained();
            $table->foreignUuid('campus_id')->constrained();
            $table->uuid('room_id');
            $table->smallInteger('priority')->default(1);
            $table->string('source')->default('manual');
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->unique(['subject_id', 'campus_id', 'room_id']);
            $table->index('room_id');
            $table->foreign(['room_id', 'campus_id'])->references(['id', 'campus_id'])->on('rooms');
        });

        DB::statement('alter table subject_room_defaults add constraint subject_room_defaults_priority_check check (priority >= 1)');
        DB::statement("alter table subject_room_defaults add constraint subject_room_defaults_source_check check (source in ('manual', 'historial'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('subject_room_defaults');
    }
};
