<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Ampliar (ronda 2 de la carga por modalidad, hacia M1 y ADR-0025; el primer paso de ampliar → cambiar → contraer):
 *
 * - `academic_loads`, `career_proposals` e `interdepartmental_agreements` reciben `modality_id` (FK obligatoria a
 *   `modalities`), llena con la modalidad del catálogo de su campus (el puente `campuses.modality_id`: C1 y C2 → `PRES`),
 *   con un índice igual a cada uno de los de campus, cambiando `campus_id` por `modality_id`;
 * - `proposal_items` recibe `campus_id` (FK opcional a `campuses`), el campus de su propuesta; al contraer solo queda
 *   nulo en una modalidad sin salones.
 *
 * Ningún lector cambia todavía, y las llaves únicas y los `campus_id` siguen como están. Si una fila es de un campus
 * sin modalidad del catálogo (un campus guardado sin sus Actions, su sembrador ni su factory), se detiene con un
 * RuntimeException que nombra cada campus (clave e id) y las tablas donde tiene filas. Todo up() va en una transacción:
 * si se detiene, no deja nada.
 *
 * down() quita las columnas, y con ellas sus índices y llaves.
 */
return new class extends Migration
{
    /**
     * Cada tabla que recibe la modalidad de su campus, con los índices que repiten los de campus.
     *
     * @var array<string, array<string, list<string>>>
     */
    private const array TABLES = [
        'academic_loads' => [
            'academic_loads_term_modality_department_index' => ['term_id', 'modality_id', 'department_id'],
            'academic_loads_modality_id_index' => ['modality_id'],
        ],
        'career_proposals' => [
            'career_proposals_term_modality_career_index' => ['term_id', 'modality_id', 'career_id'],
            'career_proposals_modality_id_index' => ['modality_id'],
        ],
        'interdepartmental_agreements' => [
            'interdepartmental_agreements_term_modality_to_status_index' => ['term_id', 'modality_id', 'to_department_id', 'status'],
            'interdepartmental_agreements_term_modality_from_status_index' => ['term_id', 'modality_id', 'from_department_id', 'status'],
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

            Schema::table('proposal_items', function (Blueprint $table) {
                $table->foreignUuid('campus_id')->nullable()->constrained();
            });

            DB::statement('update proposal_items set campus_id = career_proposals.campus_id from career_proposals where career_proposals.id = proposal_items.career_proposal_id');
        });
    }

    public function down(): void
    {
        DB::transaction(function (): void {
            Schema::table('proposal_items', function (Blueprint $table) {
                $table->dropConstrainedForeignId('campus_id');
            });

            foreach (array_keys(self::TABLES) as $table) {
                Schema::table($table, function (Blueprint $blueprint) {
                    $blueprint->dropConstrainedForeignId('modality_id');
                });
            }
        });
    }

    /**
     * @throws RuntimeException si alguna fila es de un campus sin modalidad del catálogo
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
                'Hay filas de la carga en campus sin modalidad del catálogo (campuses.modality_id nulo) y no se puede llenar modality_id: %s. Liga esos campus a su modalidad antes de migrar.',
                collect($missing)->map(fn (array $tables, string $campus): string => "{$campus} en ".implode(', ', $tables))->implode('; '),
            ));
        }
    }
};
