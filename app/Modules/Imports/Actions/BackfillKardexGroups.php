<?php

namespace App\Modules\Imports\Actions;

use Illuminate\Support\Facades\DB;

/**
 * Rellena `kardex_records.sii_group_code` en lo ya migrado, desde el archivo del SII guardado en `sii_archivo` (MP-04).
 * Para cada renglón del kárdex sin grupo busca, por periodo, número de control y clave del SII, el grupo de
 * `seleccion_materias` (el inscrito `I` primero) y, solo para los que esa no cubre, el de `historia_alumno`; así un
 * renglón se cuenta una vez. Todo es set-based y corre en una sola transacción; deja UNA entrada de bitácora con los
 * conteos por fuente y periodo. Con `$dryRun` calcula los mismos conteos y revierte, sin escribir ni dejar bitácora.
 * Idempotente: solo toca renglones sin grupo.
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
    public function handle(bool $dryRun = false): array
    {
        return DB::transaction(function () use ($dryRun): array {
            DB::statement('drop table if exists kardex_group_fill');
            DB::statement('create temporary table kardex_group_fill (id uuid primary key, grupo text not null, source text not null, term text not null)');
            $missing = [];

            foreach (self::SOURCES as $table => $order) {
                if (! $this->exists($table)) {
                    $missing[] = $table;

                    continue;
                }

                DB::insert(<<<SQL
                    insert into kardex_group_fill (id, grupo, source, term)
                    select distinct on (k.id) k.id, upper(trim(s.grupo)), ?, k.term_code
                    from kardex_records k
                    join students st on st.id = k.student_id
                    join sii_archivo.{$table} s on s.periodo = k.term_code and s.no_de_control = st.control_number and upper(trim(s.materia)) = k.sii_subject_code
                    where k.sii_group_code is null and k.deleted_at is null and k.term_code is not null and trim(s.grupo) <> ''
                      and k.id not in (select id from kardex_group_fill)
                    order by k.id, {$order}
                    SQL, [$table]);
            }

            $filled = [];

            foreach (DB::select('select source, term, count(*) as n from kardex_group_fill group by source, term order by source, term') as $row) {
                $filled[$row->source][$row->term] = (int) $row->n;
            }

            $total = array_sum(array_map('array_sum', $filled));

            if ($total > 0 && ! $dryRun) {
                DB::update('update kardex_records kr set sii_group_code = f.grupo, updated_at = now() from kardex_group_fill f where kr.id = f.id');
                activity()
                    ->event('backfilled')
                    ->withProperties(['filled' => $filled, 'total' => $total])
                    ->log("Se rellenó el grupo del SII en {$total} renglones del kárdex.");
            }

            DB::statement('drop table kardex_group_fill');
            $pending = DB::table('kardex_records')->whereNull('sii_group_code')->whereNotNull('sii_subject_code')->whereNotNull('term_code')->whereNull('deleted_at')->count();

            return ['filled' => $filled, 'total' => $total, 'missing' => $missing, 'pending' => $dryRun ? $pending - $total : $pending];
        });
    }

    private function exists(string $table): bool
    {
        return DB::table('information_schema.tables')->where('table_schema', 'sii_archivo')->where('table_name', $table)->exists();
    }
}
