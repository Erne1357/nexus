<?php

namespace App\Modules\Students\Console;

use App\Modules\Students\Actions\SyncSiiSpecialties;
use App\Modules\Students\Jobs\RecomputeStudentProgress;
use Generator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Comando de una sola vez (1.69): llena `students.specialty_id` de los alumnos ya importados con la especialidad que
 * el SII les asignó (`sii_archivo.alumnos_todos.especialidad`; solo lee esas dos columnas). Una especialidad que no
 * existe en el plan del alumno lo deja sin ella y se informa. Con `--dry-run` solo cuenta. Al terminar de verdad encola el recálculo del avance. Idempotente.
 *
 *     docker compose exec app php artisan students:sync-sii-specialties --dry-run --no-interaction
 */
final class SyncSiiSpecialtiesCommand extends Command
{
    protected $signature = 'students:sync-sii-specialties {--dry-run : Solo cuenta lo que cambiaría}';

    protected $description = 'Llena la especialidad asignada por el SII de los alumnos ya importados';

    public function handle(SyncSiiSpecialties $sync): int
    {
        if (! Schema::hasTable('sii_archivo.alumnos_todos')) {
            $this->error('No hay archivo del SII en esta base (sii_archivo.alumnos_todos): corre antes imports:migrate-sii.');

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $result = $sync->handle($this->assigned(), $dryRun);

        if (! $dryRun && $result['set'] > 0) {
            RecomputeStudentProgress::dispatch();
        }

        $this->line(($dryRun ? 'Simulación: no se escribió nada. ' : '')."Por asignar: {$result['set']}. Sin cambio: {$result['unchanged']}. Números de control que no son de Nexus: {$result['unknownStudents']}.");

        if ($result['unmatched'] !== []) {
            $this->warn(array_sum($result['unmatched']).' alumno(s) con una especialidad que no existe en su plan (quedan como estaban):');
            $this->table(['Plan/Especialidad', 'Alumnos'], array_map(fn (string $label, int $count): array => [$label, $count], array_keys($result['unmatched']), $result['unmatched']));
        }

        return self::SUCCESS;
    }

    /**
     * @return Generator<int, array{control: string, code: string}>
     */
    private function assigned(): Generator
    {
        foreach (DB::table('sii_archivo.alumnos_todos')->whereRaw("btrim(especialidad) <> ''")->orderBy('_linea')->select(['no_de_control', 'especialidad'])->cursor() as $row) {
            yield ['control' => trim($row->no_de_control), 'code' => trim($row->especialidad)];
        }
    }
}
