<?php

namespace App\Modules\Solver\Console;

use App\Modules\Solver\Contract\SoftWeights;
use App\Modules\Solver\Contract\SolverInput;
use App\Modules\Solver\Contract\SolverOutput;
use App\Modules\Solver\Engines\EngineRegistry;
use App\Modules\Solver\Evaluation\ContractEvaluator;
use App\Modules\Solver\Rules\SolverSettings;
use Illuminate\Console\Command;

/**
 * Corre cada motor sobre cada fixture compartido y reporta tiempo, violaciones y puntaje (tarea 6.12;
 * docs/arquitectura/solver.md, «Fixtures compartidos y pruebas» y «Línea base»). La columna «Cumple» compara con
 * `expected.json` solo en los motores que declaran `checksHardRules()`; en los demás va «—».
 *
 * Para el benchmark de la 7.11: `--time-limit` corre todas las entradas con el mismo límite, `--input` agrega entradas
 * fuera de los fixtures (una carga real exportada a storage/app con `solver:export-fixture --path`; si no se da
 * `--fixture`, solo esas) y `--breakdown` agrega el límite, el estado, las violaciones duras que encuentra el evaluador
 * en PHP sobre las sesiones de la salida (la misma vara para los dos motores) y el puntaje de SC-01 a SC-09.
 *
 *     docker compose exec app php artisan solver:benchmark --engine=fixed --fixture=tiny --markdown
 *     docker compose exec app php artisan solver:benchmark --engine=heuristic --engine=cpsat --time-limit=60 --breakdown --markdown
 */
final class SolverBenchmark extends Command
{
    protected $signature = 'solver:benchmark
        {--engine=* : Claves de motor; todos si no se da}
        {--fixture=* : Nombres de fixture; todos si no se da (ninguno si se da --input)}
        {--input=* : Archivos input.json fuera de los fixtures; su nombre es el de su carpeta}
        {--time-limit= : Segundos de cada corrida, de 10 a 600; si no se da, los de cada entrada}
        {--breakdown : Agrega el límite, el estado, las duras según el evaluador en PHP y el puntaje de SC-01 a SC-09}
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

        $limit = $this->option('time-limit');

        if ($limit !== null && (filter_var($limit, FILTER_VALIDATE_INT) === false || (int) $limit < SolverSettings::MIN_SECONDS || (int) $limit > SolverSettings::MAX_SECONDS)) {
            $this->error('El límite de --time-limit va de '.SolverSettings::MIN_SECONDS.' a '.SolverSettings::MAX_SECONDS.' segundos.');

            return self::FAILURE;
        }

        $instances = $this->instances();

        if ($instances === null) {
            return self::FAILURE;
        }

        $breakdown = (bool) $this->option('breakdown');
        $rows = [];

        foreach ($instances as $name => [$path, $expectedPath]) {
            $data = $this->json($path);

            if ($limit !== null) {
                $data['time_limit_seconds'] = (int) $limit;
            }

            $input = SolverInput::fromArray($data);
            $expected = $expectedPath !== null && is_file($expectedPath) ? $this->json($expectedPath) : null;
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
                    ...($breakdown ? [
                        'limit' => $input->timeLimitSeconds,
                        'evaluated' => count((new ContractEvaluator)->evaluate($input, $output->sessions)->hardViolations),
                        'by_rule' => array_map(fn (string $rule): int => (int) ($output->softScore->byRule[$rule] ?? 0), SoftWeights::RULES),
                    ] : []),
                ];
            }
        }

        if ($this->option('markdown')) {
            $breakdown ? $this->breakdownMarkdown($rows) : $this->baselineMarkdown($rows);

            return self::SUCCESS;
        }

        $headers = ['Motor', 'Fixture', 'Grupos', 'Sesiones', 'Sin programar', 'Violaciones duras', 'Puntaje blando', 'Estado', 'Segundos', 'Cumple'];

        $this->table(
            $breakdown ? [...$headers, 'Límite', 'Duras (evaluador PHP)', ...SoftWeights::RULES] : $headers,
            array_map(fn (array $row): array => $breakdown
                ? [...array_values(array_slice($row, 0, 10)), $row['limit'], $row['evaluated'], ...$row['by_rule']]
                : array_values($row), $rows),
        );

        return self::SUCCESS;
    }

    /**
     * Las entradas a medir, por nombre: [ruta de input.json, ruta de expected.json o null]. Null si alguna no existe
     * (ya dijo cuál).
     *
     * @return array<string, array{string, ?string}>|null
     */
    private function instances(): ?array
    {
        $directory = base_path('tests/Fixtures/solver/v1');
        $all = array_map('basename', glob($directory.'/*', GLOB_ONLYDIR) ?: []);
        sort($all);
        $inputs = $this->option('input');
        $fixtures = $this->option('fixture') ?: ($inputs === [] ? $all : []);
        $instances = [];

        foreach ($fixtures as $name) {
            if (! in_array($name, $all, true)) {
                $this->error("No hay un fixture «{$name}». Disponibles: ".implode(', ', $all).'.');

                return null;
            }

            $instances[$name] = [$directory.'/'.$name.'/input.json', $directory.'/'.$name.'/expected.json'];
        }

        foreach ($inputs as $path) {
            if (! is_file($path)) {
                $this->error("No hay una entrada en «{$path}».");

                return null;
            }

            $instances[basename(dirname($path))] = [$path, null];
        }

        return $instances;
    }

    /**
     * La tabla de la línea base de solver.md (6.12).
     *
     * @param  list<array<string, mixed>>  $rows
     */
    private function baselineMarkdown(array $rows): void
    {
        $this->line('| Fixture | Motor | Tiempo | Violaciones duras | Puntaje blando | Sin programar |');
        $this->line('|---|---|---|---|---|---|');

        foreach ($rows as $row) {
            $this->line(sprintf('| %s | %s | %s s | %d | %d | %d |', $row['fixture'], $row['engine'], $row['seconds'], $row['violations'], $row['score'], $row['unscheduled']));
        }
    }

    /**
     * La tabla del benchmark de la 7.11, con el desglose por regla blanda.
     *
     * @param  list<array<string, mixed>>  $rows
     */
    private function breakdownMarkdown(array $rows): void
    {
        $this->line('| Instancia | Motor | Límite | Tiempo | Estado | Duras | Duras (evaluador PHP) | Sin programar | Puntaje blando | '.implode(' | ', SoftWeights::RULES).' |');
        $this->line('|'.str_repeat('---|', 9 + count(SoftWeights::RULES)));

        foreach ($rows as $row) {
            $this->line(sprintf(
                '| %s | %s | %d s | %s s | %s | %d | %d | %d | %d | %s |',
                $row['fixture'], $row['engine'], $row['limit'], $row['seconds'], $row['status'], $row['violations'], $row['evaluated'], $row['unscheduled'], $row['score'],
                implode(' | ', $row['by_rule']),
            ));
        }
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
