<?php

namespace App\Modules\Solver\Actions;

use App\Modules\Solver\Enums\SolverRunStatus;
use App\Modules\Solver\Models\SolverRun;
use App\Modules\Solver\Support\RunPayloads;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Limpia los archivos de entrada y salida de las corridas (tarea 6.8, revisión del Riel A en #587; RunPayloads):
 *
 * - los de las corridas fallidas, canceladas y descartadas que terminaron hace más de `$days` días: borra su carpeta
 *   `solver/runs/<id>` y quita la ruta de la fila (la pantalla ya no muestra su detalle);
 * - las carpetas de `solver/runs` sin corrida (de una que no se llegó a guardar) cuyos archivos no cambian desde hace
 *   más de un día.
 *
 * Nunca toca las corridas aplicadas (lo que se escribió en el horario queda reproducible), las terminadas, que todavía
 * se pueden aceptar, ni las que están en curso. No deja bitácora: no cambia nada de la corrida salvo dónde estaban sus
 * archivos. Lo corre `solver:prune-files` a mano (supuesto por confirmar: programarlo).
 */
final class PruneSolverRunFiles
{
    private const string FOLDER = 'solver/runs';

    /**
     * @return array{runs: int, orphans: int} cuántas corridas perdieron sus archivos y cuántas carpetas sin corrida se borraron
     */
    public function handle(int $days): array
    {
        $disk = Storage::disk(RunPayloads::DISK);
        $runs = 0;

        SolverRun::query()
            ->whereIn('status', [SolverRunStatus::Failed, SolverRunStatus::Cancelled, SolverRunStatus::Discarded])
            ->where('finished_at', '<', now()->subDays($days))
            ->where(fn ($query) => $query->whereNotNull('input_path')->orWhereNotNull('output_path'))
            ->pluck('id')
            ->each(function (string $runId) use ($disk, &$runs): void {
                $disk->deleteDirectory(self::FOLDER."/{$runId}");
                SolverRun::query()->whereKey($runId)->update(['input_path' => null, 'output_path' => null]);
                $runs++;
            });

        return ['runs' => $runs, 'orphans' => $this->pruneOrphans()];
    }

    private function pruneOrphans(): int
    {
        $disk = Storage::disk(RunPayloads::DISK);
        $limit = now()->subDay()->getTimestamp();
        $orphans = 0;

        foreach ($disk->directories(self::FOLDER) as $folder) {
            $runId = basename($folder);

            if (Str::isUuid($runId) && SolverRun::query()->whereKey($runId)->exists()) {
                continue;
            }

            $files = $disk->allFiles($folder);

            if (array_filter($files, fn (string $file): bool => $disk->lastModified($file) >= $limit) === []) {
                $disk->deleteDirectory($folder);
                $orphans++;
            }
        }

        return $orphans;
    }
}
