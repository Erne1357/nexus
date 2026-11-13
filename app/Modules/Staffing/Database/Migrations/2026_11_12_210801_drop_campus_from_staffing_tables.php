<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Cambiar (ronda 2 de la carga por modalidad, M1 y M8): `staffing_rankings`, `group_teacher_assignments` y
 * `change_requests` dejan de copiar el campus de su grupo. El ranking se calcula por periodo, modalidad y departamento,
 * con los grupos de todos sus campus, y quien necesita el campus (el filtro de una pantalla, el campus que declara un
 * permiso de escritura, MS6) lo lee de `groups.campus_id`; así un grupo que cambia de campus no deja copias viejas.
 * Siguen la copia del periodo y la de la modalidad. Quitar la columna quita también su llave y sus índices
 * (`staffing_rankings (term_id, campus_id)` y `change_requests (term_id, campus_id, department_id, status)`); los de
 * modalidad de `2026_11_12_210403_add_modality_to_staffing_tables` ya los reemplazan. Quitar una columna no dispara el
 * trigger de solo agregar de `group_teacher_assignments`, y su función compara la fila completa, así que sigue igual.
 *
 * down() vuelve a poner la columna con el campus de cada grupo y sus índices. En el historial de asignaciones apaga solo
 * el trigger de filas para llenarla y lo vuelve a encender enseguida, dentro de la misma transacción, como la ampliación.
 */
return new class extends Migration
{
    /**
     * Cada tabla con los índices de campus que tenía.
     *
     * @var array<string, array<string, list<string>>>
     */
    private const array TABLES = [
        'staffing_rankings' => [
            'staffing_rankings_term_id_campus_id_index' => ['term_id', 'campus_id'],
        ],
        'group_teacher_assignments' => [],
        'change_requests' => [
            'change_requests_term_id_campus_id_department_id_status_index' => ['term_id', 'campus_id', 'department_id', 'status'],
        ],
    ];

    /**
     * Los triggers de solo agregar que rechazarían el llenado de down(), por tabla.
     *
     * @var array<string, string>
     */
    private const array APPEND_ONLY_TRIGGERS = [
        'group_teacher_assignments' => 'group_teacher_assignments_append_only_rows',
    ];

    public function up(): void
    {
        DB::transaction(function (): void {
            foreach (array_keys(self::TABLES) as $table) {
                Schema::table($table, function (Blueprint $blueprint) {
                    $blueprint->dropConstrainedForeignId('campus_id');
                });
            }
        });
    }

    public function down(): void
    {
        DB::transaction(function (): void {
            foreach (self::TABLES as $table => $indexes) {
                Schema::table($table, function (Blueprint $blueprint) {
                    $blueprint->foreignUuid('campus_id')->nullable()->constrained();
                });

                $trigger = self::APPEND_ONLY_TRIGGERS[$table] ?? null;

                if ($trigger !== null) {
                    DB::statement("alter table {$table} disable trigger {$trigger}");
                }

                DB::statement("update {$table} set campus_id = groups.campus_id from groups where groups.id = {$table}.group_id");

                if ($trigger !== null) {
                    DB::statement("alter table {$table} enable trigger {$trigger}");
                }

                DB::statement("alter table {$table} alter column campus_id set not null");

                Schema::table($table, function (Blueprint $blueprint) use ($indexes) {
                    foreach ($indexes as $name => $columns) {
                        $blueprint->index($columns, $name);
                    }
                });
            }
        });
    }
};
