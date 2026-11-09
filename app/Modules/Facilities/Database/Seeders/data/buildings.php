<?php

/**
 * Edificios y salones del demo, por clave de campus (nombres y claves inventados, al estilo del ITCJ). Cada
 * edificio lleva `code`, `name`, `active` (true por omisión), `deleted` (false) y sus grupos de salones
 * (`rooms`). Un grupo genera `count` salones con la clave `prefix` + consecutivo de dos cifras y el nombre
 * `label` + clave; sus opciones: `type` (RoomType), `capacity` [mínimo, máximo], `pin` (capacidades exactas de
 * los primeros salones), `owner` (clave del departamento dueño), `features` (etiquetas posibles), `inactive`
 * y `deleted` (cuántos de los últimos salones quedan inactivos o borrados), `long` (nombre de más de 60
 * caracteres en el primero) y `unnamed` (sin nombre). Lo usa FacilitiesDemoSeeder.
 *
 * @return array<string, list<array<string, mixed>>>
 */
$classroomFeatures = ['proyector', 'pizarrón', 'aire acondicionado', 'pantalla'];
$labFeatures = ['campana de extracción', 'tomas de 220 V', 'mesas de trabajo', 'aire acondicionado'];
$computerFeatures = ['equipos de cómputo', 'proyector', 'aire acondicionado', 'red cableada'];

$classrooms = fn (string $prefix, int $count, array $extra = []): array => $extra + [
    'prefix' => $prefix, 'count' => $count, 'type' => 'aula', 'capacity' => [30, 45], 'label' => 'Aula ',
    'features' => $classroomFeatures,
];

/*
| Campus II: 7 edificios y unos 90 salones.
*/
$campusTwo = [
    ['code' => 'A', 'name' => 'Edificio A', 'rooms' => [
        $classrooms('A', 20, ['capacity' => [28, 45], 'pin' => [12]]),
    ]],
    ['code' => 'B', 'name' => 'Edificio B', 'rooms' => [
        $classrooms('B', 17, ['capacity' => [35, 60], 'inactive' => 2]),
        $classrooms('BX', 3, ['label' => 'Aula de seminarios ', 'capacity' => [15, 25], 'deleted' => 1]),
    ]],
    ['code' => 'LC', 'name' => 'Laboratorio de Cómputo', 'rooms' => [
        ['prefix' => 'LC', 'count' => 10, 'type' => 'sala_computo', 'capacity' => [20, 35], 'label' => 'Sala ', 'owner' => 'SC', 'features' => $computerFeatures, 'inactive' => 1],
    ]],
    ['code' => 'LE', 'name' => 'Laboratorios de Electrónica y Ciencias Básicas', 'rooms' => [
        ['prefix' => 'LE', 'count' => 8, 'type' => 'laboratorio', 'capacity' => [15, 25], 'label' => 'Laboratorio de Electrónica ', 'owner' => 'EE', 'features' => $labFeatures, 'long' => true],
        ['prefix' => 'LF', 'count' => 6, 'type' => 'laboratorio', 'capacity' => [18, 30], 'label' => 'Laboratorio de Física ', 'owner' => 'CB', 'features' => $labFeatures],
    ]],
    ['code' => 'TM', 'name' => 'Taller de Metal-Mecánica', 'rooms' => [
        ['prefix' => 'TM', 'count' => 6, 'type' => 'taller', 'capacity' => [12, 30], 'label' => 'Taller ', 'owner' => 'MM', 'features' => ['tomas de 220 V', 'grúa viajera', 'mesas de trabajo'], 'pin' => [12]],
        ['prefix' => 'TI', 'count' => 3, 'type' => 'taller', 'capacity' => [20, 30], 'label' => 'Taller de manufactura ', 'owner' => 'II', 'features' => ['mesas de trabajo']],
    ]],
    ['code' => 'UM', 'name' => 'Edificio de Usos Múltiples', 'rooms' => [
        ['prefix' => 'AU', 'count' => 3, 'type' => 'auditorio', 'capacity' => [100, 250], 'pin' => [300], 'label' => 'Auditorio ', 'features' => ['audio', 'proyector', 'aire acondicionado']],
        $classrooms('UM', 7, ['capacity' => [40, 80], 'owner' => 'CEA']),
    ]],
    ['code' => 'H', 'name' => 'Edificio H (en remodelación)', 'active' => false, 'rooms' => [
        $classrooms('H', 6, ['inactive' => 6, 'unnamed' => true]),
    ]],
];

/*
| Campus I: 12 edificios y unos 180 salones, repartidos en los mismos tipos.
*/
$campusOne = [];

foreach (['A', 'B', 'C', 'D', 'E', 'F'] as $letter) {
    $campusOne[] = ['code' => $letter, 'name' => 'Edificio '.$letter, 'rooms' => [
        $classrooms($letter, 18, ['capacity' => [25, 50], 'inactive' => $letter === 'F' ? 3 : 0, 'unnamed' => $letter === 'E']),
    ]];
}

$campusOne[] = ['code' => 'LC', 'name' => 'Laboratorio de Cómputo', 'rooms' => [
    ['prefix' => 'LC', 'count' => 14, 'type' => 'sala_computo', 'capacity' => [20, 40], 'label' => 'Sala ', 'owner' => 'SC', 'features' => $computerFeatures],
]];
$campusOne[] = ['code' => 'LQ', 'name' => 'Laboratorios de Ciencias', 'rooms' => [
    ['prefix' => 'LQ', 'count' => 10, 'type' => 'laboratorio', 'capacity' => [15, 30], 'label' => 'Laboratorio de Química ', 'owner' => 'CB', 'features' => $labFeatures, 'long' => true],
    ['prefix' => 'LS', 'count' => 6, 'type' => 'laboratorio', 'capacity' => [15, 25], 'label' => 'Laboratorio de Sistemas ', 'features' => $labFeatures, 'deleted' => 1],
]];
$campusOne[] = ['code' => 'IE', 'name' => 'Edificio de Ingeniería Eléctrica', 'rooms' => [
    ['prefix' => 'IE', 'count' => 10, 'type' => 'laboratorio', 'capacity' => [15, 25], 'label' => 'Laboratorio de Electrónica ', 'owner' => 'EE', 'features' => $labFeatures, 'inactive' => 1],
    $classrooms('IA', 6, ['capacity' => [30, 40]]),
]];
$campusOne[] = ['code' => 'TA', 'name' => 'Talleres', 'rooms' => [
    ['prefix' => 'TA', 'count' => 10, 'type' => 'taller', 'capacity' => [12, 28], 'label' => 'Taller ', 'owner' => 'MM', 'features' => ['tomas de 220 V', 'grúa viajera', 'mesas de trabajo'], 'pin' => [12]],
]];
$campusOne[] = ['code' => 'UM', 'name' => 'Centro de Información y Usos Múltiples', 'rooms' => [
    ['prefix' => 'AU', 'count' => 4, 'type' => 'auditorio', 'capacity' => [100, 280], 'pin' => [300], 'label' => 'Auditorio ', 'features' => ['audio', 'proyector', 'aire acondicionado']],
    $classrooms('UM', 8, ['capacity' => [40, 90], 'owner' => 'CEA']),
]];
$campusOne[] = ['code' => 'G', 'name' => 'Edificio G (clausurado)', 'active' => false, 'rooms' => [
    $classrooms('G', 6, ['inactive' => 6]),
]];

return ['C1' => $campusOne, 'C2' => $campusTwo];
