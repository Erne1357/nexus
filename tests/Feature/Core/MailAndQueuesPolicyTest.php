<?php

use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\FailedJob;
use App\Modules\Core\Models\MailConnection;
use App\Modules\Core\Models\MailLog;
use App\Modules\Core\Models\User;

/*
| MailLogPolicy, FailedJobPolicy y MailConnectionPolicy (CF-S8, CF-S9, CF-03): `core.mail.view`, `core.mail.connect`,
| `core.queue.view` y `core.queue.manage` son candados, solo del admin; ningún otro puesto ve ni hace nada.
*/

it('CF-03: el admin ve el registro de correos, reenvía invitaciones, ve las colas y reintenta o descarta', function () {
    $admin = User::factory()->admin()->create();

    expect($admin->can('viewAny', MailLog::class))->toBeTrue()
        ->and($admin->can('resendInvitation', MailLog::class))->toBeTrue()
        ->and($admin->can('viewAny', FailedJob::class))->toBeTrue()
        ->and($admin->can('retry', new FailedJob))->toBeTrue()
        ->and($admin->can('forget', new FailedJob))->toBeTrue()
        ->and($admin->can('manage', MailConnection::class))->toBeTrue();
});

it('CF-03: ningún otro puesto ve los correos ni las colas, ni reintenta, ni descarta, ni reenvía', function (Role $role, array $scope) {
    $user = User::factory()->withRole($role, ['campus_id' => Campus::factory()->create()->id, ...$scope])->create();

    expect($user->can('viewAny', MailLog::class))->toBeFalse()
        ->and($user->can('resendInvitation', MailLog::class))->toBeFalse()
        ->and($user->can('viewAny', FailedJob::class))->toBeFalse()
        ->and($user->can('retry', new FailedJob))->toBeFalse()
        ->and($user->can('forget', new FailedJob))->toBeFalse()
        ->and($user->can('manage', MailConnection::class))->toBeFalse();
})->with([
    'subdirección' => [Role::Subdireccion, []],
    'coordinador' => [Role::Coordinador, fn () => ['career_id' => careerKey(20)]],
    'jefe de departamento' => [Role::JefeDepartamento, fn () => ['department_id' => departmentKey(10)]],
    'jefe de proyecto docente' => [Role::JefeProyectoDocente, fn () => ['department_id' => departmentKey(10)]],
    'docente' => [Role::Docente, []],
]);

it('CF-03: niega todo a un admin desactivado', function () {
    $admin = User::factory()->admin()->create(['is_active' => false]);

    expect($admin->can('viewAny', MailLog::class))->toBeFalse()
        ->and($admin->can('viewAny', FailedJob::class))->toBeFalse()
        ->and($admin->can('manage', MailConnection::class))->toBeFalse();
});
