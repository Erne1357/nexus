<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * La apertura del periodo y el calendario de etapas pasan de campus a modalidad (M4, confirmado el 12 de noviembre de
 * 2026: Presencial se abre una vez para C1 y C2, con un solo calendario). La modalidad de cada campus sale del puente
 * `campuses.modality_id` (ADR-0025; C1 y C2 → `PRES`).
 *
 * up(), todo en una transacción (si se detiene, no deja nada):
 * 1. Revisión previa: si una apertura o una ventana es de un campus sin modalidad del catálogo, si dos campus de la
 *    misma modalidad tienen la misma etapa del mismo periodo con fechas distintas, o si un campus tiene ventana propia
 *    en una etapa y otro campus sin borrar de la misma modalidad usa la general (sin ventana propia, su fecha es la
 *    general o «Sin fecha»: al juntarlas tomaría en silencio las fechas del primero), se detiene con un RuntimeException
 *    que lista los campus o los ids de las ventanas.
 * 2. `term_openings` recibe `modality_id` (FK obligatoria a `modalities`). Las aperturas de los campus de una misma
 *    modalidad se funden en una: se queda la más antigua (`opened_at` y, a igual hora, el id), con su quién y cuándo, y
 *    las demás se eliminan, cada una con una entrada en la bitácora que guarda lo que tenía. Única por (`term_id`,
 *    `modality_id`).
 * 3. `term_stages` recibe `modality_id` (FK opcional; nula es la general, para todas las modalidades). Las ventanas
 *    propias de un campus pasan a su modalidad; las de dos campus con las mismas fechas se funden en una (la de menor
 *    id), también con su entrada en la bitácora. Única por (`term_id`, `modality_id`, `stage`) con nulos no distintos.
 * 4. Quita `campus_id` de las dos tablas, con sus índices y llaves.
 *
 * down() regresa la forma anterior: cada apertura y cada ventana de una modalidad vuelve a cada campus sin borrar de esa
 * modalidad (la general sigue sin campus). Si una no tiene ningún campus, se detiene y la lista.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function (): void {
            $this->ensureCampusesHaveModality();
            $this->ensureNoConflictingStages();

            Schema::table('term_openings', function (Blueprint $table) {
                $table->foreignUuid('modality_id')->nullable()->constrained('modalities');
            });

            Schema::table('term_stages', function (Blueprint $table) {
                $table->foreignUuid('modality_id')->nullable()->constrained('modalities');
            });

            DB::statement('update term_openings set modality_id = campuses.modality_id from campuses where campuses.id = term_openings.campus_id');
            DB::statement('update term_stages set modality_id = campuses.modality_id from campuses where campuses.id = term_stages.campus_id');

            $this->mergeOpenings();
            $this->mergeStages();

            DB::statement('alter table term_openings alter column modality_id set not null');

            Schema::table('term_openings', function (Blueprint $table) {
                $table->dropUnique(['term_id', 'campus_id']);
                $table->dropIndex(['campus_id']);
                $table->dropConstrainedForeignId('campus_id');

                $table->unique(['term_id', 'modality_id']);
                $table->index('modality_id');
            });

            Schema::table('term_stages', function (Blueprint $table) {
                $table->dropUnique(['term_id', 'campus_id', 'stage']);
                $table->dropIndex(['campus_id']);
                $table->dropConstrainedForeignId('campus_id');

                $table->unique(['term_id', 'modality_id', 'stage'])->nullsNotDistinct();
                $table->index('modality_id');
            });
        });
    }

    public function down(): void
    {
        DB::transaction(function (): void {
            $this->ensureModalitiesHaveCampuses();

            Schema::table('term_openings', function (Blueprint $table) {
                $table->dropUnique(['term_id', 'modality_id']);
                $table->foreignUuid('campus_id')->nullable()->constrained();
            });

            Schema::table('term_stages', function (Blueprint $table) {
                $table->dropUnique(['term_id', 'modality_id', 'stage']);
                $table->foreignUuid('campus_id')->nullable()->constrained();
            });

            DB::statement(<<<'SQL'
                insert into term_openings (term_id, modality_id, campus_id, opened_by, opened_at, created_at, updated_at)
                select term_openings.term_id, term_openings.modality_id, campuses.id, term_openings.opened_by, term_openings.opened_at,
                    term_openings.created_at, term_openings.updated_at
                from term_openings
                join campuses on campuses.modality_id = term_openings.modality_id and campuses.deleted_at is null
                where term_openings.campus_id is null
                SQL);
            DB::table('term_openings')->whereNull('campus_id')->delete();

            DB::statement(<<<'SQL'
                insert into term_stages (term_id, modality_id, campus_id, stage, starts_on, ends_on, created_at, updated_at)
                select term_stages.term_id, term_stages.modality_id, campuses.id, term_stages.stage, term_stages.starts_on,
                    term_stages.ends_on, term_stages.created_at, term_stages.updated_at
                from term_stages
                join campuses on campuses.modality_id = term_stages.modality_id and campuses.deleted_at is null
                where term_stages.modality_id is not null and term_stages.campus_id is null
                SQL);
            DB::table('term_stages')->whereNotNull('modality_id')->whereNull('campus_id')->delete();

            DB::statement('alter table term_openings alter column campus_id set not null');

            Schema::table('term_openings', function (Blueprint $table) {
                $table->dropIndex(['modality_id']);
                $table->dropConstrainedForeignId('modality_id');

                $table->unique(['term_id', 'campus_id']);
                $table->index('campus_id');
            });

            Schema::table('term_stages', function (Blueprint $table) {
                $table->dropIndex(['modality_id']);
                $table->dropConstrainedForeignId('modality_id');

                $table->unique(['term_id', 'campus_id', 'stage'])->nullsNotDistinct();
                $table->index('campus_id');
            });
        });
    }

    /**
     * @throws RuntimeException si una apertura o ventana es de un campus sin modalidad del catálogo
     */
    private function ensureCampusesHaveModality(): void
    {
        $missing = [];

        foreach (['term_openings', 'term_stages'] as $table) {
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
                'Hay aperturas o ventanas del calendario en campus sin modalidad del catálogo (campuses.modality_id nulo) y no se puede saber su modalidad: %s. Liga esos campus a su modalidad antes de migrar.',
                collect($missing)->map(fn (array $tables, string $campus): string => "{$campus} en ".implode(', ', $tables))->implode('; '),
            ));
        }
    }

    /**
     * Dos campus de la misma modalidad con la misma etapa del mismo periodo y fechas distintas no caben en un solo
     * calendario (M4): alguien tiene que decidir cuál queda. Tampoco una etapa con ventana propia en un campus y sin ella
     * en otro campus sin borrar de la misma modalidad, que usa la general: heredar la del primero cambiaría sus fechas
     * en silencio.
     *
     * @throws RuntimeException con los ids de las ventanas en conflicto y los campus que usan la general
     */
    private function ensureNoConflictingStages(): void
    {
        $conflicts = DB::select(<<<'SQL'
            select terms.code as term_code, modalities.code as modality_code, term_stages.stage,
                string_agg(term_stages.id::text || ' (' || campuses.code || ': ' || term_stages.starts_on || ' a ' || term_stages.ends_on || ')', ', ' order by campuses.code, term_stages.id) as windows
            from term_stages
            join campuses on campuses.id = term_stages.campus_id
            join modalities on modalities.id = campuses.modality_id
            join terms on terms.id = term_stages.term_id
            group by terms.code, modalities.code, term_stages.stage
            having count(distinct (term_stages.starts_on, term_stages.ends_on)) > 1
            order by terms.code, modalities.code, term_stages.stage
            SQL);

        if ($conflicts !== []) {
            throw new RuntimeException(sprintf(
                'Hay ventanas propias de campus de la misma modalidad con la misma etapa y fechas distintas, y el calendario es uno por modalidad (M4): %s. Deja una sola fecha por etapa (o quita las propias) antes de migrar.',
                collect($conflicts)->map(fn (object $row): string => "{$row->term_code} {$row->modality_code} {$row->stage}: {$row->windows}")->implode('; '),
            ));
        }

        $inheriting = DB::select(<<<'SQL'
            select terms.code as term_code, modalities.code as modality_code, own.stage,
                string_agg(distinct own.id::text || ' (' || owner.code || ': ' || own.starts_on || ' a ' || own.ends_on || ')', ', ') as windows,
                string_agg(distinct others.code, ', ') as general_campuses
            from term_stages as own
            join campuses as owner on owner.id = own.campus_id
            join campuses as others on others.modality_id = owner.modality_id and others.deleted_at is null
                and not exists (
                    select 1 from term_stages as theirs
                    where theirs.term_id = own.term_id and theirs.stage = own.stage and theirs.campus_id = others.id
                )
            join modalities on modalities.id = owner.modality_id
            join terms on terms.id = own.term_id
            group by terms.code, modalities.code, own.stage
            order by terms.code, modalities.code, own.stage
            SQL);

        if ($inheriting !== []) {
            throw new RuntimeException(sprintf(
                'Hay etapas con ventana propia en un campus y la general en otro campus de la misma modalidad, y el calendario es uno por modalidad (M4): al juntarlas, el segundo tomaría las fechas del primero sin que nadie lo decida: %s. Da la misma ventana propia a todos los campus de la modalidad (o quita las propias) antes de migrar.',
                collect($inheriting)->map(fn (object $row): string => "{$row->term_code} {$row->modality_code} {$row->stage}: {$row->windows}; con la general: {$row->general_campuses}.")->implode(' '),
            ));
        }
    }

    /**
     * Deja una apertura por periodo y modalidad: la más antigua. Las demás se eliminan, cada una con su entrada en la
     * bitácora.
     */
    private function mergeOpenings(): void
    {
        $merged = DB::select(<<<'SQL'
            select ranked.*, terms.code as term_code, modalities.code as modality_code, campuses.code as campus_code
            from (
                select term_openings.*,
                    row_number() over merged as position,
                    first_value(term_openings.id) over merged as kept_id
                from term_openings
                window merged as (partition by term_id, modality_id order by opened_at asc, id asc)
            ) as ranked
            join terms on terms.id = ranked.term_id
            join modalities on modalities.id = ranked.modality_id
            join campuses on campuses.id = ranked.campus_id
            where ranked.position > 1
            SQL);

        foreach ($merged as $row) {
            $this->log('App\\Modules\\Core\\Models\\TermOpening', $row, "Juntó la apertura del periodo {$row->term_code} en {$row->campus_code} con la de {$row->modality_code}, porque el periodo se abre por modalidad", [
                'term_id' => $row->term_id,
                'campus_id' => $row->campus_id,
                'opened_by' => $row->opened_by,
                'opened_at' => $row->opened_at,
            ]);
        }

        DB::table('term_openings')->whereIn('id', array_column($merged, 'id'))->delete();
    }

    /**
     * Deja una ventana por periodo, modalidad y etapa: las que repiten fechas (ensureNoConflictingStages ya descartó
     * las distintas) se eliminan, cada una con su entrada en la bitácora.
     */
    private function mergeStages(): void
    {
        $merged = DB::select(<<<'SQL'
            select ranked.*, terms.code as term_code, modalities.code as modality_code, campuses.code as campus_code
            from (
                select term_stages.*,
                    row_number() over merged as position,
                    first_value(term_stages.id) over merged as kept_id
                from term_stages
                where modality_id is not null
                window merged as (partition by term_id, modality_id, stage order by id asc)
            ) as ranked
            join terms on terms.id = ranked.term_id
            join modalities on modalities.id = ranked.modality_id
            join campuses on campuses.id = ranked.campus_id
            where ranked.position > 1
            SQL);

        foreach ($merged as $row) {
            $this->log('App\\Modules\\Core\\Models\\TermStage', $row, "Juntó la ventana {$row->stage} de {$row->campus_code} del periodo {$row->term_code} con la de {$row->modality_code}, porque el calendario es por modalidad", [
                'term_id' => $row->term_id,
                'campus_id' => $row->campus_id,
                'stage' => $row->stage,
                'starts_on' => $row->starts_on,
                'ends_on' => $row->ends_on,
            ]);
        }

        DB::table('term_stages')->whereIn('id', array_column($merged, 'id'))->delete();
    }

    /**
     * @throws RuntimeException si una apertura o ventana de modalidad no tiene ningún campus al cual volver
     */
    private function ensureModalitiesHaveCampuses(): void
    {
        $orphans = collect(['term_openings', 'term_stages'])
            ->flatMap(fn (string $table) => DB::table($table)
                ->join('modalities', 'modalities.id', '=', "{$table}.modality_id")
                ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('campuses')
                    ->whereColumn('campuses.modality_id', "{$table}.modality_id")
                    ->whereNull('campuses.deleted_at'))
                ->orderBy("{$table}.id")
                ->get(["{$table}.id", 'modalities.code'])
                ->map(fn (object $row): string => "{$table} {$row->id} ({$row->code})"));

        if ($orphans->isNotEmpty()) {
            throw new RuntimeException('Hay aperturas o ventanas de modalidades sin ningún campus al cual regresarlas: '.$orphans->implode(', ').'.');
        }
    }

    /**
     * La entrada en la bitácora de una fila que se elimina al juntarla con la que se queda, con la forma de las de
     * spatie/activitylog (sin causante: la escribe la migración); `$old` es lo que tenía.
     *
     * @param  array<string, mixed>  $old
     */
    private function log(string $subjectType, object $row, string $description, array $old): void
    {
        DB::table('activity_log')->insert([
            'log_name' => config('activitylog.default_log_name', 'default'),
            'description' => $description,
            'subject_type' => $subjectType,
            'subject_id' => $row->id,
            'event' => 'deleted',
            'attribute_changes' => json_encode(['old' => $old]),
            'properties' => json_encode(['modality_id' => $row->modality_id, 'kept_id' => $row->kept_id]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
};
