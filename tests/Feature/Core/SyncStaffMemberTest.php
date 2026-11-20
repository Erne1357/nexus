<?php

use App\Modules\Core\Actions\SyncStaffMember;
use App\Modules\Core\Enums\StaffType;
use App\Modules\Core\Models\User;
use App\Modules\Faculty\Actions\SyncTeacherAccess;
use App\Modules\Faculty\Enums\AccessSync;
use App\Modules\Faculty\Models\Teacher;
use Illuminate\Support\Facades\DB;
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

/**
 * Simula a otra sesión (la cola de Faculty) que da de alta al usuario justo antes del insert de CreateStaffUser.
 */
function otraSesionCreaAlUsuario(string $username, ?string $email = null): void
{
    $done = false;

    DB::listen(function ($query) use (&$done, $username, $email): void {
        if ($done || ! str_contains($query->sql, 'for update') || ! str_contains($query->sql, 'from "users"') || ! str_contains($query->sql, '"username" =')) {
            return;
        }

        $done = true;
        DB::table('users')->insert([
            'username' => $username, 'name' => 'Creado por la cola', 'email' => $email,
            'is_active' => true, 'must_change_password' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
    });
}

it('si otra sesión crea al usuario entre la revisión y el alta, lo lee y le pone el tipo (carrera con la cola)', function () {
    otraSesionCreaAlUsuario('CARR800101AA1');

    $user = app(SyncStaffMember::class)->handle('CARR800101AA1', 'Ana Ruiz', null, StaffType::Docente);

    expect(User::where('username', 'CARR800101AA1')->count())->toBe(1)
        ->and($user->name)->toBe('Creado por la cola')
        ->and($user->fresh()->staff_type)->toBe(StaffType::Docente);
});

it('si el alta choca por el correo, crea al usuario sin correo en vez de abortar', function () {
    // El correo se libera para la revisión previa (freeEmail) y lo toma otra sesión después.
    $email = 'choque@cdjuarez.tecnm.mx';
    $other = null;
    User::creating(function (User $user) use ($email, &$other): void {
        if ($other === null && $user->username === 'CORR800101AA1') {
            $other = true;
            DB::table('users')->insert([
                'username' => 'AJENO800101AA1', 'name' => 'Ajeno', 'email' => $email,
                'is_active' => true, 'must_change_password' => true, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    });

    $user = app(SyncStaffMember::class)->handle('CORR800101AA1', 'Ana Ruiz', $email, StaffType::Administrativo);

    expect($user->username)->toBe('CORR800101AA1')
        ->and($user->email)->toBeNull()
        ->and($user->fresh()->staff_type)->toBe(StaffType::Administrativo);
});

it('si otra sesión crea al usuario justo antes del alta, SyncTeacherAccess lo liga (carrera con la importación)', function () {
    $teacher = Teacher::factory()->create(['rfc' => 'LIGA800101AA1', 'is_active' => true, 'user_id' => null]);
    otraSesionCreaAlUsuario('LIGA800101AA1');

    $result = app(SyncTeacherAccess::class)->handle($teacher);

    expect($result)->toBe(AccessSync::Linked)
        ->and($teacher->fresh()->user->username)->toBe('LIGA800101AA1')
        ->and(User::where('username', 'LIGA800101AA1')->count())->toBe(1);
});
