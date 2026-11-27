<?php

use App\Modules\AcademicLoad\Events\LoadSnapshotRequested;
use App\Modules\AcademicLoad\Models\AcademicLoad;

/*
| La publicación pide los grupos de su foto con LoadSnapshotRequested; los da un solo módulo (Scheduling).
*/

it('no tiene grupos mientras nadie contesta', function () {
    expect((new LoadSnapshotRequested(new AcademicLoad))->groups())->toBeNull();
});

it('guarda los grupos que le dan', function () {
    $event = new LoadSnapshotRequested(new AcademicLoad);
    $event->provideGroups([['code' => 'A']]);

    expect($event->groups())->toBe([['code' => 'A']]);
});

it('rechaza una segunda fuente de grupos', function () {
    $event = new LoadSnapshotRequested(new AcademicLoad);
    $event->provideGroups([]);

    expect(fn () => $event->provideGroups([]))->toThrow(LogicException::class);
});
