<?php

use App\Modules\Facilities\Enums\RoomType;

it('laboratorio, taller y sala de cómputo son espacios de práctica (HC-06)', function (RoomType $type, bool $practice) {
    expect($type->isPracticeSpace())->toBe($practice);
})->with([
    'aula' => [RoomType::Aula, false],
    'laboratorio' => [RoomType::Laboratorio, true],
    'taller' => [RoomType::Taller, true],
    'sala de cómputo' => [RoomType::SalaComputo, true],
    'auditorio' => [RoomType::Auditorio, false],
]);

it('cada tipo tiene una etiqueta en español y el valor del modelo de datos', function () {
    expect(collect(RoomType::cases())->map->value->all())->toBe(['aula', 'laboratorio', 'taller', 'sala_computo', 'auditorio'])
        ->and(RoomType::SalaComputo->label())->toBe('Sala de cómputo');
});
