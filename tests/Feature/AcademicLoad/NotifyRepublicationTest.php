<?php

use App\Modules\Academic\Models\Career;
use App\Modules\AcademicLoad\Actions\Transitions\NotifyRepublication;
use App\Modules\AcademicLoad\Database\Factories\LoadSnapshotFactory;
use App\Modules\AcademicLoad\Enums\LoadStatus;
use App\Modules\AcademicLoad\Enums\RepublicationAudience;
use App\Modules\AcademicLoad\Enums\SnapshotReason;
use App\Modules\AcademicLoad\Models\AcademicLoad;
use App\Modules\AcademicLoad\Models\LoadSnapshot;
use App\Modules\AcademicLoad\Notifications\LoadRepublished;
use App\Modules\AcademicLoad\Rules\SnapshotDiff;
use App\Modules\Core\Authorization\OrgTree;
use App\Modules\Core\Enums\OrgUnitKind;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\OrgUnit;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\User;
use App\Modules\Faculty\Models\Teacher;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;

/*
| RA-05 y RA-06 (confirmados el 17 de noviembre de 2026; tarea 8.4): al republicar, Nexus avisa solo a quien le cambió
| algo, por la campana y el correo institucional: a cada docente afectado (con exactamente lo que cambió para él, también
| al que no tiene usuario, por correo), a los coordinadores de las carreras afectadas y a los jefes de otros departamentos
| cuando se tocan sus préstamos de salón o sus grupos compartidos. No avisa a Servicios Escolares, a Subdirección, al jefe
| de la carga ni a quien no le cambió nada. NotifyRepublication lee el diff guardado de la versión.
*/

beforeEach(function () {
    config(['nexus.institutional_email_domain' => 'cdjuarez.tecnm.mx']);

    $this->load = AcademicLoad::factory()->inStatus(LoadStatus::Published)->create([
        'term_id' => Term::factory()->create(['code' => '20271'])->id,
        'modality_id' => modalityOf('PRES')->id,
        'department_id' => departmentKey(10),
    ]);
    $this->publisher = User::factory()->create();

    $this->isc = Career::factory()->create(['code' => 'ISC', 'department_id' => departmentKey(10)]);
    $this->ige = Career::factory()->create(['code' => 'IGE', 'department_id' => departmentKey(12)]);
    $this->quiet = Career::factory()->create(['code' => 'IIA', 'department_id' => departmentKey(10)]);

    $this->movedUser = User::factory()->create(['name' => 'Ana Ruiz', 'email' => 'ana.ruiz@cdjuarez.tecnm.mx']);
    $this->moved = Teacher::factory()->create(['user_id' => $this->movedUser->id, 'name' => 'Ana Ruiz']);
    $this->keptUser = User::factory()->create(['email' => 'kept@cdjuarez.tecnm.mx']);
    $this->kept = Teacher::factory()->create(['user_id' => $this->keptUser->id]);
    $this->inactiveUser = User::factory()->create(['is_active' => false, 'email' => 'baja@cdjuarez.tecnm.mx']);
    $this->inactive = Teacher::factory()->create(['user_id' => $this->inactiveUser->id]);
    $this->unreachable = Teacher::factory()->create(['institutional_email' => null, 'email' => 'personal@gmail.com']);
    $this->mailOnly = Teacher::factory()->create(['name' => 'Jesús Delgado', 'institutional_email' => 'Jesus.Delgado@cdjuarez.tecnm.mx']);

    $this->iscCoordinator = User::factory()->withRole(Role::Coordinador, ['career_id' => $this->isc->id])->create(['email' => 'coord.isc@cdjuarez.tecnm.mx']);
    $this->igeCoordinator = User::factory()->withRole(Role::Coordinador, ['career_id' => $this->ige->id])->create();
    $this->quietCoordinator = User::factory()->withRole(Role::Coordinador, ['career_id' => $this->quiet->id])->create();
    $this->ownHead = User::factory()->withRole(Role::JefeDepartamento, ['department_id' => departmentKey(10)])->create();
    $this->roomOwnerHead = User::factory()->withRole(Role::JefeDepartamento, ['department_id' => departmentKey(11)])->create();
    $this->sharedHead = User::factory()->withRole(Role::JefeDepartamento, ['department_id' => departmentKey(12)])->create();
    $this->quietHead = User::factory()->withRole(Role::JefeDepartamento, ['department_id' => departmentKey(13)])->create();
    $this->subdireccion = User::factory()->withRole(Role::Subdireccion)->create();
    $services = OrgUnit::factory()->create(['code' => OrgTree::SCHOOL_SERVICES, 'name' => 'Departamento de Servicios Escolares', 'kind' => OrgUnitKind::Departamento, 'parent_id' => OrgUnit::query()->where('code', OrgTree::ACADEMIC)->sole()->id]);
    $this->servicesHead = User::factory()->withRole(Role::JefeDepartamento, ['org_unit_id' => $services->id])->create();
});

