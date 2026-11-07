<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Cupos por carrera (docs/dominio/modelo-de-datos.md, `group_quotas`; ADR-0006): reemplazan al «paralelo» del SII.
 * Uno por grupo y carrera; a lo más un dueño por grupo, y el dueño es la carrera dueña del grupo.
 *
 * HC-07: la suma de `max_seats` de un grupo no excede su capacidad. Un CHECK no ve otras filas, así que lo revisa un
 * trigger de restricción al escribir un cupo y al cambiar la capacidad del grupo; es diferible (inicialmente
 * inmediato) para que una Action baje capacidad y cupos en una misma transacción. Que todo grupo tenga el cupo de
 * su carrera dueña lo asegura la Action que lo crea.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('group_quotas', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->foreignUuid('group_id')->constrained();
            $table->foreignUuid('career_id')->constrained();
            $table->integer('max_seats');
            $table->boolean('is_owner')->default(false);
            $table->timestampsTz();

            $table->unique(['group_id', 'career_id']);
            $table->index('career_id');
        });

        DB::statement('alter table group_quotas add constraint group_quotas_max_seats_check check (max_seats >= 0)');
        DB::statement('create unique index group_quotas_one_owner on group_quotas (group_id) where is_owner');

        DB::unprepared(<<<'SQL'
            create or replace function group_quotas_check_group(target uuid) returns void language plpgsql as $$
            declare
                seats bigint;
                group_capacity integer;
            begin
                select capacity into group_capacity from groups where id = target;
                select coalesce(sum(max_seats), 0) into seats from group_quotas where group_id = target;

                if seats > group_capacity then
                    raise exception 'HC-07: los cupos del grupo suman % y su capacidad es %', seats, group_capacity
                        using errcode = '23514', constraint = 'group_quotas_within_capacity';
                end if;

                if exists (
                    select 1 from group_quotas q join groups g on g.id = q.group_id
                     where q.group_id = target and q.is_owner and q.career_id <> g.owner_career_id
                ) then
                    raise exception 'El cupo dueño del grupo debe ser de su carrera dueña'
                        using errcode = '23514', constraint = 'group_quotas_owner_career';
                end if;
            end
            $$;

            create or replace function group_quotas_check() returns trigger language plpgsql as $$
            begin
                perform group_quotas_check_group(new.group_id);

                return null;
            end
            $$;

            create constraint trigger group_quotas_check after insert or update on group_quotas
                deferrable initially immediate
                for each row execute function group_quotas_check();

            create or replace function groups_check_quotas() returns trigger language plpgsql as $$
            begin
                perform group_quotas_check_group(new.id);

                return null;
            end
            $$;

            create constraint trigger groups_check_quotas after update of capacity, owner_career_id on groups
                deferrable initially immediate
                for each row execute function groups_check_quotas();
            SQL);
    }

    public function down(): void
    {
        DB::unprepared('drop trigger if exists groups_check_quotas on groups; drop function if exists groups_check_quotas();');
        Schema::dropIfExists('group_quotas');
        DB::unprepared('drop function if exists group_quotas_check(); drop function if exists group_quotas_check_group(uuid);');
    }
};
