<?php

use App\Modules\CatalogValidation\Enums\Catalog;
use App\Modules\CatalogValidation\Enums\ReviewFlag;
use App\Modules\CatalogValidation\Enums\ReviewStatus;
use App\Modules\Core\Enums\StatusTone;

/*
| Catálogos que el jefe valida cada periodo y estado de su revisión (docs/dominio/modelo-de-datos.md,
| `catalog_reviews`). Los generales se revisan una vez por periodo para todo el Tec; los salones, en
| cada campus (D2, confirmado el 1 de noviembre de 2026).
*/

it('cada catálogo tiene su etiqueta y dice si es general o de un campus', function (Catalog $catalog, string $label, bool $general) {
    expect($catalog->label())->toBe($label)
        ->and($catalog->isGeneral())->toBe($general);
})->with([
    [Catalog::Teachers, 'Docentes', true],
    [Catalog::Rooms, 'Salones', false],
    [Catalog::Subjects, 'Materias', true],
    [Catalog::StudyPlans, 'Planes de estudio', true],
    [Catalog::Careers, 'Carreras', true],
]);

it('los catálogos generales son los cuatro que no son salones', function () {
    expect(Catalog::general())->toBe([Catalog::Teachers, Catalog::Subjects, Catalog::StudyPlans, Catalog::Careers]);
});

it('cada estado de revisión tiene su etiqueta y el tono de su insignia', function (ReviewStatus $status, string $label, StatusTone $tone) {
    expect($status->label())->toBe($label)
        ->and($status->tone())->toBe($tone);
})->with([
    [ReviewStatus::Pending, 'Pendiente', StatusTone::Warning],
    [ReviewStatus::InReview, 'En revisión', StatusTone::Info],
    [ReviewStatus::Validated, 'Validado', StatusTone::Success],
]);

it('cada tipo de observación tiene su etiqueta', function (ReviewFlag $flag, string $label) {
    expect($flag->label())->toBe($label);
})->with([
    [ReviewFlag::Inactive, 'Debe desactivarse'],
    [ReviewFlag::WrongCapacity, 'Capacidad incorrecta'],
    [ReviewFlag::Duplicate, 'Duplicado'],
    [ReviewFlag::MissingData, 'Faltan datos'],
    [ReviewFlag::Other, 'Otra'],
]);

it('la capacidad incorrecta solo se observa en salones; lo demás, en cualquier catálogo (supuesto por confirmar)', function (Catalog $catalog) {
    expect(ReviewFlag::WrongCapacity->appliesTo($catalog))->toBe($catalog === Catalog::Rooms)
        ->and(ReviewFlag::Inactive->appliesTo($catalog))->toBeTrue()
        ->and(ReviewFlag::Other->appliesTo($catalog))->toBeTrue();
})->with(Catalog::cases());
