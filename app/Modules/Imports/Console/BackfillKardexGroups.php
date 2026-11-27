<?php

namespace App\Modules\Imports\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Comando de una sola vez (MP-04): rellena `kardex_records.sii_group_code` en lo que ya se migró, desde el archivo del
 * SII guardado en `sii_archivo`. Para cada renglón del kárdex sin grupo busca, por periodo, número de control y clave del
 * SII, el grupo de `seleccion_materias` (el inscrito `I` primero) y, si no está ahí, el de `historia_alumno`. Idempotente:
 * solo toca renglones sin grupo, y los que no encuentra se quedan nulos. Con `--dry-run` solo cuenta. No lleva datos en
 * el código: todo sale de la base.
 */
final class BackfillKardexGroups extends Command
{
    protected $signature = 'imports:backfill-kardex-groups {--dry-run : Solo cuenta lo que rellenaría}';

    protected $description = 'Rellena el grupo del SII en el kárdex ya migrado desde sii_archivo (seleccion_materias e historia_alumno)';

    /** @var array<string, string> tabla de sii_archivo => cómo ordenar sus candidatos (el mejor primero) */
    private const array SOURCES = [
        'seleccion_materias' => "(s.status_seleccion = 'I') desc, s._linea desc",
        'historia_alumno' => 's._linea desc',
    ];

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');
        $total = 0;

        foreach (self::SOURCES as $table => $order) {
            if (! $this->exists($table)) {
                $this->warn("sii_archivo.{$table} no existe: se omite.");

                continue;
            }

            $count = $this->fill($table, $order, $dry);
            $total += $count;
            $this->line(($dry ? 'Simulacro: ' : '')."{$count} renglones del kárdex ".($dry ? 'se rellenarían' : 'rellenados')." desde {$table}.");
        }

        $pending = DB::table('kardex_records')->whereNull('sii_group_code')->whereNotNull('sii_subject_code')->whereNotNull('term_code')->whereNull('deleted_at')->count();
        $this->info(($dry ? 'Simulacro, no se escribió nada. ' : '')."Total: {$total}. Siguen sin grupo (con periodo y clave del SII): ".($dry ? $pending - $total : $pending).'.');

        return self::SUCCESS;
    }

    private function exists(string $table): bool
    {
        return DB::table('information_schema.tables')->where('table_schema', 'sii_archivo')->where('table_name', $table)->exists();
    }

    private function fill(string $table, string $order, bool $dry): int
    {
        $pairs = <<<SQL
            select distinct on (k.id) k.id, upper(trim(s.grupo)) as grupo
            from kardex_records k
            join students st on st.id = k.student_id
            join sii_archivo.{$table} s on s.periodo = k.term_code and s.no_de_control = st.control_number and upper(trim(s.materia)) = k.sii_subject_code
            where k.sii_group_code is null and k.deleted_at is null and k.term_code is not null and trim(s.grupo) <> ''
            order by k.id, {$order}
            SQL;

        if ($dry) {
            return (int) DB::selectOne("select count(*) as n from ({$pairs}) x")->n;
        }

        return DB::update("update kardex_records kr set sii_group_code = x.grupo, updated_at = now() from ({$pairs}) x where kr.id = x.id");
    }
}