/**
 * @return array{id: string, rfc: string, name: string}
 */
function republicationTeacher(Teacher $teacher): array
{
    return ['id' => $teacher->id, 'rfc' => $teacher->rfc, 'name' => $teacher->name];
}

/**
 * @return array<string, mixed>
 */
function republicationPlacement(Career $career, int $seats, bool $owner = true): array
{
    return LoadSnapshotFactory::placement(['career' => ['id' => $career->id, 'code' => $career->code, 'name' => $career->name], 'seats' => $seats, 'is_owner' => $owner]);
}

/**
 * Publica la versión 1 y la 2 de la carga con estos grupos y guarda en la 2 su diff, como lo hace PublishLoad.
 *
 * @param  list<array<string, mixed>>  $before
 * @param  list<array<string, mixed>>  $after
 */
function republicationVersions(array $before, array $after): LoadSnapshot
{
    $load = test()->load;
    $first = LoadSnapshotFactory::payload($load, 1, test()->publisher, $before);
    $second = LoadSnapshotFactory::payload($load, 2, test()->publisher, $after);
    $departments = Career::query()->whereKey(SnapshotDiff::careerIds($first, $second))->pluck('department_id', 'id')->all();

    LoadSnapshot::factory()->for($load)->create(['version' => 1, 'created_by' => test()->publisher->id, 'payload' => $first, 'reason' => SnapshotReason::Published]);

    return LoadSnapshot::factory()->for($load)->create([
        'version' => 2,
        'created_by' => test()->publisher->id,
        'payload' => $second,
        'diff' => SnapshotDiff::between($first, $second, $departments),
        'reason' => SnapshotReason::Reanalysis,
    ]);
}

/**
 * Cinco grupos de Sistemas: uno que cambia de horario (Ruiz), uno sin cambios (de otra carrera), uno compartido con IGE
 * que cambia de cupo (docente con la cuenta dada de baja), uno con sesión en un salón prestado por el departamento 11 que
 * se mueve (docente sin usuario ni correo institucional) y uno que se cancela (docente sin usuario, con correo
 * institucional).
 */
function republicationScenario(): LoadSnapshot
{
    $session = fn (int $day, ?string $owner = null): array => LoadSnapshotFactory::session(['day' => $day, 'room' => [...LoadSnapshotFactory::session()['room'], 'id' => fakeKey(900 + (int) ($owner !== null)), 'code' => $owner === null ? 'A-101' : 'LC-2', 'owner_department_id' => $owner ?? departmentKey(10)]]);
    $group = fn (int $key, string $code, Teacher $teacher, array $placements, array $sessions, int $capacity = 30): array => LoadSnapshotFactory::group([
        'id' => fakeKey($key), 'code' => $code, 'capacity' => $capacity, 'teacher' => republicationTeacher($teacher), 'placements' => $placements, 'sessions' => $sessions,
    ]);

    $before = [
        $group(1, 'A', test()->moved, [republicationPlacement(test()->isc, 30)], [$session(1)]),
        $group(2, 'B', test()->kept, [republicationPlacement(test()->quiet, 30)], [$session(2)]),
        $group(3, 'C', test()->inactive, [republicationPlacement(test()->isc, 20), republicationPlacement(test()->ige, 10, false)], [$session(3)]),
        $group(4, 'D', test()->unreachable, [republicationPlacement(test()->isc, 30)], [$session(2, departmentKey(11))]),
        $group(5, 'E', test()->mailOnly, [republicationPlacement(test()->isc, 30)], [$session(5)]),
    ];
    $after = [
        array_replace($before[0], ['sessions' => [$session(3)]]),
        $before[1],
        array_replace($before[2], ['capacity' => 35, 'placements' => [republicationPlacement(test()->isc, 20), republicationPlacement(test()->ige, 15, false)]]),
        array_replace($before[3], ['sessions' => [$session(4, departmentKey(11))]]),
    ];

    return republicationVersions($before, $after);
}

