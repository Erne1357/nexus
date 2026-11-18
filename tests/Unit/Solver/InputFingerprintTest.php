<?php

use App\Modules\Solver\Contract\SolverInput;
use App\Modules\Solver\Rules\InputFingerprint;

/*
| SV-02: la huella de la entrada de una corrida (tarea 6.8). Es el sha256 del JSON canónico de lo que define si lo que
| propuso el motor sigue vigente: los grupos con sus sesiones, docente, cupo y horas; las sesiones fijas y la ocupación;
| los salones y docentes con su disponibilidad. No cuentan el identificador de la corrida ni lo que solo ajusta al motor.
*/

/**
 * La entrada `medium` de los fixtures, con un cambio opcional sobre su arreglo.
 *
 * @param  (callable(array<string, mixed>): array<string, mixed>)|null  $change
 */
function fingerprintInput(?callable $change = null): SolverInput
{
    $data = json_decode(file_get_contents(__DIR__.'/../../Fixtures/solver/v1/medium/input.json'), true, flags: JSON_THROW_ON_ERROR);
    $data['groups'][1]['current_sessions'] = [
        ['day' => 1, 'starts_at' => '09:00', 'ends_at' => '11:00', 'room_id' => 'r001', 'kind' => 'teoria'],
        ['day' => 2, 'starts_at' => '09:00', 'ends_at' => '11:00', 'room_id' => 'r001', 'kind' => 'teoria'],
    ];

    return SolverInput::fromArray($change === null ? $data : $change($data));
}

it('SV-02: es un sha256 y la misma entrada da la misma huella', function () {
    $fingerprint = InputFingerprint::of(fingerprintInput());

    expect($fingerprint)->toMatch('/^[0-9a-f]{64}$/')
        ->and(InputFingerprint::of(fingerprintInput()))->toBe($fingerprint);
});

it('SV-02: no cambia con el identificador, el límite de tiempo, las opciones, los pesos ni el traslado', function () {
    $changed = fingerprintInput(function (array $data): array {
        $data['run_id'] = 'otra-corrida';
        $data['time_limit_seconds'] = 600;
        $data['options'] = ['threads' => 4, 'seed' => 7, 'suggest_teachers' => true, 'return_intermediate' => true];
        $data['soft_weights']['SC-01'] = 0;
        $data['travel_minutes'] = 90;

        return $data;
    });

    expect(InputFingerprint::of($changed))->toBe(InputFingerprint::of(fingerprintInput()));
});

it('SV-02: no depende del orden en que el armado entrega grupos, docentes, salones y sesiones', function () {
    $reordered = fingerprintInput(function (array $data): array {
        $data['groups'] = array_reverse($data['groups']);
        $data['teachers'] = array_reverse($data['teachers']);
        $data['rooms'] = array_reverse($data['rooms']);
        $data['campuses'] = array_reverse($data['campuses']);

        foreach ($data['groups'] as &$group) {
            $group['current_sessions'] = array_reverse($group['current_sessions'] ?? []);
            $group['fixed_sessions'] = array_reverse($group['fixed_sessions'] ?? []);
            $group['cohorts'] = array_reverse($group['cohorts'] ?? []);
        }

        return $data;
    });

    expect(InputFingerprint::of($reordered))->toBe(InputFingerprint::of(fingerprintInput()));
});

it('SV-02: cambia cuando cambia algo de lo que define la vigencia', function (Closure $change) {
    expect(InputFingerprint::of(fingerprintInput($change)))->not->toBe(InputFingerprint::of(fingerprintInput()));
})->with([
    'mover una sesión actual' => [function (array $data): array {
        $data['groups'][1]['current_sessions'][0]['starts_at'] = '11:00';
        $data['groups'][1]['current_sessions'][0]['ends_at'] = '13:00';

        return $data;
    }],
    'cambiar el cupo de un grupo' => [function (array $data): array {
        $data['groups'][1]['capacity'] = 30;

        return $data;
    }],
    'cambiar el docente de un grupo' => [function (array $data): array {
        $data['groups'][1]['teacher_id'] = 't002';

        return $data;
    }],
    'cambiar las horas de un grupo' => [function (array $data): array {
        $data['groups'][1]['practice_hours'] = 1;

        return $data;
    }],
    'quitar un grupo' => [function (array $data): array {
        array_pop($data['groups']);

        return $data;
    }],
    'mover una sesión fija' => [function (array $data): array {
        $data['groups'][0]['fixed_sessions'][0]['day'] = 2;

        return $data;
    }],
    'ocupar al docente fuera de la corrida' => [function (array $data): array {
        $data['teachers'][0]['busy'][] = ['day' => 4, 'starts_at' => '07:00', 'ends_at' => '08:00', 'campus_code' => 'C1'];

        return $data;
    }],
    'cambiar la disponibilidad del docente' => [function (array $data): array {
        $data['teachers'][0]['unavailable'] = [];

        return $data;
    }],
    'bloquear un salón' => [function (array $data): array {
        $data['rooms'][0]['blocks'][] = ['day' => 6, 'starts_at' => '07:00', 'ends_at' => '09:00'];

        return $data;
    }],
    'ocupar un salón fuera de la corrida' => [function (array $data): array {
        $data['rooms'][0]['busy'][] = ['day' => 6, 'starts_at' => '07:00', 'ends_at' => '09:00'];

        return $data;
    }],
]);
