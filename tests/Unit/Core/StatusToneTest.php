<?php

use App\Modules\Core\Enums\StatusTone;

/*
| Tonos de la insignia de estado (DESIGN.md, "Insignias de estado"): cada tono con las clases de
| daisyUI y el icono de Heroicons de la tabla, iguales en toda la aplicación.
*/

$designTable = [
    'activo, validado' => [StatusTone::Success, 'success', 'badge-success badge-soft', 'o-check-circle'],
    'publicado' => [StatusTone::SuccessSolid, 'success-solid', 'badge-success', 'o-check-circle'],
    'inactivo' => [StatusTone::Neutral, 'neutral', 'badge-neutral badge-soft', 'o-minus-circle'],
    'borrador' => [StatusTone::Draft, 'draft', 'badge-dash', 'o-pencil'],
    'en revisión' => [StatusTone::Info, 'info', 'badge-info badge-soft', 'o-eye'],
    'pendiente' => [StatusTone::Warning, 'warning', 'badge-warning badge-soft', 'o-clock'],
    'con error, choque' => [StatusTone::Error, 'error', 'badge-error badge-soft', 'o-x-circle'],
    'periodo actual' => [StatusTone::Primary, 'primary', 'badge-primary', null],
];

it('da a cada tono su valor para tone="…", las clases y el icono de la tabla de DESIGN.md', function (StatusTone $tone, string $value, string $classes, ?string $icon) {
    expect($tone->value)->toBe($value)
        ->and($tone->classes())->toBe($classes)
        ->and($tone->icon())->toBe($icon);
})->with($designTable);

it('no tiene tonos fuera de la tabla de DESIGN.md', function () use ($designTable) {
    expect(array_column($designTable, 0))->toBe(StatusTone::cases());
});
