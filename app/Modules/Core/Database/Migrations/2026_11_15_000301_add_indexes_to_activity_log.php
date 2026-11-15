<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Índices para «Bitácora» (CF-S5): la lista va de la entrada más nueva a la más vieja y se filtra por fechas y por quién
 * causó el cambio. Crear un índice no edita ninguna fila, así que no choca con el trigger que hace inmutable a
 * `activity_log` (make_activity_log_immutable); la tabla sigue sin UPDATE, DELETE ni TRUNCATE.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('create index activity_log_created_at_index on activity_log (created_at)');
        DB::statement('create index activity_log_causer_id_id_index on activity_log (causer_id, id)');
        // Para la lista de eventos del filtro (select distinct); el tipo de registro ya lo cubre el índice `subject`.
        DB::statement('create index activity_log_event_index on activity_log (event)');
    }

    public function down(): void
    {
        DB::statement('drop index if exists activity_log_event_index');
        DB::statement('drop index if exists activity_log_causer_id_id_index');
        DB::statement('drop index if exists activity_log_created_at_index');
    }
};
