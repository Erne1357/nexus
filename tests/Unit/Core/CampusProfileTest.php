<?php

use App\Modules\Core\Enums\StaffingMode;
use App\Modules\Core\Models\CampusProfile;

it('expone los campos del perfil de flujo', function () {
    $profile = CampusProfile::fromArray([
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
    $profile = CampusProfile::fromArray([]);

    expect($profile->usesRooms)->toBeTrue()
        ->and($profile->usesTimeBlocks)->toBeTrue()
        ->and($profile->staffingMode)->toBe(StaffingMode::Department)
        ->and($profile->blockMinutes)->toBe(60)
        ->and($profile->dayStart)->toBe('07:00')
        ->and($profile->dayEnd)->toBe('21:00')
        ->and($profile->days)->toBe([1, 2, 3, 4, 5]);
});

it('arranca sin visto bueno obligatorio de Subdirección (ADR-0008) y sin docentes externos', function () {
    $profile = CampusProfile::fromArray([]);

    expect($profile->requiresSubdireccionApproval)->toBeFalse()
        ->and($profile->allowExternalTeachers)->toBeFalse();
});

it('completa con valores por omisión solo las claves que faltan', function () {
    $profile = CampusProfile::fromArray(['days' => [1, 2, 3, 4, 5, 6], 'uses_rooms' => false]);

    expect($profile->days)->toBe([1, 2, 3, 4, 5, 6])
        ->and($profile->usesRooms)->toBeFalse()
        ->and($profile->blockMinutes)->toBe(60);
});

it('acepta el modo de asignación como enum', function () {
    expect(CampusProfile::fromArray(['staffing_mode' => StaffingMode::Central])->staffingMode)
        ->toBe(StaffingMode::Central);
});

it('ordena los días y quita repetidos', function () {
    expect(CampusProfile::fromArray(['days' => [5, 1, 3, 1]])->days)->toBe([1, 3, 5]);
});

it('se convierte de vuelta en el arreglo que guarda campuses.settings', function () {
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

    expect(CampusProfile::fromArray($settings)->toArray())->toBe($settings)
        ->and(CampusProfile::fromArray([])->toArray())->toHaveKeys(array_keys($settings));
});

it('rechaza un perfil con valores inválidos', function (array $settings) {
    expect(fn () => CampusProfile::fromArray($settings))->toThrow(InvalidArgumentException::class);
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
