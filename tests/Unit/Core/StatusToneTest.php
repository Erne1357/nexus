<?php

use App\Modules\Core\Enums\StatusTone;

/*
| Tonos de la insignia de estado (DESIGN.md, "Insignias de estado"): cada tono con las clases de
| daisyUI y el icono de Heroicons de la tabla, iguales en toda la aplicación.
*/

it('da a cada tono las clases y el icono de la tabla de DESIGN.md', function (StatusTone $tone, string $classes, ?string $icon) {
    expect($tone->classes())->toBe($classes)
        ->and($tone->icon())->toBe($icon);
})->with([
    'activo, validado' => [StatusTone::Success, 'badge-success badge-soft', 'o-check-circle'],
    'publicado' => [StatusTone::SuccessSolid, 'badge-success', 'o-check-circle'],
    'inactivo' => [StatusTone::Neutral, 'badge-neutral badge-soft', 'o-minus-circle'],
    'borrador' => [StatusTone::Draft, 'badge-dash', 'o-pencil'],
    'en revisión' => [StatusTone::Info, 'badge-info badge-soft', 'o-eye'],
    'pendiente' => [StatusTone::Warning, 'badge-warning badge-soft', 'o-clock'],
    'con error, choque' => [StatusTone::Error, 'badge-error badge-soft', 'o-x-circle'],
    'periodo actual' => [StatusTone::Primary, 'badge-primary', null],
]);

it('cubre todos los tonos con la tabla de arriba', function () {
    expect(array_map(fn (StatusTone $tone): string => $tone->value, StatusTone::cases()))->toBe([
        'success',
        'success-solid',
        'neutral',
        'draft',
        'info',
        'warning',
        'error',
        'primary',
    ]);
});
