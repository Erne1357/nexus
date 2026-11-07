<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Sesiones de grupo (docs/dominio/modelo-de-datos.md, `group_sessions`; tarea 3.1): una franja de un grupo en un día,
 * de inicio a fin (bloques consecutivos se guardan como una sola sesión), con su salón y su tipo.
 *
 * `term_id`, `campus_id`, `teacher_id` e `is_active` son copia del grupo, porque una restricción de exclusión solo
 * mira una tabla: un trigger los toma del grupo al escribir la sesión (no se pueden falsear), con el grupo bloqueado
 * para compartir para que un cambio de docente concurrente no deje una sesión con el docente viejo, y otro los
 * actualiza cuando el grupo cambia de docente, de periodo, de campus o entra o sale de cancelado. Con ellos la base es la red de seguridad de:
 *
 * - HC-01: un docente no tiene dos sesiones que se empalmen el mismo día y periodo, en ningún campus;
 * - HC-02: un salón no tiene dos sesiones que se empalmen el mismo día y periodo;
 * - un grupo no tiene dos sesiones propias que se empalmen.
 *
 * Las tres usan `tsrange` sobre una fecha fija, abierto al final, así que dos horas contiguas no se empalman, y no
 * cuentan las sesiones de un grupo cancelado. Son diferibles (inicialmente inmediatas) para que una Action pueda
 * intercambiar dos sesiones en una transacción. El salón debe ser del campus del grupo (llave compuesta a
 * `rooms (id, campus_id)`). Necesita `btree_gist` (ADR-0016).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('create extension if not exists btree_gist');

        Schema::create('group_sessions', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->foreignUuid('group_id')->constrained();
            $table->smallInteger('day');
            $table->time('starts_at');
            $table->time('ends_at');
            $table->uuid('room_id')->nullable();
            $table->string('kind')->default('teoria');
            $table->foreignUuid('term_id')->constrained();
            $table->foreignUuid('campus_id')->constrained();
            $table->foreignUuid('teacher_id')->nullable()->constrained();
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();

            $table->foreign(['room_id', 'campus_id'], 'group_sessions_room_campus_foreign')->references(['id', 'campus_id'])->on('rooms');
            $table->index('group_id');
            $table->index('room_id');
        });

        DB::statement('alter table group_sessions add constraint group_sessions_day_check check (day between 1 and 6)');
        DB::statement('alter table group_sessions add constraint group_sessions_time_check check (ends_at > starts_at)');
        DB::statement("alter table group_sessions add constraint group_sessions_kind_check check (kind in ('teoria', 'practica', 'virtual'))");

        $range = "tsrange(date '2000-01-01' + starts_at, date '2000-01-01' + ends_at)";

        DB::statement("alter table group_sessions add constraint group_sessions_group_no_overlap exclude using gist (
            group_id with =, day with =, {$range} with &&
        ) deferrable initially immediate");

        DB::statement("alter table group_sessions add constraint group_sessions_teacher_no_overlap exclude using gist (
            term_id with =, teacher_id with =, day with =, {$range} with &&
        ) where (teacher_id is not null and is_active) deferrable initially immediate");

        DB::statement("alter table group_sessions add constraint group_sessions_room_no_overlap exclude using gist (
            term_id with =, room_id with =, day with =, {$range} with &&
        ) where (room_id is not null and is_active) deferrable initially immediate");

        DB::unprepared(<<<'SQL'
            create or replace function group_sessions_copy_group() returns trigger language plpgsql as $$
            begin
                select g.term_id, g.campus_id, g.teacher_id, g.status <> 'cancelled'
                  into new.term_id, new.campus_id, new.teacher_id, new.is_active
                  from groups g
                 where g.id = new.group_id
                   for share;

                return new;
            end
            $$;

            create trigger group_sessions_copy_group before insert or update on group_sessions
                for each row execute function group_sessions_copy_group();

            create or replace function groups_sync_sessions() returns trigger language plpgsql as $$
            begin
                update group_sessions set group_id = group_id where group_id = new.id;

                return null;
            end
            $$;

            create trigger groups_sync_sessions after update of term_id, campus_id, teacher_id, status on groups
                for each row
                when (old.term_id is distinct from new.term_id
                    or old.campus_id is distinct from new.campus_id
                    or old.teacher_id is distinct from new.teacher_id
                    or (old.status = 'cancelled') is distinct from (new.status = 'cancelled'))
                execute function groups_sync_sessions();
            SQL);
    }

    public function down(): void
    {
        DB::unprepared('drop trigger if exists groups_sync_sessions on groups; drop function if exists groups_sync_sessions();');
        Schema::dropIfExists('group_sessions');
        DB::unprepared('drop function if exists group_sessions_copy_group();');
    }
};
