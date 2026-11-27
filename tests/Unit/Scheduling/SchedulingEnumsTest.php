<?php

use App\Modules\Core\Authorization\Dimension;
use App\Modules\Scheduling\Enums\ConflictSeverity;
use App\Modules\Scheduling\Enums\GroupStatus;
use App\Modules\Scheduling\Enums\SchedulingPermission;
use App\Modules\Scheduling\Enums\SessionKind;

/*
| Los enums de Scheduling: sus nombres para la interfaz y las dimensiones de sus permisos.
*/

it('nombra en español los estados del grupo, los tipos de sesión y la gravedad de un choque', function () {
    expect(array_map(fn (GroupStatus $status): string => $status->label(), GroupStatus::cases()))->toBe(['Planeado', 'Confirmado', 'Cancelado'])
        ->and(array_map(fn (SessionKind $kind): string => $kind->label(), SessionKind::cases()))->toBe(['Teoría', 'Práctica', 'Virtual'])
        ->and(array_map(fn (ConflictSeverity $severity): string => $severity->label(), ConflictSeverity::cases()))->toBe(['Choque duro', 'Aviso']);
});

it('los permisos de Scheduling tienen nombre, son de gestión y el objetivo es un grupo', function () {
    foreach (SchedulingPermission::cases() as $permission) {
        expect($permission->label())->not->toBe('')
            ->and($permission->isPersonal())->toBeFalse()
            ->and($permission->appliesTo())->not->toBeNull()
            ->and($permission->dimensions())->toContain(Dimension::Modality, Dimension::Unit);
    }

    expect(SchedulingPermission::ScheduleView->dimensions())->not->toContain(Dimension::Campus)
        ->and(SchedulingPermission::RoomAssign->dimensions())->toContain(Dimension::Campus)
        ->and(SchedulingPermission::RoomAssign->dimensions())->not->toContain(Dimension::Career)
        ->and(SchedulingPermission::SessionEdit->dimensions())->toContain(Dimension::Career);
});
