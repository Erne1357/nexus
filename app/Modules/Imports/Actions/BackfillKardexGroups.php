<?php

namespace App\Modules\Imports\Actions;

use Illuminate\Support\Facades\DB;

/**
 * Rellena `kardex_records.sii_group_code` en lo ya migrado, desde el archivo del SII guardado en `sii_archivo` (MP-04).
 * Para cada renglón del kárdex sin grupo busca, por periodo, número de control y clave del SII, el grupo de
 * `seleccion_materias` (el inscrito `I` primero) y, solo para los que esa no cubre, el de `historia_alumno`; así un
 * renglón se cuenta una vez. Cada fuente y los candidatos del kárdex se normalizan una vez en tablas temporales con
 * índice (las de `sii_archivo` no tienen), y el trabajo corre por periodo, con una transacción corta por periodo para no
 * sostener un candado largo sobre `kardex_records`. Deja UNA entrada de bitácora al final con los conteos por fuente y
 * periodo. Con `$dryRun` calcula los mismos conteos y no escribe ni deja bitácora. Idempotente: solo toca renglones sin
 * grupo. `$term` limita la corrida a un periodo.
 */
final class BackfillKardexGroups
{
    /** @var array<string, string> tabla de sii_archivo => cómo ordenar sus candidatos (el mejor primero) */
    private const array SOURCES = [
        'seleccion_materias' => "(s.status_seleccion = 'I') desc, s._linea desc",
        'historia_alumno' => 's._linea desc',
    ];

    /**
     * `filled`: fuente => periodo => renglones; `missing`: tablas de sii_archivo que no existen; `pending`: renglones con
     * periodo y clave del SII que siguen sin grupo.
     *
     * @return array{filled: array<string, array<string, int>>, total: int, missing: list<string>, pending: int}
     */
    public function handle(bool $dryRun = false, ?string $term = null): array
    {
        $this->dropTemporary();
        $missing = [];
        $available = [];

        try {
            DB::statement('create temporary table kg_cand as select k.id, k.term_code as term, st.control_number as ctl, k.sii_subject_code as subj from kardex_records k join students st on st.id = k.student_id where k.sii_group_code is null and k.deleted_at is null and k.term_code is not null and k.sii_subject_code is not null'.($term !== null ? ' and k.term_code = ?' : ''), $term !== null ? [$term] : []);
            DB::statement('create index on kg_cand (term, ctl, subj)');
            DB::statement('analyze kg_cand');

            foreach (self::SOURCES as $table => $order) {
                if (! $this->exists($table)) {
                    $missing[] = $table;

                    continue;
                }

                $available[] = $table;
                $name = "kg_src_{$table}";
                DB::statement("create temporary table {$name} as select distinct on (s.periodo, s.no_de_control, upper(trim(s.materia))) s.periodo as term, s.no_de_control as ctl, upper(trim(s.materia)) as subj, upper(trim(s.grupo)) as grupo from sii_archivo.{$table} s where trim(s.grupo) <> ''".($term !== null ? ' and s.periodo = ?' : '').' order by s.periodo, s.no_de_control, upper(trim(s.materia)), '.$order, $term !== null ? [$term] : []);
                DB::statement("create index on {$name} (term, ctl, subj)");
                DB::statement("analyze {$name}");
            }

            DB::statement('create temporary table kg_fill (id uuid primary key, grupo text not null, source text not null)');
            $filled = [];

            foreach (DB::select('select distinct term from kg_cand order by term') as $row) {
                DB::transaction(function () use ($row, $available, $dryRun, &$filled): void {
                    DB::statement('truncate kg_fill');

                    foreach ($available as $table) {
                        DB::insert("insert into kg_fill (id, grupo, source) select c.id, s.grupo, ? from kg_cand c join kg_src_{$table} s on s.term = c.term and s.ctl = c.ctl and s.subj = c.subj where c.term = ? and not exists (select 1 from kg_fill f where f.id = c.id)", [$table, $row->term]);
                    }

                    foreach (DB::select('select source, count(*) as n from kg_fill group by source') as $count) {
                        $filled[$count->source][$row->term] = (int) $count->n;
                    }

                    if (! $dryRun) {
                        DB::update('update kardex_records kr set sii_group_code = f.grupo, updated_at = now() from kg_fill f where kr.id = f.id and kr.sii_group_code is null');
                    }
                });
            }

            ksort($filled);
            foreach ($filled as &$terms) {
                ksort($terms);
            }
            unset($terms);

            $total = array_sum(array_map('array_sum', $filled));

            if ($total > 0 && ! $dryRun) {
                activity()
                    ->event('backfilled')
                    ->withProperties(['filled' => $filled, 'total' => $total])
                    ->log("Se rellenó el grupo del SII en {$total} renglones del kárdex.");
            }

            $pending = (int) DB::scalar('select count(*) from kg_cand') - ($dryRun ? $total : 0);
            if (! $dryRun) {
                $pending = DB::table('kardex_records')->whereNull('sii_group_code')->whereNotNull('sii_subject_code')->whereNotNull('term_code')->whereNull('deleted_at')->when($term !== null, fn ($q) => $q->where('term_code', $term))->count();
            }
        } finally {
            $this->dropTemporary();
        }

        return ['filled' => $filled, 'total' => $total, 'missing' => $missing, 'pending' => $pending];
    }

    private function dropTemporary(): void
    {
        foreach (['kg_fill', 'kg_cand', ...array_map(fn (string $t): string => "kg_src_{$t}", array_keys(self::SOURCES))] as $table) {
            DB::statement("drop table if exists pg_temp.{$table}");
        }
    }

    private function exists(string $table): bool
    {
        return DB::table('information_schema.tables')->where('table_schema', 'sii_archivo')->where('table_name', $table)->exists();
    }
}
