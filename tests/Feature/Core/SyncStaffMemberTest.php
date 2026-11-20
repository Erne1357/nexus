<?php

use App\Modules\Core\Actions\SyncStaffMember;
use App\Modules\Core\Enums\StaffType;
use App\Modules\Core\Models\User;
use App\Modules\Faculty\Actions\SyncTeacherAccess;
use App\Modules\Faculty\Enums\AccessSync;
use App\Modules\Faculty\Models\Teacher;
use Spatie\Activitylog\Models\Activity;

/*
| SyncStaffMember (R-V2-3, confirmado el 19 de noviembre de 2026): busca al usuario por RFC, lo crea sin acceso ni puesto
| si falta y le pone su tipo de personal; no toca nombre, correo ni acceso de uno que ya existe.
*/

it('crea al usuario sin acceso ni puesto, con su tipo y el correo si está libre', function () {
    $user = app(SyncStaffMember::class)->handle('ADMI800101AA1', 'Ana Ruiz', 'ana@cdjuarez.tecnm.mx', StaffType::Administrativo);

    expect($user->username)->toBe('ADMI800101AA1')
        ->and($user->staff_type)->toBe(StaffType::Administrativo)
        ->and($user->password)->toBeNull()
        ->and($user->email)->toBe('ana@cdjuarez.tecnm.mx')
        ->and($user->roleAssignments()->count())->toBe(0);
});

it('no guarda un correo que otro usuario ya tiene, sin importar mayúsculas', function () {
    User::factory()->create(['email' => 'ana@cdjuarez.tecnm.mx']);

    $user = app(SyncStaffMember::class)->handle('ADMI800101AA1', 'Ana Ruiz', 'ANA@cdjuarez.tecnm.mx', StaffType::Administrativo);

    expect($user->email)->toBeNull();
});

it('a un usuario que ya existe solo le pone el tipo y no le cambia nombre, correo ni acceso', function () {
    $existing = User::factory()->create(['username' => 'DOCE800101AA1', 'name' => 'Nombre Propio', 'email' => 'propio@cdjuarez.tecnm.mx']);
    $password = $existing->password;

    $user = app(SyncStaffMember::class)->handle('DOCE800101AA1', 'Otro Nombre', 'otro@cdjuarez.tecnm.mx', StaffType::Docente);

    expect($user->is($existing))->toBeTrue()
        ->and($user->fresh()->staff_type)->toBe(StaffType::Docente)
        ->and($user->fresh()->name)->toBe('Nombre Propio')
        ->and($user->fresh()->email)->toBe('propio@cdjuarez.tecnm.mx')
        ->and($user->fresh()->password)->toBe($password);
});

it('sin tipo no borra el que ya tiene', function () {
    User::factory()->create(['username' => 'DOCE800101AA1', 'staff_type' => StaffType::Docente]);

    app(SyncStaffMember::class)->handle('DOCE800101AA1', 'X', null, null);

    expect(User::where('username', 'DOCE800101AA1')->sole()->staff_type)->toBe(StaffType::Docente);
});

it('una segunda corrida no cambia nada ni escribe bitácora', function () {
    $action = app(SyncStaffMember::class);
    $action->handle('ADMI800101AA1', 'Ana Ruiz', null, StaffType::Administrativo);
    $logs = Activity::query()->count();

    $action->handle('ADMI800101AA1', 'Ana Ruiz', null, StaffType::Administrativo);

    expect(Activity::query()->count())->toBe($logs);
});

it('cambiar el tipo deja su bitácora con el valor anterior y el nuevo', function () {
    $user = User::factory()->create(['username' => 'DOCE800101AA1']);

    app(SyncStaffMember::class)->handle('DOCE800101AA1', 'X', null, StaffType::Docente);

    $log = Activity::query()->where('subject_id', $user->id)->where('event', 'updated')->sole();
    expect($log->attribute_changes['attributes']['staff_type'])->toBe('docente')
        ->and($log->attribute_changes['old']['staff_type'])->toBeNull();
});

it('el usuario creado para un RFC se liga después al docente con ese RFC, sin conflicto', function () {
    app(SyncStaffMember::class)->handle('DOCE800101AA1', 'Doc Ente', null, StaffType::Docente);
    $teacher = Teacher::factory()->create(['rfc' => 'DOCE800101AA1']);

    expect(app(SyncTeacherAccess::class)->handle($teacher))->toBe(AccessSync::Linked)
        ->and($teacher->fresh()->user->username)->toBe('DOCE800101AA1');
});
