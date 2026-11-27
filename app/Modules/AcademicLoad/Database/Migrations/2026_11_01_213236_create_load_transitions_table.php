<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Historial de transiciones de la carga (docs/dominio/modelo-de-datos.md, `load_transitions`): de qué
 * estado a cuál, quién (siempre un usuario) y con qué comentario. Solo se inserta: HC-18, un trigger de
 * PostgreSQL rechaza UPDATE, DELETE y TRUNCATE venga de donde venga, igual que en `activity_log`. Sin
 * `updated_at`.
 *
 * `create or replace` porque `migrate:fresh` borra las tablas (y con ellas los triggers) pero no las
 * funciones.
 */
return new class extends Migration
{
    private const string STATUSES = "'draft', 'proposed', 'staffing', 'adjusting', 'closed', 'approved', 'published', 'reanalysis'";

    public function up(): void
    {
        Schema::create('load_transitions', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->foreignUuid('academic_load_id')->constrained();
            $table->string('from_status');
            $table->string('to_status');
            $table->foreignUuid('user_id')->constrained();
            $table->text('comment')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->index('academic_load_id');
        });

        DB::statement('alter table load_transitions add constraint load_transitions_from_status_check check (from_status in ('.self::STATUSES.'))');
        DB::statement('alter table load_transitions add constraint load_transitions_to_status_check check (to_status in ('.self::STATUSES.'))');

        DB::unprepared(<<<'SQL'
            create or replace function load_transitions_reject_changes() returns trigger
            language plpgsql as $$
            begin
                raise exception 'load_transitions es inmutable: no se permite %', tg_op;
            end;
            $$;

            create trigger load_transitions_immutable_rows
                before update or delete on load_transitions
                for each row execute function load_transitions_reject_changes();

            create trigger load_transitions_immutable_truncate
                before truncate on load_transitions
                for each statement execute function load_transitions_reject_changes();
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('load_transitions');
        DB::unprepared('drop function if exists load_transitions_reject_changes()');
    }
};
