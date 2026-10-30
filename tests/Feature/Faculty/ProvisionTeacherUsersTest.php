<?php

use App\Modules\Academic\Models\Department;
use App\Modules\Academic\Models\DepartmentHead;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\RoleScope;
use App\Modules\Core\Models\User;
use App\Modules\Faculty\Actions\ProvisionTeacherUsers;
use App\Modules\Faculty\Jobs\ProvisionTeacherUsersJob;
use App\Modules\Faculty\Models\Teacher;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Queue;
use Spatie\Activitylog\Models\Activity;

/*
| ProvisionTeacherUsers corre SyncTeacherAccess sobre todos los docentes, por bloques (a los borrados les aplica la
| baja si falta; supuesto por confirmar), con un solo hash de la contraseña por defecto por corrida, y deja una entrada de bitácora de la corrida con las cuentas,
| además de las de cada docente que cambió. Sin NEXUS_DEFAULT_PASSWORD falla antes de tocar a nadie. La despacha
| el job ProvisionTeacherUsersJob, único en la cola.
*/

beforeEach(function () {
    $this->campus = Campus::factory()->create(['code' => 'C2']);
    $this->department = Department::factory()->create();
});

/**
 * 3 docentes activos sin usuario, 1 inactivo, 1 borrado cuya baja ya se aplicó (su usuario está inactivo) y 1
 * activo cuyo número es el usuario de ese docente borrado.
 */
function teachersToProvision(Department $department): Teacher
{
    Teacher::factory()->count(3)->adscribedTo($department)->create();
    Teacher::factory()->inactive()->adscribedTo($department)->create();

    $taken = User::factory()->create(['username' => '7654321', 'is_active' => false]);
    Teacher::factory()->create(['user_id' => $taken->id])->delete();

    return Teacher::factory()->adscribedTo($department)->create(['employee_number' => '7654321']);
}

it('con 3 activos, 1 inactivo, 1 borrado y 1 conflicto devuelve las cuentas', function () {
    teachersToProvision($this->department);

    expect(app(ProvisionTeacherUsers::class)->handle())->toBe([
        'created' => 3,
        'linked' => 0,
        'updated' => 0,
        'unchanged' => 2,
        'conflicts' => ['7654321'],
    ]);

    $created = User::where('username', '!=', '7654321')->get();
    $run = Activity::where('event', 'provisioned')->sole();

    expect($created)->toHaveCount(3)
        ->and($created->pluck('password')->unique())->toHaveCount(1)
        ->and($created->every(fn (User $user): bool => $user->must_change_password && $user->is_active))->toBeTrue()
        ->and(RoleScope::where('role', Role::Docente)->whereNull('campus_id')->whereIn('user_id', $created->pluck('id'))->count())->toBe(3)
        ->and(RoleScope::whereNotNull('campus_id')->exists())->toBeFalse()
        ->and($run->subject)->toBeNull()
        ->and($run->description)->toBe('Sincronizó el acceso de 6 docentes: 3 usuarios creados, 0 ligados, 0 actualizados, 2 sin cambios y 1 en conflicto')
        ->and($run->properties->all())->toBe([
            'created' => 3,
            'linked' => 0,
            'updated' => 0,
            'unchanged' => 2,
            'conflicts' => ['7654321'],
        ]);
});

it('una segunda corrida no cambia nada y solo deja su propia entrada', function () {
    teachersToProvision($this->department);
    app(ProvisionTeacherUsers::class)->handle();
    $entries = Activity::count();

    expect(app(ProvisionTeacherUsers::class)->handle())->toBe([
        'created' => 0,
        'linked' => 0,
        'updated' => 0,
        'unchanged' => 5,
        'conflicts' => ['7654321'],
    ])->and(Activity::count())->toBe($entries + 1);
});

it('aplica la baja a un docente borrado cuyo usuario seguía activo, y repetirla no cambia nada', function () {
    $user = User::factory()->create(['username' => '3333333']);
    $teacher = Teacher::factory()->adscribedTo($this->department)->create(['employee_number' => '3333333', 'user_id' => $user->id]);
    $head = DepartmentHead::factory()->create([
        'department_id' => $this->department->id,
        'campus_id' => $this->campus->id,
        'user_id' => $user->id,
    ]);
    $teacher->delete();

    expect(app(ProvisionTeacherUsers::class)->handle())->toMatchArray(['updated' => 1, 'unchanged' => 0])
        ->and($user->fresh()->is_active)->toBeFalse()
        ->and($head->fresh()->valid_to->toDateString())->toBe(today()->subDay()->toDateString());

    expect(app(ProvisionTeacherUsers::class)->handle())->toMatchArray(['updated' => 0, 'unchanged' => 1]);
});

it('no da usuario a un docente borrado', function () {
    $teacher = Teacher::factory()->create(['employee_number' => '1111111']);
    $teacher->delete();

    app(ProvisionTeacherUsers::class)->handle();

    expect(User::where('username', '1111111')->exists())->toBeFalse();
});

it('sin NEXUS_DEFAULT_PASSWORD lanza y no crea a nadie', function () {
    config(['auth.default_password' => null]);
    teachersToProvision($this->department);
    $users = User::count();

    expect(fn () => app(ProvisionTeacherUsers::class)->handle())
        ->toThrow(RuntimeException::class, 'NEXUS_DEFAULT_PASSWORD');

    expect(User::count())->toBe($users)
        ->and(Teacher::whereNotNull('user_id')->exists())->toBeFalse()
        ->and(Activity::count())->toBe(0);
});

it('deja la corrida y los cambios de cada docente a nombre de quien la pidió', function () {
    $admin = User::factory()->admin()->create();
    Teacher::factory()->adscribedTo($this->department)->create();

    app(ProvisionTeacherUsers::class)->handle($admin);

    expect(Activity::count())->toBeGreaterThan(1)
        ->and(Activity::all()->every(fn (Activity $entry): bool => $entry->causer?->is($admin) === true))->toBeTrue();
});

it('el job corre la provisión en la cola', function () {
    Teacher::factory()->adscribedTo($this->department)->create(['employee_number' => '2222222']);

    ProvisionTeacherUsersJob::dispatch();

    $job = new ProvisionTeacherUsersJob;
    $middleware = $job->middleware();

    expect(User::where('username', '2222222')->exists())->toBeTrue()
        ->and($job)->toBeInstanceOf(ShouldQueue::class)
        ->and($job)->toBeInstanceOf(ShouldBeUnique::class)
        ->and($job)
        ->maxExceptions->toBe(1)
        ->timeout->toBe(1800)
        ->uniqueFor->toBe(3600)
        ->and($middleware)->toHaveCount(1)
        ->and($middleware[0])->toBeInstanceOf(WithoutOverlapping::class);
});

it('el job es único: espera una sola provisión en la cola', function () {
    Queue::fake();

    ProvisionTeacherUsersJob::dispatch();
    ProvisionTeacherUsersJob::dispatch();

    Queue::assertPushed(ProvisionTeacherUsersJob::class, 1);
});
