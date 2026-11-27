<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * El perfil de flujo ya no lleva `heads_edit_rooms`: el jefe corrige los salones de su departamento en cualquier campus
 * al validar sus catálogos (decisión 5, confirmada el 12 de noviembre de 2026; reglas-de-negocio.md, CV2). Se quita la
 * clave de `campuses.settings`; CampusProfile ya ignoraba las claves que no conoce, así que esto solo limpia lo
 * guardado. down() no la regresa: su valor ya no decide nada.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("update campuses set settings = settings::jsonb - 'heads_edit_rooms' where jsonb_exists(settings::jsonb, 'heads_edit_rooms')");
    }

    public function down(): void
    {
        // Sin reversa (ver el docblock).
    }
};
