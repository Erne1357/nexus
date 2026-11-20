<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Sesiones (día, hora, aula, docente) de los grupos históricos del SII (tarea 1.24.1, docs/dominio/modelo-de-datos.md,
 * `historical_group_sessions`), para que el mapa del campus muestre las clases reales de un periodo pasado. AcademicLoad no
 * depende de Facilities ni de Faculty, así que el aula y el docente van como texto (`room_code` normalizado como
 * RoomRules, `teacher_rfc` tal cual el SII) y quien las lee (Scheduling) los resuelve. Se borran en cascada con su grupo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('historical_group_sessions', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->foreignUuid('historical_group_id')->constrained('historical_groups')->cascadeOnDelete();
            $table->smallInteger('day');
            $table->time('starts_at');
            $table->time('ends_at');
            $table->string('room_code')->nullable();
            $table->string('teacher_rfc')->nullable();
            $table->timestamps();

            $table->index('historical_group_id');
        });

        DB::statement('alter table historical_group_sessions add constraint historical_group_sessions_day_check check (day between 1 and 6)');
        DB::statement('alter table historical_group_sessions add constraint historical_group_sessions_time_check check (ends_at > starts_at)');
    }

    public function down(): void
    {
        Schema::dropIfExists('historical_group_sessions');
    }
};