it('RA-05: avisa exactamente a los docentes afectados, los coordinadores de las carreras afectadas y los jefes de los otros departamentos tocados', function () {
    Notification::fake();
    republicationScenario();

    $sent = app(NotifyRepublication::class)->handle($this->load, 2);

    expect($sent)->toBe(7);
    Notification::assertSentTo([$this->movedUser, $this->inactiveUser, $this->iscCoordinator, $this->igeCoordinator, $this->roomOwnerHead, $this->sharedHead], LoadRepublished::class);
    Notification::assertSentOnDemandTimes(LoadRepublished::class, 1);
    Notification::assertNotSentTo([$this->keptUser, $this->quietCoordinator, $this->ownHead, $this->quietHead, $this->subdireccion, $this->servicesHead, $this->publisher], LoadRepublished::class);
});

it('RA-05: el docente recibe exactamente lo que cambió para él y lo lleva a su semana publicada', function () {
    Notification::fake();
    republicationScenario();

    app(NotifyRepublication::class)->handle($this->load, 2);

    Notification::assertSentTo($this->movedUser, LoadRepublished::class, function (LoadRepublished $notice) {
        $message = $notice->message($this->movedUser);

        return count($notice->lines()) === 1
            && str_ends_with($notice->lines()[0], ', grupo A (C2): horario de lunes 7:00 a 9:00 a miércoles 7:00 a 9:00.')
            && $message->route === 'published.teacher'
            && $message->parameters === ['periodo' => '20271', 'modalidad' => 'pres', 'docente' => $this->moved->id]
            && $message->modalityId === $this->load->modality_id
            && $message->termId === $this->load->term_id;
    });
});

it('RA-05: el coordinador recibe solo los grupos con lugares de su carrera y el jefe de otro departamento solo los que lo tocan', function () {
    Notification::fake();
    republicationScenario();

    app(NotifyRepublication::class)->handle($this->load, 2);
    $codes = fn (LoadRepublished $notice): array => array_map(fn (string $line): string => preg_match('/grupo ([A-Z])/', $line, $match) === 1 ? $match[1] : '?', $notice->lines());
    $department = $this->load->department->name;

    Notification::assertSentTo($this->iscCoordinator, LoadRepublished::class, fn (LoadRepublished $notice): bool => $codes($notice) === ['E', 'A', 'C', 'D']
        && $notice->message($this->iscCoordinator)->title === "La carga de {$department} cambió grupos de ISC"
        && $notice->message($this->iscCoordinator)->route === 'published.semester'
        && $notice->message($this->iscCoordinator)->parameters['carrera'] === 'ISC');
    Notification::assertSentTo($this->igeCoordinator, LoadRepublished::class, fn (LoadRepublished $notice): bool => $codes($notice) === ['C']);
    Notification::assertSentTo($this->roomOwnerHead, LoadRepublished::class, fn (LoadRepublished $notice): bool => $codes($notice) === ['D']
        && $notice->message($this->roomOwnerHead)->route === 'published.index'
        && str_starts_with($notice->message($this->roomOwnerHead)->title, "La carga de {$department} cambió préstamos de salón o grupos compartidos de "));
    Notification::assertSentTo($this->sharedHead, LoadRepublished::class, fn (LoadRepublished $notice): bool => $codes($notice) === ['C']
        && str_contains($notice->lines()[0], 'lugares por carrera de ISC 20, IGE 10 a ISC 20, IGE 15'));
});

it('RA-06: va por la campana y el correo institucional; sin correo o con la cuenta dada de baja, solo la campana', function () {
    Notification::fake();
    republicationScenario();

    app(NotifyRepublication::class)->handle($this->load, 2);

    Notification::assertSentTo($this->movedUser, LoadRepublished::class, fn (LoadRepublished $notice, array $channels): bool => $channels === ['database', 'mail']);
    Notification::assertSentTo($this->iscCoordinator, LoadRepublished::class, fn (LoadRepublished $notice, array $channels): bool => $channels === ['database', 'mail']);
    Notification::assertSentTo($this->igeCoordinator, LoadRepublished::class, fn (LoadRepublished $notice, array $channels): bool => $channels === ['database']);
    Notification::assertSentTo($this->inactiveUser, LoadRepublished::class, fn (LoadRepublished $notice, array $channels): bool => $channels === ['database']);
});

