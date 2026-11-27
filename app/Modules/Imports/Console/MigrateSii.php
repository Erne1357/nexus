<?php

namespace App\Modules\Imports\Console;

use App\Modules\Core\Authorization\Actor;
use App\Modules\Core\Models\User;
use App\Modules\Imports\Enums\ImportsPermission;
use App\Modules\Imports\Sii\RunSiiMigration;
use App\Modules\Imports\Sii\SiiContractBroken;
use App\Modules\Imports\Sii\SiiFailureLog;
use App\Modules\Imports\Sii\SiiMigrationStopped;
use Illuminate\Console\Command;
use InvalidArgumentException;
use Throwable;

/**
 * Migra la exportación del SII a Nexus por el motor de importación. Antes guarda el original en el esquema
 * `sii_archivo` (paso `archivo`, ArchiveSiiExport) y al final lo compara con Nexus (paso `verificacion`,
 * VerifySiiMigration): si algo no cuadra, la corrida termina «con diferencias», sin revertir nada y con salida 0.
 * Con `--dry-run` corre todo y lo revierte, sin verificar.
 * Imprime solo cuentas por lote, nunca valores; el detalle por fila está en `<dir>/_incidencias.csv`. Sube el límite
 * de memoria a 1 GB si era menor, porque el paso `kardex` guarda una llave por cada uno de los dos millones de
 * renglones de la historia; uno mayor o sin límite se respeta.
 */
final class MigrateSii extends Command
{
    private const int MEMORY_LIMIT = 1024 * 1024 * 1024;

    protected $signature = 'imports:migrate-sii
        {dir : Carpeta con la exportación del SII}
        {--dry-run : Corre todo y revierte sin dejar rastro}
        {--only=* : Corre solo los pasos con este nombre}
        {--user= : Usuario (username) con permiso de aplicar importaciones}
        {--force : Permite correr en producción}';

    protected $description = 'Migra la exportación del SII a Nexus con el motor de importación';

    public function handle(RunSiiMigration $migration): int
    {
        $this->raiseMemoryLimit();

        if ($this->laravel->isProduction() && ! $this->option('force')) {
            $this->components->error('En producción hay que pasar --force.');

            return self::FAILURE;
        }

        $dir = (string) $this->argument('dir');

        if (! is_dir($dir)) {
            $this->components->error('La carpeta de la exportación no existe.');

            return self::FAILURE;
        }

        $user = $this->importer();

        if ($user === null) {
            return self::FAILURE;
        }

        $only = array_values((array) $this->option('only'));
        $incidentsFile = RunSiiMigration::incidentsFileName($only);

        try {
            $report = $migration->handle($dir, $user, (bool) $this->option('dry-run'), $only);
        } catch (SiiContractBroken $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        } catch (SiiMigrationStopped $stopped) {
            $this->components->error($stopped->getMessage().' Excepción: '.class_basename($stopped->getPrevious() ?? $stopped).'.');
            $this->components->warn($this->option('dry-run')
                ? 'Era un ensayo: no quedó nada. Detalle en {$incidentsFile} y en el log.'
                : "Los lotes anteriores ya se aplicaron ({$stopped->appliedBatches}); repetir la migración no los cambia. Detalle en {$incidentsFile} y en el log.");

            return self::FAILURE;
        } catch (InvalidArgumentException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        } catch (Throwable $exception) {
            SiiFailureLog::write('error inesperado', $exception);
            $this->components->error('La migración se detuvo por un error inesperado ('.class_basename($exception).'). Detalle en el log.');

            return self::FAILURE;
        }

        $this->table(
            ['Plantilla', 'Archivo', 'Filas', 'Válidas', 'Advertencias', 'Errores', 'Altas', 'Cambios'],
            array_map(fn ($batch): array => [$batch->template, $batch->file, $batch->rows, $batch->valid, $batch->warnings, $batch->errors, $batch->created, $batch->changed], $report->batches),
        );
        if ($report->notes() !== []) {
            $this->components->bulletList($report->notes());
        }
        if ($report->unreadable > 0) {
            $this->components->warn("{$report->unreadable} renglones del SII no se pudieron leer y no se migran (ver «malformados» en {$incidentsFile}).");
        }

        $incidents = "Incidencias: {$report->incidents} (ver {$incidentsFile}).";

        if ($this->option('dry-run')) {
            $this->components->info("Ensayo terminado, sin cambios. {$incidents}");
        } elseif ($report->differences() > 0) {
            $this->components->warn("Migración terminada con diferencias: la verificación contra el SII encontró {$report->differences()} renglones o alumnos que no cuadran (errores del paso verificacion en {$incidentsFile}; detalle en el esquema sii_verificacion). Nada se revirtió. {$incidents}");
        } else {
            $this->components->info("Migración terminada. {$incidents}");
        }

        return self::SUCCESS;
    }

    private function importer(): ?User
    {
        $username = (string) $this->option('user');
        $user = $username === '' ? null : User::query()->where('username', $username)->first();

        if ($user === null) {
            $this->components->error('Indica con --user el username de un usuario que exista.');

            return null;
        }

        if (! app(Actor::class)->forUser($user)->allows(ImportsPermission::BatchApply)) {
            $this->components->error('Ese usuario no tiene permiso para aplicar importaciones.');

            return null;
        }

        return $user;
    }

    /**
     * Sube `memory_limit` a 1 GB si el actual es menor; -1 (sin límite) o uno mayor se quedan.
     */
    private function raiseMemoryLimit(): void
    {
        $current = (string) ini_get('memory_limit');

        if ($current === '-1') {
            return;
        }

        $bytes = (int) $current * match (strtoupper(substr($current, -1))) {
            'G' => 1024 ** 3,
            'M' => 1024 ** 2,
            'K' => 1024,
            default => 1,
        };

        if ($bytes < self::MEMORY_LIMIT) {
            ini_set('memory_limit', (string) self::MEMORY_LIMIT);
        }
    }
}
