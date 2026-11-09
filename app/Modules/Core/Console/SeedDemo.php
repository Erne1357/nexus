<?php

namespace App\Modules\Core\Console;

use App\Modules\Core\Demo\DemoAdmin;
use App\Modules\Core\Demo\DemoContext;
use App\Modules\Core\Demo\DemoReport;
use App\Modules\Core\Demo\DemoRunner;
use App\Modules\Core\Enums\DemoCheckpoint;
use App\Modules\Core\Enums\DemoVolume;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Borra la base de la conexión actual y siembra la demo hasta el inicio de una etapa del proceso, con lo anterior
 * terminado en todos los departamentos (decisión del usuario, 9 de noviembre de 2026); `todo` siembra la demo
 * mezclada de siempre. Imprime cada paso, las revisiones del punto y los usuarios para entrar; sale con error si una
 * revisión falla. Nunca en producción. Detalle en docs/arquitectura/testing.md, «Demo por puntos del proceso».
 *
 *     docker compose exec -e DB_DATABASE=nexus_massive_a app php artisan demo:seed --hasta=asignacion --volumen=masivo --force
 */
final class SeedDemo extends Command
{
    protected $signature = 'demo:seed
        {--hasta=todo : Punto del proceso: catalogos, validacion, propuesta, horarios, asignacion, salones, cierre o todo}
        {--volumen=demo : demo (Campus II en pequeño) o masivo (Campus I y Campus II con volumen)}
        {--semilla=2027 : La misma semilla da los mismos datos (2027, la de DemoSeeder y MassiveSeeder)}
        {--force : Sin pedir confirmación}';

    protected $description = 'Borra la base y siembra la demo hasta el inicio de una etapa del proceso';

    public function handle(DemoRunner $runner): int
    {
        if ($this->laravel->environment('production')) {
            $this->error('Los datos demo no se siembran en producción.');

            return self::FAILURE;
        }

        $target = DemoCheckpoint::tryFrom((string) $this->option('hasta'));
        $volume = DemoVolume::tryFrom((string) $this->option('volumen'));
        $seed = filter_var($this->option('semilla'), FILTER_VALIDATE_INT);

        if ($target === null) {
            $this->error(sprintf('Punto desconocido: %s. Válidos: %s.', $this->option('hasta'), implode(', ', array_column(DemoCheckpoint::cases(), 'value'))));

            return self::INVALID;
        }

        if ($volume === null) {
            $this->error(sprintf('Volumen desconocido: %s. Válidos: %s.', $this->option('volumen'), implode(', ', array_column(DemoVolume::cases(), 'value'))));

            return self::INVALID;
        }

        if ($seed === false) {
            $this->error('La semilla debe ser un número entero.');

            return self::INVALID;
        }

        $database = DB::connection()->getDatabaseName();

        if (! $this->option('force') && ! $this->confirm("Esto borra la base «{$database}» y la vuelve a sembrar. ¿Seguir?")) {
            $this->line('Sin cambios.');

            return self::FAILURE;
        }

        $started = hrtime(true);
        config(['queue.default' => 'sync']);
        $this->call('migrate:fresh', ['--force' => true]);

        $report = null;

        if ($target === DemoCheckpoint::All) {
            $this->call('db:seed', [
                '--class' => $volume === DemoVolume::Massive ? 'Database\Seeders\MassiveSeeder' : 'Database\Seeders\DemoSeeder',
                '--force' => true,
            ]);
            DemoAdmin::useDemoPassword();
        } else {
            $report = $runner->run($target, DemoContext::for($volume, $seed, $this));
            $this->printChecks($report, $target);
        }

        $this->newLine();
        $this->info(sprintf('Listo: %s (%s) en %.1f s, en la base «%s».', $target->label(), $volume->label(), (hrtime(true) - $started) / 1e9, $database));
        $this->printUsers();

        return $report?->failed() ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Las revisiones del punto pedido y las que fallaron en el camino.
     */
    private function printChecks(DemoReport $report, DemoCheckpoint $target): void
    {
        $checks = array_values(array_filter($report->checks, fn (array $check): bool => $check['checkpoint'] === $target || ! $check['passed']));

        if ($checks === []) {
            return;
        }

        $this->newLine();
        $this->table(['', 'Punto', 'Revisión', 'Motivo'], array_map(fn (array $check): array => [
            $check['passed'] ? '✓' : '✗',
            $check['checkpoint']->number().' '.$check['checkpoint']->value,
            $check['label'],
            $check['reason'] ?? '',
        ], $checks));
    }

    private function printUsers(): void
    {
        $this->line('Usuarios (contraseña «'.DemoAdmin::PASSWORD.'»): admin (todo el Tec), coord.isc.c2 (coordinador de ISC en Campus II), jefe.sc (jefe de Sistemas y Computación).');
        $this->line('Docentes: su número de empleado, con la contraseña de NEXUS_DEFAULT_PASSWORD (piden cambiarla).');
    }
}