it('RA-06: el docente sin usuario recibe el correo en su institucional; sin correo institucional no recibe nada', function () {
    Notification::fake();
    republicationScenario();

    app(NotifyRepublication::class)->handle($this->load, 2);

    Notification::assertSentOnDemand(LoadRepublished::class, fn (LoadRepublished $notice, array $channels, AnonymousNotifiable $notifiable): bool => $notifiable->routes === ['mail' => 'jesus.delgado@cdjuarez.tecnm.mx']
        && $channels === ['mail']
        && count($notice->lines()) === 1
        && str_starts_with($notice->lines()[0], 'Se canceló ')
        && $notice->toMail($notifiable)->greeting === 'Hola, Jesús Delgado');
    Notification::assertSentOnDemandTimes(LoadRepublished::class, 1);
});

it('RA-06: el correo dice la versión, el motivo, lo que cambió y lleva a lo publicado', function () {
    republicationScenario();
    $notice = new LoadRepublished($this->load, 2, SnapshotReason::Reanalysis, RepublicationAudience::Teacher, 'Ana Ruiz', SnapshotDiff::teachers(LoadSnapshot::query()->where('version', 2)->sole()->diff)[$this->moved->id]['entries'], 'published.teacher', ['periodo' => '20271', 'modalidad' => 'pres', 'docente' => $this->moved->id]);

    $mail = $notice->toMail($this->movedUser);

    expect($mail->subject)->toBe('Cambiaron tus grupos en la carga de '.$this->load->department->name)
        ->and($mail->introLines[0])->toBe("Se publicó la versión 2 de la carga de {$this->load->department->name} en Presencial, {$this->load->term->name}, después de su re-análisis.")
        ->and($mail->introLines[1])->toBe('Esto cambió para ti:')
        ->and($mail->introLines[2])->toEndWith('horario de lunes 7:00 a 9:00 a miércoles 7:00 a 9:00.')
        ->and($mail->actionUrl)->toBe(route('published.teacher', ['periodo' => '20271', 'modalidad' => 'pres', 'docente' => $this->moved->id]));
});

it('no avisa en la versión 1, sin diff guardado ni cuando la versión no cambió nada', function () {
    Notification::fake();
    $group = LoadSnapshotFactory::group(['teacher' => republicationTeacher($this->moved), 'placements' => [republicationPlacement($this->isc, 30)]]);
    republicationVersions([$group], [$group]);

    expect(app(NotifyRepublication::class)->handle($this->load, 1))->toBe(0)
        ->and(app(NotifyRepublication::class)->handle($this->load, 2))->toBe(0)
        ->and(app(NotifyRepublication::class)->handle($this->load, 3))->toBe(0);

    LoadSnapshot::factory()->for($this->load)->create(['version' => 3]);

    expect(app(NotifyRepublication::class)->handle($this->load, 3))->toBe(0);
    Notification::assertNothingSent();
});

it('RA-06: a una ficha de docente dada de baja no se le manda nada, ni campana ni correo, aunque tenga usuario o correo institucional (supuesto por confirmar)', function () {
    Notification::fake();
    $this->moved->update(['is_active' => false]);
    $this->mailOnly->update(['is_active' => false]);
    republicationScenario();

    $sent = app(NotifyRepublication::class)->handle($this->load, 2);

    expect($sent)->toBe(5);
    Notification::assertNotSentTo($this->movedUser, LoadRepublished::class);
    Notification::assertSentOnDemandTimes(LoadRepublished::class, 0);
    Notification::assertSentTo([$this->inactiveUser, $this->iscCoordinator, $this->igeCoordinator, $this->roomOwnerHead, $this->sharedHead], LoadRepublished::class);
});

it('RA-05: las líneas que recibe el coordinador dicen el campus de cada grupo', function () {
    Notification::fake();
    republicationScenario();

    app(NotifyRepublication::class)->handle($this->load, 2);

    Notification::assertSentTo($this->iscCoordinator, LoadRepublished::class, function (LoadRepublished $notice): bool {
        return count($notice->lines()) === 4
            && collect($notice->lines())->every(fn (string $line): bool => str_contains($line, ' (C2)'));
    });
});

it('RA-05: quien es docente afectado y coordinador de una carrera afectada recibe un aviso por cada papel, y no dos del mismo', function () {
    Notification::fake();
    $both = User::factory()->withRole(Role::Coordinador, ['career_id' => $this->isc->id])->create(['email' => 'ambos@cdjuarez.tecnm.mx']);
    $this->moved->update(['user_id' => $both->id]);
    republicationScenario();

    app(NotifyRepublication::class)->handle($this->load, 2);

    $titles = Notification::sent($both, LoadRepublished::class)->map(fn (LoadRepublished $notice): string => $notice->message($both)->title)->all();

    expect($titles)->toHaveCount(2)
        ->and($titles)->toContain('Cambiaron tus grupos en la carga de '.$this->load->department->name)
        ->and($titles)->toContain('La carga de '.$this->load->department->name.' cambió grupos de ISC');
});

