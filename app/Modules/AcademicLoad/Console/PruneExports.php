<?php

namespace App\Modules\AcademicLoad\Console;

use App\Modules\AcademicLoad\Exports\PublishedExports;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Borra del disco `exports` los archivos que ya nadie va a pedir (tarea 4.5): los de llaves viejas, que una versión
 * publicada nueva dejó sin uso, y los temporales `.part` de un armado que se cortó. Un exporte borrado que alguien
 * vuelva a pedir se arma otra vez desde su snapshot, así que borrar de más solo cuesta tiempo de cola. Corre a diario
 * (routes/console.php).
 */
final class PruneExports extends Command
{
    protected $signature = 'academic-load:prune-exports {--days=30 : Borra los archivos que no cambian desde hace más de estos días}';

    protected $description = 'Borra los exportes de lo publicado viejos y los temporales que quedaron a medias';

    public function handle(): int
    {
        $disk = Storage::disk(PublishedExports::DISK);
        $files = $disk->exists('published') ? $disk->allFiles('published') : [];
        $oldFiles = now()->subDays(max(1, (int) $this->option('days')))->getTimestamp();
        $oldParts = now()->subHour()->getTimestamp();
        $deleted = 0;

        foreach ($files as $file) {
            $limit = str_ends_with($file, '.part') ? $oldParts : $oldFiles;

            if ($disk->lastModified($file) < $limit) {
                $disk->delete($file);
                $deleted++;
            }
        }

        $this->info("Se borraron {$deleted} archivos de exportes.");

        return self::SUCCESS;
    }
}
