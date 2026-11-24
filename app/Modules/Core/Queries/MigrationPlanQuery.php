<?php

namespace App\Modules\Core\Queries;

use App\Modules\Core\Database\Contract;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Support\Facades\DB;
use ReflectionMethod;
use ReflectionObject;

/**
 * Compara las migraciones que conoce el Migrator (las carpetas de todos los módulos) con la tabla `migrations`: cuáles
 * faltan por correr, cuáles están en la base y el código ya no las tiene, cuáles se repiten entre carpetas y cuáles de
 * las pendientes son contracciones. La usan `/health` y `nexus:migration-plan`.
 */
final class MigrationPlanQuery
{
    public function __construct(private readonly Migrator $migrator) {}

    public function run(): MigrationPlan
    {
        /** @var array<string, string> $files nombre => ruta (con repetidos, la primera) */
        $files = [];
        $seen = [];

        foreach ([...$this->migrator->paths(), database_path('migrations')] as $directory) {
            foreach (glob(rtrim($directory, '/').'/*.php') ?: [] as $path) {
                $name = basename($path, '.php');
                $seen[$name] = ($seen[$name] ?? 0) + 1;
                $files[$name] ??= $path;
            }
        }

        $ran = DB::connection(config('health.database_connection'))
            ->table((string) config('database.migrations.table', 'migrations'))
            ->pluck('migration')
            ->all();

        $pending = array_values(array_diff(array_keys($files), $ran));
        $unknown = array_values(array_diff($ran, array_keys($files)));
        sort($pending);
        sort($unknown);

        return new MigrationPlan(
            pending: $pending,
            unknown: $unknown,
            duplicates: array_keys(array_filter($seen, fn (int $count): bool => $count > 1)),
            pendingContracts: array_values(array_filter($pending, fn (string $name): bool => $this->isContract($files[$name]))),
        );
    }

    private function isContract(string $path): bool
    {
        // `resolvePath` es protegido: es la misma resolución que usa `migrate` (con su caché de archivos ya cargados).
        $migration = (new ReflectionMethod($this->migrator, 'resolvePath'))->invoke($this->migrator, $path);

        return (new ReflectionObject($migration))->getAttributes(Contract::class) !== [];
    }
}