it('RA-05: el aviso al coordinador abre el semestre del primer cambio que se ve en la consulta pública, no el de un cambio solo de cupos (ensayo 8.11, G-06)', function () {
    Notification::fake();
    $placement = fn (int $semester, int $seats): array => LoadSnapshotFactory::placement([
        'career' => ['id' => $this->isc->id, 'code' => $this->isc->code, 'name' => $this->isc->name],
        'study_plan' => ['id' => fakeKey(800 + $semester), 'code' => 'ISIC-2010-224'],
        'semester' => $semester,
        'seats' => $seats,
    ]);
    $quotaOnly = LoadSnapshotFactory::group(['id' => fakeKey(1), 'code' => 'A', 'teacher' => republicationTeacher($this->kept), 'placements' => [$placement(1, 30)], 'sessions' => [LoadSnapshotFactory::session(['day' => 1])]]);
    $scheduled = LoadSnapshotFactory::group(['id' => fakeKey(2), 'code' => 'B', 'teacher' => republicationTeacher($this->kept), 'placements' => [$placement(3, 30)], 'sessions' => [LoadSnapshotFactory::session(['day' => 2])]]);

    republicationVersions([$quotaOnly, $scheduled], [
        array_replace($quotaOnly, ['placements' => [$placement(1, 35)]]),
        array_replace($scheduled, ['sessions' => [LoadSnapshotFactory::session(['day' => 5])]]),
    ]);

    app(NotifyRepublication::class)->handle($this->load, 2);

    Notification::assertSentTo($this->iscCoordinator, LoadRepublished::class, fn (LoadRepublished $notice): bool => $notice->message($this->iscCoordinator)->parameters === ['periodo' => '20271', 'modalidad' => 'pres', 'carrera' => 'ISC', 'reticula' => 'ISIC-2010-224', 'semestre' => '3']);
});

it('RA-05: el aviso al coordinador abre el semestre y la retícula del primer grupo cambiado de su carrera, también si se canceló (ensayo 8.11, E-15)', function () {
    Notification::fake();
    $placement = fn (Career $career, int $semester, string $plan): array => LoadSnapshotFactory::placement([
        'career' => ['id' => $career->id, 'code' => $career->code, 'name' => $career->name],
        'study_plan' => ['id' => fakeKey(800 + $semester), 'code' => $plan],
        'semester' => $semester,
        'seats' => 30,
    ]);
    $group = fn (int $key, string $code, array $placements, int $day): array => LoadSnapshotFactory::group([
        'id' => fakeKey($key), 'code' => $code, 'teacher' => republicationTeacher($this->kept), 'placements' => $placements, 'sessions' => [LoadSnapshotFactory::session(['day' => $day])],
    ]);
    $first = $group(1, 'A', [$placement($this->isc, 1, 'ISIC-2010-224')], 1);
    $third = $group(2, 'B', [$placement($this->isc, 3, 'ISIC-2010-224')], 2);
    $cancelled = $group(3, 'C', [$placement($this->ige, 5, 'IGEM-2009-201')], 3);
    $kept = $group(4, 'D', [$placement($this->ige, 2, 'IGEM-2009-201')], 4);

    republicationVersions([$first, $third, $cancelled, $kept], [$first, array_replace($third, ['sessions' => [LoadSnapshotFactory::session(['day' => 5])]]), $kept]);

    app(NotifyRepublication::class)->handle($this->load, 2);

    Notification::assertSentTo($this->iscCoordinator, LoadRepublished::class, fn (LoadRepublished $notice): bool => $notice->message($this->iscCoordinator)->route === 'published.semester'
        && $notice->message($this->iscCoordinator)->parameters === ['periodo' => '20271', 'modalidad' => 'pres', 'carrera' => 'ISC', 'reticula' => 'ISIC-2010-224', 'semestre' => '3']);
    Notification::assertSentTo($this->igeCoordinator, LoadRepublished::class, fn (LoadRepublished $notice): bool => $notice->message($this->igeCoordinator)->parameters === ['periodo' => '20271', 'modalidad' => 'pres', 'carrera' => 'IGE', 'reticula' => 'IGEM-2009-201', 'semestre' => '5']
        && str_contains(route($notice->message($this->igeCoordinator)->route, $notice->message($this->igeCoordinator)->parameters), '/horarios/20271/pres/carrera/IGE?reticula=IGEM-2009-201&semestre=5'));
});
