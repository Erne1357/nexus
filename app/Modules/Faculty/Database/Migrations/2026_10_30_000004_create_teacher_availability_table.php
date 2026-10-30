<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Disponibilidad de un docente en un periodo: solo las excepciones (no disponible) y las preferencias; lo
 * demás se da por disponible. `day` es 1 (lunes) a 6 (sábado). Dos bloques del mismo docente, periodo y día
 * no se traslapan: la exclusión usa `tsrange` sobre una fecha fija para comparar solo la hora, con el
 * extremo final abierto, de modo que 9–10 y 10–11 son contiguos. Los borrados no cuentan. `btree_gist` ya
 * está instalado por `department_heads` (ADR-0016).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('create extension if not exists btree_gist');

        Schema::create('teacher_availability', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->foreignUuid('teacher_id')->constrained();
            $table->foreignUuid('term_id')->constrained();
            $table->unsignedSmallInteger('day');
            $table->time('starts_at');
            $table->time('ends_at');
            $table->string('kind');
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index(['teacher_id', 'term_id']);
            $table->index('term_id');
        });

        DB::statement('alter table teacher_availability add constraint teacher_availability_day_check check (day between 1 and 6)');
        DB::statement('alter table teacher_availability add constraint teacher_availability_time_check check (ends_at > starts_at)');
        DB::statement("alter table teacher_availability add constraint teacher_availability_kind_check check (kind in ('unavailable', 'preferred'))");
        DB::statement(<<<'SQL'
            alter table teacher_availability add constraint teacher_availability_no_overlap exclude using gist (
                teacher_id with =,
                term_id with =,
                day with =,
                tsrange(date '2000-01-01' + starts_at, date '2000-01-01' + ends_at) with &&
            ) where (deleted_at is null)
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('teacher_availability');
    }
};
