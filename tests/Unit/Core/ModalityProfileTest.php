<?php

use App\Modules\Core\Enums\StaffingMode;
use App\Modules\Core\Models\ModalityProfile;

/*
| El perfil de flujo de una modalidad (MS1, supuesto por confirmar; ADR-0025): el de CampusProfile sin
| `heads_edit_rooms`, que es de la validación de salones y no de la modalidad.
*/

it('expone los campos del perfil de flujo', function () {
    $profile = ModalityProfile::fromArray([
        'uses_rooms' => false,
        'uses_time_blocks' => false,
        'staffing_mode' => 'central',
        'block_minutes' => 90,
        'day_start' => '08:00',
        'day_end' => '20:00',
        'days' => [1, 2, 3, 4, 5, 6],
        'allow_external_teachers' => true,
        'requires_subdireccion_approval' => false,
    ]);

    expect($profile->usesRooms)->toBeFalse()
        ->and($profile->usesTimeBlocks)->toBeFalse()
        ->and($profile->staffingMode)->toBe(StaffingMode::Central)
        ->and($profile->blockMinutes)->toBe(90)
        ->and($profile->dayStart)->toBe('08:00')
        ->and($profile->dayEnd)->toBe('20:00')
        ->and($profile->days)->toBe([1, 2, 3, 4, 5, 6])
        ->and($profile->allowExternalTeachers)->toBeTrue()
        ->and($profile->requiresSubdireccionApproval)->toBeFalse();
});

it('usa el perfil presencial de PROJECT.md para las claves que faltan', function () {
    $profile = ModalityProfile::fromArray([]);

    expect($profile->usesRooms)->toBeTrue()
        ->and($profile->usesTimeBlocks)->toBeTrue()
        ->and($profile->staffingMode)->toBe(StaffingMode::Department)
        ->and($profile->blockMinutes)->toBe(60)
        ->and($profile->dayStart)->toBe('07:00')
        ->and($profile->dayEnd)->toBe('21:00')
        ->and($profile->days)->toBe([1, 2, 3, 4, 5]);
});

it('arranca sin visto bueno obligatorio de Subdirección (ADR-0008) y sin docentes externos', function () {
    $profile = ModalityProfile::fromArray([]);

    expect($profile->requiresSubdireccionApproval)->toBeFalse()
        ->and($profile->allowExternalTeachers)->toBeFalse();
});

it('completa con valores por omisión solo las claves que faltan', function () {
    $profile = ModalityProfile::fromArray(['days' => [1, 2, 3, 4, 5, 6], 'uses_rooms' => false]);

    expect($profile->days)->toBe([1, 2, 3, 4, 5, 6])
        ->and($profile->usesRooms)->toBeFalse()
        ->and($profile->blockMinutes)->toBe(60);
});

it('acepta el modo de asignación como enum', function () {
    expect(ModalityProfile::fromArray(['staffing_mode' => StaffingMode::Central])->staffingMode)
        ->toBe(StaffingMode::Central);
});

it('ordena los días y quita repetidos', function () {
    expect(ModalityProfile::fromArray(['days' => [5, 1, 3, 1]])->days)->toBe([1, 3, 5]);
});

it('se convierte de vuelta en el arreglo que guarda modalities.settings', function () {
    $settings = [
        'uses_rooms' => true,
        'uses_time_blocks' => true,
        'staffing_mode' => 'department',
        'block_minutes' => 60,
        'day_start' => '07:00',
        'day_end' => '21:00',
        'days' => [1, 2, 3, 4, 5, 6],
        'allow_external_teachers' => false,
        'requires_subdireccion_approval' => true,
    ];

    expect(ModalityProfile::fromArray($settings)->toArray())->toBe($settings)
        ->and(ModalityProfile::fromArray([])->toArray())->toBe(ModalityProfile::DEFAULTS);
});

it('ignora heads_edit_rooms si llega en settings', function () {
    expect(ModalityProfile::fromArray(['heads_edit_rooms' => true])->toArray())->not->toHaveKey('heads_edit_rooms')
        ->and(ModalityProfile::DEFAULTS)->not->toHaveKey('heads_edit_rooms');
});

it('se resume en una línea', function (array $settings, string $summary) {
    expect(ModalityProfile::fromArray($settings)->summary())->toBe($summary);
})->with([
    'presencial por omisión' => [[], 'Salones · bloques de 60 min · lunes a viernes, 7:00 a 21:00'],
    'con visto bueno de Subdirección' => [
        ['requires_subdireccion_approval' => true],
        'Salones · bloques de 60 min · lunes a viernes, 7:00 a 21:00 · visto bueno de Subdirección',
    ],
    'dos días seguidos' => [
        ['days' => [5, 6], 'block_minutes' => 120, 'day_start' => '08:00', 'day_end' => '14:00'],
        'Salones · bloques de 120 min · viernes y sábado, 8:00 a 14:00',
    ],
    'un solo día' => [['days' => [6], 'day_start' => '00:30', 'day_end' => '12:30'], 'Salones · bloques de 60 min · sábado, 0:30 a 12:30'],
    'días salteados, sin salones ni bloques, central y con externos' => [
        ['uses_rooms' => false, 'uses_time_blocks' => false, 'staffing_mode' => 'central', 'days' => [1, 3, 5], 'allow_external_teachers' => true],
        'Sin salones · sin bloques fijos · lunes, miércoles y viernes, 7:00 a 21:00 · asignación central · docentes externos',
    ],
]);

