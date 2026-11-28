<?php

use App\Modules\Core\Observability\CanonicalLog;

it('elige el nivel por estado y por duración', function (int $status, float $ms, string $level) {
    expect(CanonicalLog::levelFor($status, $ms))->toBe($level);
})->with([
    'rápida y correcta' => [200, 40.0, 'info'],
    'lenta' => [200, 1500.0, 'warning'],
    '5xx rápida' => [503, 40.0, 'error'],
    '5xx lenta' => [500, 1500.0, 'error'],
]);

it('fija la lista blanca de la línea por petición y por job', function () {
    expect(CanonicalLog::REQUEST_KEYS)->toBe([
        'request_id', 'method', 'route', 'module', 'livewire', 'status', 'duration_ms', 'db_ms', 'db_queries', 'external_ms', 'user_id', 'role', 'release',
    ])->and(CanonicalLog::JOB_KEYS)->toBe([
        'request_id', 'job', 'queue', 'attempt', 'queue_wait_ms', 'duration_ms', 'db_ms', 'module', 'outcome',
    ]);
});

it('saca el módulo del namespace de la clase, en kebab, y cae a core fuera de los módulos', function () {
    expect(CanonicalLog::moduleOf('App\Modules\AcademicLoad\Jobs\Foo'))->toBe('academic-load')
        ->and(CanonicalLog::moduleOf('Tests\Foo'))->toBe('core');
});
