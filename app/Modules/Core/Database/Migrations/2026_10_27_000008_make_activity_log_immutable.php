<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * La bitácora nunca se edita ni se borra (docs/dominio/modelo-de-datos.md): PostgreSQL rechaza
 * UPDATE, DELETE y TRUNCATE sobre `activity_log` venga de donde venga (Eloquent, query builder o
 * SQL directo). INSERT sigue permitido. Por eso `activitylog:clean` no se usa.
 *
 * `create or replace` porque `migrate:fresh` borra las tablas (y con ellas los triggers) pero no
 * las funciones.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            create or replace function activity_log_reject_changes() returns trigger
            language plpgsql as $$
            begin
                raise exception 'activity_log es inmutable: no se permite %', tg_op;
            end;
            $$;

            create trigger activity_log_immutable_rows
                before update or delete on activity_log
                for each row execute function activity_log_reject_changes();

            create trigger activity_log_immutable_truncate
                before truncate on activity_log
                for each statement execute function activity_log_reject_changes();
            SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            drop trigger if exists activity_log_immutable_truncate on activity_log;
            drop trigger if exists activity_log_immutable_rows on activity_log;
            drop function if exists activity_log_reject_changes();
            SQL);
    }
};
