<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Ampliar (ronda 2 de la carga por modalidad, hacia M1 y ADR-0025; el primer paso de ampliar → cambiar → contraer):
 * `staffing_rankings`, `group_teacher_assignments` y `change_requests` reciben `modality_id` (FK obligatoria a
 * `modalities`), copiada de su grupo como ya copian el periodo y el campus (`groups.modality_id` ya es obligatoria desde
 * `2026_11_12_210402_add_modality_to_groups`, así que ninguna fila queda sin ella), con un índice igual a cada uno de
 * los de campus, cambiando `campus_id` por `modality_id`. Ningún lector cambia todavía, y `campus_id` sigue como está.
 *
 * `group_teacher_assignments` es de solo agregar: su trigger `group_teacher_assignments_append_only_rows` rechaza todo
 * UPDATE que no sea fijar `replaced_at` y `replaced_by` en una fila vigente, también el que llena la columna nueva. Para
 * llenarla se apaga ese trigger (`disable trigger`, solo él: el de TRUNCATE sigue) y se vuelve a encender enseguida,
 * dentro de la misma transacción, así que nadie escribe en la tabla con el trigger apagado y, si algo falla, la
 * transacción regresa el trigger encendido. El llenado solo escribe `modality_id` y no cambia ningún otro dato del
 * historial.
 *
 * down() quita las columnas, y con ellas sus índices y llaves (quitar una columna no dispara el trigger).
 */
return new class extends Migration
{
    /**
     * Cada tabla que copia la modalidad de su grupo, con los índices que repiten los de campus.
     *
     * @var array<string, array<string, list<string>>>
     */
    private const array TABLES = [
        'staffing_rankings' => [
            'staffing_rankings_term_modality_index' => ['term_id', 'modality_id'],
        ],
        'group_teacher_assignments' => [],
        'change_requests' => [
            'change_requests_term_modality_department_status_index' => ['term_id', 'modality_id', 'department_id', 'status'],
        ],
    ];

    /**
     * Los triggers de solo agregar que rechazarían el llenado, por tabla.
     *
     * @var array<string, string>
     */
    private const array APPEND_ONLY_TRIGGERS = [
        'group_teacher_assignments' => 'group_teacher_assignments_append_only_rows',
    ];

    public function up(): void
    {
        DB::transaction(function (): void {
            foreach (self::TABLES as $table => $indexes) {
                Schema::table($table, function (Blueprint $blueprint) {
                    $blueprint->foreignUuid('modality_id')->nullable()->constrained('modalities');
                });

                $trigger = self::APPEND_ONLY_TRIGGERS[$table] ?? null;

                if ($trigger !== null) {
                    DB::statement("alter table {$table} disable trigger {$trigger}");
                }

                DB::statement("update {$table} set modality_id = groups.modality_id from groups where groups.id = {$table}.group_id");

                if ($trigger !== null) {
                    DB::statement("alter table {$table} enable trigger {$trigger}");
                }

                DB::statement("alter table {$table} alter column modality_id set not null");

                Schema::table($table, function (Blueprint $blueprint) use ($indexes) {
                    foreach ($indexes as $name => $columns) {
                        $blueprint->index($columns, $name);
                    }
                });
            }
        });
    }

    public function down(): void
    {
        DB::transaction(function (): void {
            foreach (array_keys(self::TABLES) as $table) {
                Schema::table($table, function (Blueprint $blueprint) {
                    $blueprint->dropConstrainedForeignId('modality_id');
                });
            }
        });
    }
};