it('dice sus días y su horario, como en el resumen', function (array $settings, string $schedule) {
    expect(ModalityProfile::fromArray($settings)->schedule())->toBe($schedule);
})->with([
    'presencial por omisión' => [[], 'lunes a viernes, 7:00 a 21:00'],
    'dos días seguidos' => [['days' => [5, 6], 'day_start' => '08:00', 'day_end' => '14:00'], 'viernes y sábado, 8:00 a 14:00'],
]);

it('rechaza un perfil con valores inválidos', function (array $settings) {
    expect(fn () => ModalityProfile::fromArray($settings))->toThrow(InvalidArgumentException::class, 'Perfil de modalidad inválido: ');
})->with([
    'booleano como texto' => [['uses_rooms' => 'yes']],
    'booleano como entero' => [['requires_subdireccion_approval' => 1]],
    'modo de asignación desconocido' => [['staffing_mode' => 'regional']],
    'modo de asignación que no es texto' => [['staffing_mode' => 1]],
    'bloque de cero minutos' => [['block_minutes' => 0]],
    'bloque como texto' => [['block_minutes' => '60']],
    'bloques que no llenan la jornada exacta' => [['block_minutes' => 90, 'day_start' => '07:00', 'day_end' => '21:00']],
    'hora sin dos dígitos' => [['day_start' => '7:00']],
    'hora fuera de rango' => [['day_end' => '24:00']],
    'hora que no es texto' => [['day_start' => 700]],
    'hora con salto de línea final' => [['day_start' => "07:00\n"]],
    'fin antes del inicio' => [['day_start' => '21:00', 'day_end' => '07:00']],
    'fin igual al inicio' => [['day_start' => '07:00', 'day_end' => '07:00']],
    'sin días' => [['days' => []]],
    'día cero' => [['days' => [0, 1]]],
    'domingo, fuera de group_sessions.day' => [['days' => [1, 7]]],
    'día como texto' => [['days' => ['1']]],
    'días que no son lista' => [['days' => 'L-V']],
]);

describe('covering (MS11, supuesto por confirmar)', function () {
    it('la ventana cubre lunes a sábado de 7:00 a 21:00 con dos modalidades activas (MS11)', function () {
        $window = ModalityProfile::covering([
            ModalityProfile::fromArray(['days' => [1, 2, 3, 4, 5], 'day_start' => '07:00', 'day_end' => '21:00', 'block_minutes' => 60]),
            ModalityProfile::fromArray(['days' => [1, 2, 3, 4, 5, 6], 'day_start' => '08:00', 'day_end' => '20:00', 'block_minutes' => 60]),
        ]);

        expect($window->days)->toBe([1, 2, 3, 4, 5, 6])
            ->and($window->dayStart)->toBe('07:00')
            ->and($window->dayEnd)->toBe('21:00')
            ->and($window->blockMinutes)->toBe(60);
    });

    it('une los días sin repetir y toma el bloque más corto si divide la ventana', function () {
        $window = ModalityProfile::covering([
            ModalityProfile::fromArray(['days' => [1, 3], 'block_minutes' => 60]),
            ModalityProfile::fromArray(['days' => [3, 6], 'block_minutes' => 30]),
        ]);

        expect($window->days)->toBe([1, 3, 6])
            ->and($window->blockMinutes)->toBe(30);
    });

    it('si el bloque más corto no divide la ventana usa el máximo común divisor de los bloques y la ventana', function () {
        $window = ModalityProfile::covering([
            ModalityProfile::fromArray(['day_start' => '08:00', 'day_end' => '20:00', 'block_minutes' => 90]),
            ModalityProfile::fromArray(['day_start' => '07:00', 'day_end' => '14:00', 'block_minutes' => 70]),
        ]);

        // Ventana 7:00 a 20:00 = 780 min; 70 no la divide; mcd(90, 70, 780) = 10.
        expect($window->dayStart)->toBe('07:00')
            ->and($window->dayEnd)->toBe('20:00')
            ->and($window->blockMinutes)->toBe(10);
    });

    it('sin perfiles es el presencial por omisión', function () {
        expect(ModalityProfile::covering([])->toArray())->toBe(ModalityProfile::DEFAULTS);
    });

    it('con un solo perfil conserva su ventana y toma lo demás de los valores por omisión', function () {
        $window = ModalityProfile::covering([ModalityProfile::fromArray(['staffing_mode' => 'central', 'allow_external_teachers' => true, 'days' => [2, 4]])]);

        expect($window->days)->toBe([2, 4])
            ->and($window->allowExternalTeachers)->toBeFalse()
            ->and($window->staffingMode->value)->toBe('department');
    });
});
