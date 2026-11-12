<?php

use App\Modules\CatalogValidation\Enums\Catalog;
use App\Modules\CatalogValidation\Enums\ReviewFlag;
use App\Modules\CatalogValidation\Enums\ReviewStatus;
use App\Modules\Core\Enums\StatusTone;

/*
| Catálogos que el jefe valida cada periodo y estado de su revisión (docs/dominio/modelo-de-datos.md,
| `catalog_reviews`). Todos se revisan una vez por periodo y departamento para todo el Tec, también los salones
| (decisión 6, confirmada el 12 de noviembre de 2026); los salones solo se exigen donde el campus usa salones (HC-14).
*/

it('cada catálogo tiene su etiqueta', function (Catalog $catalog, string $label) {
    expect($catalog->label())->toBe($label);
})->with([
    [Catalog::Teachers, 'Docentes'],
    [Catalog::Rooms, 'Salones'],
    [Catalog::Subjects, 'Materias'],
    [Catalog::StudyPlans, 'Planes de estudio'],
    [Catalog::Careers, 'Carreras'],
]);

it('HC-14: un campus que usa salones exige los cinco; uno sin salones, los cuatro generales', function () {
    expect(Catalog::general())->toBe([Catalog::Teachers, Catalog::Subjects, Catalog::StudyPlans, Catalog::Careers]);

    expect(Catalog::requiredFor(usesRooms: true))->toBe(Catalog::cases())
        ->and(Catalog::requiredFor(usesRooms: false))->toBe([Catalog::Teachers, Catalog::Subjects, Catalog::StudyPlans, Catalog::Careers]);
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
