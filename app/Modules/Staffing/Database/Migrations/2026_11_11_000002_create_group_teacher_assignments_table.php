<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Historial de asignaciones de docente a grupo (docs/dominio/modelo-de-datos.md, `group_teacher_assignments`;
 * tarea 3.8). El docente vigente es `groups.teacher_id`; aquí queda quién asignó a quién, cuándo, en qué lugar del
 * ranking (de cuántos) y por qué.
 *
 * - HC-16: asignar a alguien que no era el primero del ranking (o que no estaba en él, posición nula) exige un motivo
 *   con texto. La base lo garantiza con un CHECK.
 * - Una sola asignación vigente por grupo: índice único parcial donde `replaced_at is null`.
 * - Solo se agrega: un trigger rechaza DELETE y TRUNCATE, y de un UPDATE solo deja fijar, una vez, `replaced_at` y
 *   `replaced_by` (y `updated_at`) mientras la fila siga vigente; cualquier otro cambio se rechaza. Las pruebas que
 *   truncan con CASCADE desde una tabla padre deben desactivar `group_teacher_assignments_append_only_truncate`
 *   (como `ExportDemoFilesTest` con `load_transitions`).
 *
 * `create or replace` porque `migrate:fresh` borra las tablas (y sus triggers) pero no las funciones.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('group_teacher_assignments', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->foreignUuid('term_id')->constrained();
            $table->foreignUuid('campus_id')->constrained();
            $table->foreignUuid('group_id')->constrained();
            $table->foreignUuid('teacher_id')->constrained();
            $table->foreignUuid('assigned_by')->constrained('users');
            $table->timestampTz('assigned_at');
            $table->smallInteger('ranking_position_at_assignment')->nullable();
            $table->smallInteger('ranking_size_at_assignment')->nullable();
            $table->text('reason')->nullable();
            $table->timestampTz('replaced_at')->nullable();
            $table->foreignUuid('replaced_by')->nullable()->constrained('users');
            $table->timestampsTz();

            $table->index(['group_id', 'assigned_at']);
            $table->index(['teacher_id', 'term_id']);
        });

        DB::statement('alter table group_teacher_assignments add constraint group_teacher_assignments_position_check check (ranking_position_at_assignment is null or ranking_position_at_assignment >= 1)');
        DB::statement('alter table group_teacher_assignments add constraint group_teacher_assignments_size_check check (ranking_size_at_assignment is null or ranking_size_at_assignment >= 1)');
        DB::statement("alter table group_teacher_assignments add constraint group_teacher_assignments_hc16_check check (coalesce(ranking_position_at_assignment = 1, false) or (reason is not null and btrim(reason) <> ''))");
        DB::statement('alter table group_teacher_assignments add constraint group_teacher_assignments_replaced_check check (replaced_at is null or replaced_at >= assigned_at)');
        DB::statement('alter table group_teacher_assignments add constraint group_teacher_assignments_replaced_pair_check check ((replaced_at is null) = (replaced_by is null))');
        DB::statement('create unique index group_teacher_assignments_one_current on group_teacher_assignments (group_id) where replaced_at is null');

        DB::unprepared(<<<'SQL'
            create or replace function group_teacher_assignments_guard() returns trigger
            language plpgsql as $$
            begin
                if tg_op = 'UPDATE' and old.replaced_at is null
                   and (to_jsonb(new) - 'replaced_at' - 'replaced_by' - 'updated_at')
                       = (to_jsonb(old) - 'replaced_at' - 'replaced_by' - 'updated_at') then
                    return new;
                end if;

                raise exception 'group_teacher_assignments es de solo agregar: no se permite %', tg_op;
            end;
            $$;

            create or replace function group_teacher_assignments_reject_truncate() returns trigger
            language plpgsql as $$
            begin
                raise exception 'group_teacher_assignments es de solo agregar: no se permite %', tg_op;
            end;
            $$;

            create trigger group_teacher_assignments_append_only_rows
                before update or delete on group_teacher_assignments
                for each row execute function group_teacher_assignments_guard();

            create trigger group_teacher_assignments_append_only_truncate
                before truncate on group_teacher_assignments
                for each statement execute function group_teacher_assignments_reject_truncate();
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('group_teacher_assignments');
        DB::unprepared('drop function if exists group_teacher_assignments_guard(); drop function if exists group_teacher_assignments_reject_truncate();');
    }
};
