<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Cada departamento académico es también una unidad del organigrama con la misma llave
 * (ADR-0018, AZ-S2): así las nueve tablas con `department_id` siguen apuntando a `departments`.
 * Tres triggers mantienen la unidad igual al departamento; los nuevos nacen bajo la Subdirección
 * Académica (supuesto por confirmar; si no existe, bajo la raíz). Otros dos triggers sobre `org_units`
 * impiden editar, re-tipar o borrar directamente la unidad de un departamento (solo se puede moverla).
 * Los departamentos no se borran físicamente en producción: borrar uno cuya unidad tenga unidades
 * hijas falla por la FK de `parent_id` (se acepta). El trigger de alta no hace nada si la clave ya existe: un
 * `INSERT … ON CONFLICT (code) DO UPDATE` dispara el BEFORE INSERT con un id nuevo y dejaría una unidad huérfana. Se da unidad también a los departamentos borrados.
 *
 * `create or replace` porque `migrate:fresh` borra las tablas (y con ellas los triggers) pero no
 * las funciones.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            insert into org_units (id, parent_id, code, name, kind, sort, is_active, deleted_at, created_at, updated_at)
            select d.id, (select id from org_units where code = 'SUB-ACADEMICA' and kind <> 'departamento_academico' and deleted_at is null),
                   d.code, d.name, 'departamento_academico', 0, d.is_active, d.deleted_at, now(), now()
            from departments d
            on conflict (id) do nothing;

            alter table departments add constraint departments_id_org_unit_foreign
                foreign key (id) references org_units (id);

            create or replace function departments_create_org_unit() returns trigger
            language plpgsql as $$
            declare
                parent uuid;
            begin
                if exists (select 1 from departments where code = new.code) then
                    return new;
                end if;

                parent := coalesce(
                    (select id from org_units where code = 'SUB-ACADEMICA' and kind <> 'departamento_academico' and deleted_at is null and is_active),
                    (select id from org_units where parent_id is null and deleted_at is null and is_active)
                );

                if parent is null then
                    raise exception 'No hay unidad padre para el departamento: falta la Dirección.';
                end if;

                insert into org_units (id, parent_id, code, name, kind, sort, is_active, deleted_at, created_at, updated_at)
                values (new.id, parent,
                        new.code, new.name, 'departamento_academico', 0, new.is_active, new.deleted_at, now(), now())
                on conflict (id) do nothing;
                return new;
            end;
            $$;

            create or replace function departments_sync_org_unit() returns trigger
            language plpgsql as $$
            begin
                update org_units set code = new.code, name = new.name, is_active = new.is_active,
                                     deleted_at = new.deleted_at, updated_at = now() where id = new.id;
                return new;
            end;
            $$;

            create or replace function departments_delete_org_unit() returns trigger
            language plpgsql as $$
            begin
                delete from org_units where id = old.id;
                return old;
            end;
            $$;

            create or replace function org_units_guard_department_update() returns trigger
            language plpgsql as $$
            begin
                if (old.kind = 'departamento_academico' or new.kind = 'departamento_academico') and old.kind <> new.kind then
                    raise exception 'El tipo de una unidad de departamento académico no se cambia.';
                end if;

                if old.kind = 'departamento_academico'
                    and exists (select 1 from departments where id = old.id)
                    and not exists (
                        select 1 from departments d
                        where d.id = old.id and d.code = new.code and d.name = new.name
                            and d.is_active = new.is_active and d.deleted_at is not distinct from new.deleted_at
                    ) then
                    raise exception 'La unidad de un departamento académico se edita desde el departamento.';
                end if;

                return new;
            end;
            $$;

            create or replace function org_units_guard_department_delete() returns trigger
            language plpgsql as $$
            begin
                if old.kind = 'departamento_academico' and exists (select 1 from departments where id = old.id) then
                    raise exception 'La unidad de un departamento académico se borra desde el departamento.';
                end if;

                return old;
            end;
            $$;

            create trigger org_units_guard_department_update
                before update on org_units
                for each row execute function org_units_guard_department_update();

            create trigger org_units_guard_department_delete
                before delete on org_units
                for each row execute function org_units_guard_department_delete();

            create trigger departments_create_org_unit
                before insert on departments
                for each row execute function departments_create_org_unit();

            create trigger departments_sync_org_unit
                after update of code, name, is_active, deleted_at on departments
                for each row execute function departments_sync_org_unit();

            create trigger departments_delete_org_unit
                after delete on departments
                for each row execute function departments_delete_org_unit();
            SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            drop trigger if exists org_units_guard_department_delete on org_units;
            drop trigger if exists org_units_guard_department_update on org_units;
            drop trigger if exists departments_delete_org_unit on departments;
            drop trigger if exists departments_sync_org_unit on departments;
            drop trigger if exists departments_create_org_unit on departments;
            drop function if exists org_units_guard_department_delete();
            drop function if exists org_units_guard_department_update();
            drop function if exists departments_delete_org_unit();
            drop function if exists departments_sync_org_unit();
            drop function if exists departments_create_org_unit();
            alter table departments drop constraint if exists departments_id_org_unit_foreign;
            delete from org_units where kind = 'departamento_academico';
            SQL);
    }
};
