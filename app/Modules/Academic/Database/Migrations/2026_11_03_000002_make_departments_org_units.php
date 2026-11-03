<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Cada departamento académico es también una unidad del organigrama con la misma llave
 * (ADR-0018, AZ-S2): así las nueve tablas con `department_id` siguen apuntando a `departments`.
 * Tres triggers mantienen la unidad igual al departamento; los nuevos nacen bajo la Subdirección
 * Académica (supuesto por confirmar). Se da unidad también a los departamentos borrados.
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
            select d.id, (select id from org_units where code = 'SUB-ACADEMICA' and deleted_at is null),
                   d.code, d.name, 'departamento_academico', 0, d.is_active, d.deleted_at, now(), now()
            from departments d
            on conflict (id) do nothing;

            alter table departments add constraint departments_id_org_unit_foreign
                foreign key (id) references org_units (id);

            create or replace function departments_create_org_unit() returns trigger
            language plpgsql as $$
            begin
                insert into org_units (id, parent_id, code, name, kind, sort, is_active, deleted_at, created_at, updated_at)
                values (new.id, (select id from org_units where code = 'SUB-ACADEMICA' and deleted_at is null),
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
            drop trigger if exists departments_delete_org_unit on departments;
            drop trigger if exists departments_sync_org_unit on departments;
            drop trigger if exists departments_create_org_unit on departments;
            drop function if exists departments_delete_org_unit();
            drop function if exists departments_sync_org_unit();
            drop function if exists departments_create_org_unit();
            alter table departments drop constraint if exists departments_id_org_unit_foreign;
            delete from org_units where kind = 'departamento_academico';
            SQL);
    }
};
