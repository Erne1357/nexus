<?php

namespace App\Modules\Solver\Console;

use App\Modules\Solver\Contract\SolverInput;
use App\Modules\Solver\Contract\SolverOutput;
use App\Modules\Solver\Engines\EngineRegistry;
use Illuminate\Console\Command;

/**
 * Corre cada motor sobre cada fixture compartido y reporta tiempo, violaciones y puntaje (tarea 6.12;
 * docs/arquitectura/solver.md, «Fixtures compartidos y pruebas» y «Línea base»). La columna «Cumple» compara con
 * `expected.json` solo en los motores que declaran `checksHardRules()`; en los demás va «—».
 *
 *     docker compose exec app php artisan solver:benchmark --engine=fixed --fixture=tiny --markdown
 */
final class SolverBenchmark extends Command
{
    protected $signature = 'solver:benchmark
        {--engine=* : Claves de motor; todos si no se da}
        {--fixture=* : Nombres de fixture; todos si no se da}
        {--markdown : Imprime la tabla en Markdown para la línea base de solver.md}';

    protected $description = 'Mide cada motor sobre los fixtures de tests/Fixtures/solver/v1 (tiempo, violaciones y puntaje)';

    public function handle(EngineRegistry $registry): int
    {
        $available = array_map(fn ($engine): string => $engine->key(), $registry->all());
        $engines = $this->option('engine') ?: $available;

        foreach ($engines as $key) {
            if (! in_array($key, $available, true)) {
                $this->error("No hay un motor «{$key}». Disponibles: ".implode(', ', $available).'.');

                return self::FAILURE;
            }
        }

        $directory = base_path('tests/Fixtures/solver/v1');
        $all = array_map('basename', glob($directory.'/*', GLOB_ONLYDIR) ?: []);
        sort($all);
        $fixtures = $this->option('fixture') ?: $all;

        foreach ($fixtures as $name) {
            if (! in_array($name, $all, true)) {
                $this->error("No hay un fixture «{$name}». Disponibles: ".implode(', ', $all).'.');

                return self::FAILURE;
            }
        }

        $rows = [];

        foreach ($fixtures as $name) {
            $input = SolverInput::fromArray($this->json($directory.'/'.$name.'/input.json'));
            $expected = is_file($directory.'/'.$name.'/expected.json') ? $this->json($directory.'/'.$name.'/expected.json') : null;
            $movable = count(array_filter($input->groups, fn ($group): bool => $group->movable));

            foreach ($engines as $key) {
                $engine = $registry->get($key);
                $started = hrtime(true);
                $output = $engine->solve($input);
                $seconds = (hrtime(true) - $started) / 1e9;

                $complies = '—';

                if ($engine->checksHardRules() && $expected !== null) {
                    $complies = $this->complies($output, $expected) ? '✓' : '✗';
                }

                $rows[] = [
                    'engine' => $key,
                    'fixture' => $name,
                    'movable' => $movable,
                    'sessions' => count($output->sessions),
                    'unscheduled' => count($output->unscheduled),
                    'violations' => count($output->hardViolations),
                    'score' => $output->softScore->total,
                    'status' => $output->status->value,
                    'seconds' => number_format($seconds, 2, '.', ''),
                    'complies' => $complies,
                ];
            }
        }

        if ($this->option('markdown')) {
            $this->line('| Fixture | Motor | Tiempo | Violaciones duras | Puntaje blando | Sin programar |');
            $this->line('|---|---|---|---|---|---|');

            foreach ($rows as $row) {
                $this->line(sprintf('| %s | %s | %s s | %d | %d | %d |', $row['fixture'], $row['engine'], $row['seconds'], $row['violations'], $row['score'], $row['unscheduled']));
            }

            return self::SUCCESS;
        }

        $this->table(
            ['Motor', 'Fixture', 'Grupos', 'Sesiones', 'Sin programar', 'Violaciones duras', 'Puntaje blando', 'Estado', 'Segundos', 'Cumple'],
            array_map(fn (array $row): array => array_values($row), $rows),
        );

        return self::SUCCESS;
    }

    /**
     * Lo que exige `expected.json` a un motor que revisa reglas duras: los grupos de `must_schedule` programados y la
     * factibilidad que dice (factible: sin violaciones ni grupos sin programar; infactible: al menos `min_unscheduled`).
     *
     * @param  array<string, mixed>  $expected
     */
    private function complies(SolverOutput $output, array $expected): bool
    {
        $unscheduled = array_map(fn ($group): string => $group->groupId, $output->unscheduled);

        if (array_intersect($expected['must_schedule'] ?? [], $unscheduled) !== []) {
            return false;
        }

        return match ($expected['feasible'] ?? null) {
            true => $output->hardViolations === [] && $unscheduled === [],
            false => count($unscheduled) >= ($expected['min_unscheduled'] ?? 1),
            default => true,
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function json(string $path): array
    {
        return json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
    }
}
