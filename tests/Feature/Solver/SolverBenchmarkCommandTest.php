<?php

use App\Modules\Solver\Contract\SolverInput;
use App\Modules\Solver\Contract\SolverOutput;
use App\Modules\Solver\Engines\EngineRegistry;
use App\Modules\Solver\Engines\FixedEngine;
use App\Modules\Solver\Engines\SolverEngine;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;

/**
 * El comando de benchmark del motor (tarea 6.12; docs/arquitectura/solver.md, «Línea base»).
 *
 * @return array{int, string}
 */
function benchmarkRun(array $options): array
{
    $code = Artisan::call('solver:benchmark', $options);

    return [$code, Artisan::output()];
}

it('reporta una fila por motor y fixture con sus valores', function () {
    [$code, $output] = benchmarkRun(['--engine' => ['fixed'], '--fixture' => ['tiny', 'small']]);

    expect($code)->toBe(0)
        ->and($output)->toContain('Motor', 'Fixture', 'Grupos', 'Sesiones', 'Sin programar', 'Violaciones duras', 'Puntaje blando', 'Estado', 'Segundos', 'Cumple');

    $rows = array_values(array_filter(array_map('trim', explode("\n", $output)), fn (string $line): bool => str_starts_with($line, '| fixed')));

    expect($rows)->toHaveCount(2);

    $cells = fn (string $row): array => array_map('trim', explode('|', trim($row, '|')));
    [$engine, $fixture, $groups, $sessions, , $violations, $score, $status, $seconds, $complies] = $cells($rows[0]);

    expect($engine)->toBe('fixed')
        ->and($fixture)->toBe('tiny')
        ->and((int) $groups)->toBeGreaterThan(0)
        ->and((int) $sessions)->toBeGreaterThan(0)
        ->and($violations)->toBe('0')
        ->and($score)->toBe('0')
        ->and($status)->toBe('partial')
        ->and($seconds)->toMatch('/^\d+\.\d{2}$/')
        ->and($complies)->toBe('—')
        ->and($cells($rows[1])[1])->toBe('small');
});

it('imprime la tabla en Markdown con las columnas de la línea base', function () {
    [$code, $output] = benchmarkRun(['--engine' => ['fixed'], '--fixture' => ['tiny'], '--markdown' => true]);

    expect($code)->toBe(0)
        ->and($output)->toContain('| Fixture | Motor | Tiempo | Violaciones duras | Puntaje blando | Sin programar |')
        ->and($output)->toContain('|---|---|---|---|---|---|')
        ->and($output)->toContain('| tiny | fixed |');
});

it('falla con un mensaje en español si el fixture o el motor no existen', function () {
    [$code, $output] = benchmarkRun(['--engine' => ['fixed'], '--fixture' => ['no-existe']]);

    expect($code)->toBe(1)->and($output)->toContain('No hay un fixture «no-existe»');

    [$code, $output] = benchmarkRun(['--engine' => ['nada']]);

    expect($code)->toBe(1)->and($output)->toContain('No hay un motor «nada»');
});

/**
 * Un motor de prueba que guarda los límites de tiempo que recibe y responde como el motor de prueba.
 */
function benchmarkSpyEngine(): SolverEngine
{
    return new class implements SolverEngine
    {
        /** @var list<int> */
        public array $limits = [];

        public function key(): string
        {
            return 'spy';
        }

        public function label(): string
        {
            return 'spy';
        }

        public function supportedVersions(): array
        {
            return [SolverInput::VERSION];
        }

        public function checksHardRules(): bool
        {
            return false;
        }

        public function solve(SolverInput $input, ?Closure $progress = null): SolverOutput
        {
            $this->limits[] = $input->timeLimitSeconds;

            return (new FixedEngine)->solve($input, $progress);
        }
    };
}

it('7.11: --time-limit cambia el límite de cada entrada y rechaza uno fuera de 10 a 600 segundos', function () {
    $spy = benchmarkSpyEngine();
    app()->instance(EngineRegistry::class, new EngineRegistry([$spy]));

    [$code] = benchmarkRun(['--engine' => ['spy'], '--fixture' => ['tiny', 'medium'], '--time-limit' => '45']);

    expect($code)->toBe(0)->and($spy->limits)->toBe([45, 45]);

    [$code, $output] = benchmarkRun(['--engine' => ['spy'], '--fixture' => ['tiny'], '--time-limit' => '5']);

    expect($code)->toBe(1)->and($output)->toContain('El límite de --time-limit va de 10 a 600 segundos.');
});

it('7.11: --input corre entradas de storage/app con el nombre de su carpeta desde ahí, sin pisarse, y solo esas si no se da --fixture', function () {
    $directory = storage_path('app/benchmark-'.uniqid());
    $name = basename($directory);

    foreach (['a/carga', 'b/carga'] as $folder) {
        File::ensureDirectoryExists($directory.'/'.$folder);
        File::copy(base_path('tests/Fixtures/solver/v1/small/input.json'), $directory.'/'.$folder.'/input.json');
    }

    try {
        [$code, $output] = benchmarkRun(['--engine' => ['fixed'], '--markdown' => true, '--input' => [
            $directory.'/a/carga/input.json', $directory.'/b/carga/input.json', $directory.'/a/carga/input.json',
        ]]);
    } finally {
        File::deleteDirectory($directory);
    }

    expect($code)->toBe(0)
        ->and($output)->toContain("| {$name}/a/carga | fixed |", "| {$name}/b/carga | fixed |", "| {$name}/a/carga (2) | fixed |")
        ->and($output)->not->toContain('| tiny |');

    [$code, $output] = benchmarkRun(['--engine' => ['fixed'], '--input' => ['/no/existe/input.json']]);

    expect($code)->toBe(1)->and($output)->toContain('No hay una entrada en «/no/existe/input.json»');
});

it('7.11: --input no acepta una entrada fuera de storage/app', function (string $path) {
    [$code, $output] = benchmarkRun(['--engine' => ['fixed'], '--input' => [$path]]);

    expect($code)->toBe(1)->and($output)->toContain('La entrada de --input debe estar dentro de storage/app.');
})->with([
    'un fixture' => [fn (): string => base_path('tests/Fixtures/solver/v1/small/input.json')],
    'subiendo desde storage/app' => [fn (): string => storage_path('app/../../tests/Fixtures/solver/v1/tiny/input.json')],
]);

it('7.11: --breakdown agrega el límite, las duras según el evaluador en PHP y el puntaje de SC-01 a SC-09', function () {
    [$code, $output] = benchmarkRun(['--engine' => ['heuristic'], '--fixture' => ['small'], '--markdown' => true, '--breakdown' => true]);

    $header = '| Instancia | Motor | Límite | Tiempo | Estado | Duras | Duras (evaluador PHP) | Sin programar | Puntaje blando | SC-01 | SC-02 | SC-03 | SC-04 | SC-05 | SC-06 | SC-07 | SC-08 | SC-09 |';
    $row = collect(explode("\n", $output))->first(fn (string $line): bool => str_starts_with($line, '| small | heuristic |'));
    $cells = array_map('trim', explode('|', trim((string) $row, '|')));

    expect($code)->toBe(0)
        ->and($output)->toContain($header)
        ->and($cells)->toHaveCount(18)
        ->and($cells[2])->toBe('10 s')
        ->and($cells[5])->toBe('0')
        ->and($cells[6])->toBe('0')
        ->and((int) $cells[8])->toBe(array_sum(array_map('intval', array_slice($cells, 9))));
});
