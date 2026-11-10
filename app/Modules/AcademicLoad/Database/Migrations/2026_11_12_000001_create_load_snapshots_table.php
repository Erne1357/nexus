<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fotos publicadas de la carga (docs/dominio/modelo-de-datos.md, `load_snapshots`): una versión por cada publicación
 * de una carga, con el payload que leen la consulta publicada y los exportes (forma en `Rules\SnapshotPayload`).
 * Solo se inserta: HC-18, un trigger rechaza UPDATE, DELETE y TRUNCATE, como en `load_transitions`. La versión
 * empieza en 1 y no se repite por carga, así que dos publicaciones de la misma carga no pueden quedar con el mismo
 * número aunque se crucen. Sin `updated_at`.
 *
 * `create or replace` porque `migrate:fresh` borra las tablas (y con ellas los triggers) pero no las funciones.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('load_snapshots', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->foreignUuid('academic_load_id')->constrained();
            $table->integer('version');
            $table->jsonb('payload');
            $table->foreignUuid('created_by')->constrained('users');
            $table->timestampTz('created_at')->useCurrent();

            $table->unique(['academic_load_id', 'version']);
        });

        DB::statement('alter table load_snapshots add constraint load_snapshots_version_check check (version >= 1)');

        DB::unprepared(<<<'SQL'
            create or replace function load_snapshots_reject_changes() returns trigger
            language plpgsql as $$
            begin
                raise exception 'load_snapshots es inmutable: no se permite %', tg_op;
            end;
            $$;

            create trigger load_snapshots_immutable_rows
                before update or delete on load_snapshots
                for each row execute function load_snapshots_reject_changes();

            create trigger load_snapshots_immutable_truncate
                before truncate on load_snapshots
                for each statement execute function load_snapshots_reject_changes();
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('load_snapshots');
        DB::unprepared('drop function if exists load_snapshots_reject_changes()');
    }
};
