<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Ampliar (ronda 2 de la carga por modalidad, ADR-0025; el primer paso de ampliar → cambiar → contraer):
 *
 * - `groups` y `schedule_conflicts` reciben `modality_id` (FK obligatoria a `modalities`), llena con la modalidad del
 *   catálogo de su campus (el puente `campuses.modality_id`: C1 y C2 → `PRES`), con un índice igual a cada uno de los
 *   de campus, cambiando `campus_id` por `modality_id`;
 * - `group_sessions` recibe `modality_id` (FK obligatoria), copia de su grupo como el periodo, el campus y el docente:
 *   `group_sessions_copy_group` (de `2026_11_10_000002_create_group_sessions_table`) la copia también al escribir la
 *   sesión, y `groups_sync_sessions` también corre cuando el grupo cambia de modalidad.
 *
 * Ningún lector cambia todavía, y `campus_id` sigue como está. Si un grupo o un choque es de un campus sin modalidad del
 * catálogo (un campus guardado sin sus Actions, su sembrador ni su factory), se detiene con un RuntimeException que
 * nombra cada campus (clave e id) y las tablas donde tiene filas. Todo up() va en una transacción: si se detiene, no
 * deja nada.
 *
 * down() regresa los dos triggers a su forma anterior y quita las columnas, y con ellas sus índices y llaves.
 */
return new class extends Migration
{
    /**
     * Cada tabla que recibe la modalidad de su campus, con los índices que repiten los de campus.
     *
     * @var array<string, array<string, list<string>>>
     */
    private const array TABLES = [
        'groups' => [
            'groups_term_modality_career_plan_semester_index' => ['term_id', 'modality_id', 'owner_career_id', 'study_plan_id', 'semester'],
            'groups_term_modality_department_index' => ['term_id', 'modality_id', 'department_id'],
        ],
        'schedule_conflicts' => [
            'schedule_conflicts_term_modality_department_index' => ['term_id', 'modality_id', 'department_id'],
            'schedule_conflicts_term_modality_career_index' => ['term_id', 'modality_id', 'career_id'],
        ],
    ];

    public function up(): void
    {
        DB::transaction(function (): void {
            $this->ensureCampusesHaveModality();

            foreach (self::TABLES as $table => $indexes) {
                Schema::table($table, function (Blueprint $blueprint) {
                    $blueprint->foreignUuid('modality_id')->nullable()->constrained('modalities');
                });

                DB::statement("update {$table} set modality_id = campuses.modality_id from campuses where campuses.id = {$table}.campus_id");
                DB::statement("alter table {$table} alter column modality_id set not null");

                Schema::table($table, function (Blueprint $blueprint) use ($indexes) {
                    foreach ($indexes as $name => $columns) {
                        $blueprint->index($columns, $name);
                    }
                });
            }

            Schema::table('group_sessions', function (Blueprint $table) {
                $table->foreignUuid('modality_id')->nullable()->constrained('modalities');
            });

            DB::unprepared($this->copyFunction(withModality: true));
            DB::statement('update group_sessions set modality_id = groups.modality_id from groups where groups.id = group_sessions.group_id');
            DB::statement('alter table group_sessions alter column modality_id set not null');
            DB::unprepared($this->syncTrigger(withModality: true));
        });
    }

    public function down(): void
    {
        DB::transaction(function (): void {
            DB::unprepared($this->syncTrigger(withModality: false));
            DB::unprepared($this->copyFunction(withModality: false));

            foreach (['group_sessions', ...array_keys(self::TABLES)] as $table) {
                Schema::table($table, function (Blueprint $blueprint) {
                    $blueprint->dropConstrainedForeignId('modality_id');
                });
            }
        });
    }

    /**
     * La función del trigger que copia lo del grupo en la sesión, con o sin la modalidad.
     */
    private function copyFunction(bool $withModality): string
    {
        $columns = $withModality ? 'g.term_id, g.campus_id, g.modality_id' : 'g.term_id, g.campus_id';
        $targets = $withModality ? 'new.term_id, new.campus_id, new.modality_id' : 'new.term_id, new.campus_id';

        return <<<SQL
            create or replace function group_sessions_copy_group() returns trigger language plpgsql as \$\$
            begin
                select {$columns}, g.teacher_id, g.status <> 'cancelled'
                  into {$targets}, new.teacher_id, new.is_active
                  from groups g
                 where g.id = new.group_id
                   for share;

                return new;
            end
            \$\$;
            SQL;
    }

    /**
     * El trigger que vuelve a copiar en las sesiones lo que cambió del grupo, con o sin la modalidad.
     */
    private function syncTrigger(bool $withModality): string
    {
        $columns = $withModality ? 'term_id, campus_id, modality_id' : 'term_id, campus_id';
        $modality = $withModality ? 'or old.modality_id is distinct from new.modality_id' : '';

        return <<<SQL
            drop trigger if exists groups_sync_sessions on groups;

            create trigger groups_sync_sessions after update of {$columns}, teacher_id, status on groups
                for each row
                when (old.term_id is distinct from new.term_id
                    or old.campus_id is distinct from new.campus_id
                    {$modality}
                    or old.teacher_id is distinct from new.teacher_id
                    or (old.status = 'cancelled') is distinct from (new.status = 'cancelled'))
                execute function groups_sync_sessions();
            SQL;
    }

    /**
     * @throws RuntimeException si algún grupo o choque es de un campus sin modalidad del catálogo
     */
    private function ensureCampusesHaveModality(): void
    {
        $missing = [];

        foreach (array_keys(self::TABLES) as $table) {
            $rows = DB::table($table)
                ->join('campuses', 'campuses.id', '=', "{$table}.campus_id")
                ->whereNull('campuses.modality_id')
                ->groupBy('campuses.id', 'campuses.code')
                ->orderBy('campuses.code')
                ->get(['campuses.id', 'campuses.code', DB::raw('count(*) as total')]);

            foreach ($rows as $row) {
                $missing["{$row->code} ({$row->id})"][] = "{$table} ({$row->total})";
            }
        }

        if ($missing !== []) {
            throw new RuntimeException(sprintf(
                'Hay grupos o choques en campus sin modalidad del catálogo (campuses.modality_id nulo) y no se puede llenar modality_id: %s. Liga esos campus a su modalidad antes de migrar.',
                collect($missing)->map(fn (array $tables, string $campus): string => "{$campus} en ".implode(', ', $tables))->implode('; '),
            ));
        }
    }
};
