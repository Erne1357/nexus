<?php

use Illuminate\Support\Facades\Artisan;

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
