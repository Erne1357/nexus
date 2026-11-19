<?php

use App\Modules\Academic\Models\Career;
use App\Modules\Academic\Models\CareerCampus;
use App\Modules\Academic\Models\Subject;
use App\Modules\AcademicLoad\Actions\Transitions\PublishLoad;
use App\Modules\AcademicLoad\Actions\Transitions\TransitionLoad;
use App\Modules\AcademicLoad\Enums\LoadStatus;
use App\Modules\AcademicLoad\Enums\SnapshotReason;
use App\Modules\AcademicLoad\Models\AcademicLoad;
use App\Modules\AcademicLoad\Models\LoadSnapshot;
use App\Modules\AcademicLoad\Models\LoadTransition;
use App\Modules\AcademicLoad\Notifications\LoadRepublished;
use App\Modules\AcademicLoad\Queries\LoadVersionsQuery;
use App\Modules\AcademicLoad\Rules\SnapshotDiff;
use App\Modules\Core\Models\User;
use App\Modules\Facilities\Models\Building;
use App\Modules\Facilities\Models\Room;
use App\Modules\Faculty\Models\Teacher;
use App\Modules\Faculty\Models\TeacherPosition;
use App\Modules\Scheduling\Models\Group;
use App\Modules\Scheduling\Models\GroupQuota;
use App\Modules\Scheduling\Models\GroupSession;
use Illuminate\Database\QueryException;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Spatie\Activitylog\Models\Activity;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mime\Address;

/*
| Tarea 8.4: republicar una carga es cerrarla (con el visto bueno si la modalidad lo exige, RA-03) y publicarla otra vez
| con PublishLoad, que guarda la versión n + 1 con lo que cambió contra la n (`diff`, SnapshotDiff) y de dónde salió
| (`reason`). Después del commit avisa a quien le cambió algo, por la campana y por el correo institucional (RA-05,
| RA-06); una publicación que se revierte no avisa.
*/

beforeEach(function () {
    config(['nexus.institutional_email_domain' => 'cdjuarez.tecnm.mx']);

    $this->load = AcademicLoad::factory()->create(['status' => LoadStatus::Closed, 'department_id' => departmentKey(10)]);
    $this->publisher = User::factory()->create(['name' => 'Laura Méndez']);
    $this->room = Room::factory()->capacity(40)->create(['building_id' => Building::factory()->create(['campus_id' => loadCampus()->id])->id, 'department_id' => departmentKey(10)]);

    $this->teacherUser = User::factory()->create(['name' => 'Ana Ruiz', 'email' => 'ana.ruiz@gmail.com']);
    $this->teacher = republishTeacher(['user_id' => $this->teacherUser->id, 'institutional_email' => 'ana.ruiz@cdjuarez.tecnm.mx']);
    $this->taught = republishGroup(['code' => 'A', 'teacher_id' => $this->teacher->id]);
    $this->session = GroupSession::factory()->for($this->taught)->inRoom($this->room)->on(1, '07:00', '09:00')->create();

    $this->otherUser = User::factory()->create(['name' => 'Jesús Delgado']);
    $this->other = republishTeacher(['user_id' => $this->otherUser->id, 'institutional_email' => 'jesus.delgado@cdjuarez.tecnm.mx']);
    $this->untouched = republishGroup(['code' => 'B', 'teacher_id' => $this->other->id]);
    GroupSession::factory()->for($this->untouched)->inRoom($this->room)->on(2, '07:00', '09:00')->create();
});

/**
 * Un grupo de 2 h de la carga sin choques duros: su carrera se ofrece en el campus (HC-13) y cabe en el salón (HC-05).
 *
 * @param  array<string, mixed>  $attributes
 */
function republishGroup(array $attributes): Group
{
    $group = Group::factory()->withQuota()->create([
        'term_id' => test()->load->term_id,
        'campus_id' => loadCampus()->id,
        'subject_id' => Subject::factory()->create(['department_id' => departmentKey(10), 'theory_hours' => 2, 'practice_hours' => 0])->id,
        'capacity' => 30,
        ...$attributes,
    ]);
    CareerCampus::factory()->create(['career_id' => $group->owner_career_id, 'campus_id' => $group->campus_id]);

    return $group;
}

/**
 * Un docente con horas base para sus grupos (HC-08).
 *
 * @param  array<string, mixed>  $attributes
 */
function republishTeacher(array $attributes = []): Teacher
{
    $teacher = Teacher::factory()->create($attributes);
    TeacherPosition::factory()->asignatura(20)->create(['teacher_id' => $teacher->id]);

    return $teacher;
}

function republish(): LoadSnapshot
{
    return app(PublishLoad::class)->handle(test()->load->fresh(), test()->publisher, 'Carga lista');
}

/**
 * Lleva la carga publicada otra vez a `closed` como lo haría su ciclo, con la transición a cerrada desde `$from`
 * (re-análisis, o asignación después de reabrirla a ajustes).
 */
function republishCycle(LoadStatus $from): void
{
    $admin = User::factory()->admin()->create();
    $transition = app(TransitionLoad::class);

    if ($from === LoadStatus::Reanalysis) {
        $transition->handle(test()->load->fresh(), LoadStatus::Reanalysis, $admin, 'Llegaron las calificaciones', fn (): array => []);
    } else {
        $transition->handle(test()->load->fresh(), LoadStatus::Adjusting, $admin, 'Corrección', fn (): array => []);
        $transition->handle(test()->load->fresh(), LoadStatus::Staffing, $admin, null, fn (): array => []);
    }

    $transition->handle(test()->load->fresh(), LoadStatus::Closed, $admin, null, fn (): array => []);
}

/**
 * A quiénes llegó correo de verdad (transporte `array`, cola `sync`).
 *
 * @return list<string>
 */
function republishMailedTo(): array
{
    return app('mail.manager')->mailer()->getSymfonyTransport()->messages()
        ->flatMap(fn (SentMessage $sent): array => array_map(fn (Address $address): string => $address->getAddress(), $sent->getOriginalMessage()->getTo()))
        ->values()
        ->all();
}

it('la versión 1 se guarda sin diff y con el motivo «publicación»; su bitácora no trae cambios', function () {
    $snapshot = republish();

    expect($snapshot->fresh()->diff)->toBeNull()
        ->and($snapshot->fresh()->reason)->toBe(SnapshotReason::Published)
        ->and(Activity::query()->where('subject_id', $this->load->id)->where('event', 'published')->sole()->properties->all())
        ->toBe(['version' => 1, 'groups' => 2, 'sessions' => 2, 'reason' => 'published']);
});

it('RA-05: republicar tras un re-análisis guarda la versión 2 con su diff contra la 1 y el motivo «re-análisis»', function () {
    republish();
    republishCycle(LoadStatus::Reanalysis);
    $this->session->update(['day' => 3]);
    $this->taught->quotas()->update(['max_seats' => 25]);
    $this->taught->update(['capacity' => 25]);

    $snapshot = republish()->fresh();

    expect($snapshot->version)->toBe(2)
        ->and($snapshot->reason)->toBe(SnapshotReason::Reanalysis)
        ->and($snapshot->diff['version'])->toBe(SnapshotDiff::VERSION)
        ->and($snapshot->diff['groups']['added'])->toBe([])
        ->and($snapshot->diff['groups']['cancelled'])->toBe([])
        ->and($snapshot->diff['groups']['changed'])->toHaveCount(1)
        ->and($snapshot->diff['groups']['changed'][0]['group']['id'])->toBe($this->taught->id)
        ->and(array_keys($snapshot->diff['groups']['changed'][0]['changes']))->toEqualCanonicalizing(['schedule', 'quota', 'career_quotas'])
        ->and($snapshot->diff['summary'])->toEqual(['added' => 0, 'cancelled' => 0, 'changed' => 1, 'teachers_affected' => 1, 'careers_affected' => 1, 'departments_affected' => 0]);

    $entry = Activity::query()->where('subject_id', $this->load->id)->where('event', 'published')->latest('id')->first();
    expect($entry->properties->all())->toMatchArray(['version' => 2, 'reason' => 'reanalysis', 'changes' => $snapshot->diff['summary']])
        ->and($entry->description)->toContain('contra la versión anterior: nuevos 0, cancelados 0, con cambios 1');
});

it('republicar después de reabrir a ajustes guarda el motivo «corrección»', function () {
    republish();
    republishCycle(LoadStatus::Staffing);

    expect(republish()->fresh()->reason)->toBe(SnapshotReason::Correction);
});

it('un ciclo de re-análisis devuelto por Subdirección y cerrado otra vez sigue siendo re-análisis', function () {
    republish();
    republishCycle(LoadStatus::Reanalysis);
    $admin = User::factory()->admin()->create();
    app(TransitionLoad::class)->handle($this->load->fresh(), LoadStatus::Reanalysis, $admin, 'Falta un grupo', fn (): array => []);
    app(TransitionLoad::class)->handle($this->load->fresh(), LoadStatus::Closed, $admin, null, fn (): array => []);

    expect(republish()->fresh()->reason)->toBe(SnapshotReason::Reanalysis)
        ->and(LoadTransition::query()->where('to_status', LoadStatus::Closed)->count())->toBe(2);
});

it('RA-05: el diff guarda el departamento de cada carrera, y un grupo compartido con otra carrera toca a su departamento', function () {
    republish();
    republishCycle(LoadStatus::Reanalysis);
    $foreign = Career::factory()->create(['code' => 'IND', 'department_id' => departmentKey(11)]);
    $this->taught->quotas()->update(['max_seats' => 20]);
    GroupQuota::factory()->create(['group_id' => $this->taught->id, 'career_id' => $foreign->id, 'max_seats' => 10]);

    $group = republish()->fresh()->diff['groups']['changed'][0]['group'];

    expect(collect($group['careers'])->firstWhere('code', 'IND'))->toEqual(['id' => $foreign->id, 'code' => 'IND', 'department_id' => departmentKey(11), 'is_owner' => false])
        ->and($group['other_departments'])->toBe([departmentKey(11)]);
});

it('una versión que no cambió nada guarda un diff vacío y no avisa a nadie', function () {
    republish();
    republishCycle(LoadStatus::Reanalysis);
    Notification::fake();

    $snapshot = republish()->fresh();

    expect(SnapshotDiff::isEmpty($snapshot->diff))->toBeTrue()
        ->and($snapshot->diff['summary']['changed'])->toBe(0);
    Notification::assertNothingSent();
});

it('RA-05 y RA-06: al republicar avisa al docente afectado por la campana y el correo, con lo que le cambió, y no al que no le cambió nada', function () {
    republish();
    republishCycle(LoadStatus::Reanalysis);
    Notification::fake();
    $this->session->update(['day' => 3]);

    republish();

    Notification::assertSentTo($this->teacherUser, LoadRepublished::class, fn (LoadRepublished $notice, array $channels): bool => $channels === ['database', 'mail']
        && count($notice->lines()) === 1
        && str_contains($notice->lines()[0], 'grupo A')
        && str_contains($notice->lines()[0], 'horario de lunes 7:00 a 9:00 a miércoles 7:00 a 9:00')
        && $notice->message($this->teacherUser)->title === 'Cambiaron tus grupos en la carga de '.$this->load->department->name
        && $notice->message($this->teacherUser)->route === 'published.teacher'
        && $notice->message($this->teacherUser)->parameters['docente'] === $this->teacher->id);
    Notification::assertNotSentTo([$this->otherUser, $this->publisher], LoadRepublished::class);
});

it('RA-06: el docente sin usuario pero con correo institucional recibe el aviso por correo', function () {
    $this->other->update(['user_id' => null]);
    republish();
    republishCycle(LoadStatus::Reanalysis);
    Notification::fake();
    $this->untouched->update(['teacher_id' => $this->teacher->id]);

    republish();

    Notification::assertSentOnDemand(LoadRepublished::class, fn (LoadRepublished $notice, array $channels, AnonymousNotifiable $notifiable): bool => $notifiable->routes === ['mail' => 'jesus.delgado@cdjuarez.tecnm.mx']
        && $channels === ['mail']
        && str_starts_with($notice->lines()[0], 'Ya no impartes ')
        && $notice->message($notifiable)->route === 'published.index');
    Notification::assertSentTo($this->teacherUser, LoadRepublished::class, fn (LoadRepublished $notice): bool => str_starts_with($notice->lines()[0], 'Ahora impartes '));
});

it('RA-06: el correo de verdad llega al institucional de la ficha del docente y la campana queda guardada', function () {
    republish();
    republishCycle(LoadStatus::Reanalysis);
    $this->session->update(['day' => 3]);

    republish();

    expect(republishMailedTo())->toBe(['ana.ruiz@cdjuarez.tecnm.mx'])
        ->and($this->teacherUser->notifications()->where('type', LoadRepublished::class)->count())->toBe(1)
        ->and($this->otherUser->notifications()->count())->toBe(0);
});

it('una republicación que se revierte no guarda la versión ni avisa', function () {
    republish();
    republishCycle(LoadStatus::Reanalysis);
    Notification::fake();
    $this->session->update(['day' => 3]);

    expect(fn () => DB::transaction(function (): void {
        republish();

        throw new RuntimeException('Algo falló después de publicar.');
    }))->toThrow(RuntimeException::class);

    expect(LoadSnapshot::query()->where('academic_load_id', $this->load->id)->count())->toBe(1);
    Notification::assertNothingSent();
});

it('la línea de versiones lee el motivo, el resumen y el diff guardados', function () {
    republish();
    republishCycle(LoadStatus::Reanalysis);
    $this->taught->update(['teacher_id' => $this->other->id]);

    $snapshot = republish()->fresh();
    $versions = app(LoadVersionsQuery::class)->versions($this->load->fresh());

    expect($versions[0])->toMatchArray(['version' => 1, 'reason' => SnapshotReason::Published, 'summary' => null])
        ->and($versions[1])->toMatchArray(['version' => 2, 'reason' => SnapshotReason::Reanalysis, 'summary' => $snapshot->diff['summary']])
        ->and(app(LoadVersionsQuery::class)->diff($this->load, 2))->toBe($snapshot->diff)
        ->and(app(LoadVersionsQuery::class)->diff($this->load, 1))->toBeNull()
        ->and(app(LoadVersionsQuery::class)->changes($this->load, 2))->toBe(['added' => 0, 'removed' => 0, 'teachers' => 1, 'rooms' => 0]);
});

it('la base no deja diff en la versión 1 ni un motivo que no existe', function (int $version, array $columns, string $constraint) {
    $snapshot = LoadSnapshot::factory()->create();

    expect(fn () => DB::table('load_snapshots')->insert(['id' => fakeKey(1), 'academic_load_id' => $snapshot->academic_load_id, 'version' => $version, 'payload' => '{}', 'created_by' => $snapshot->created_by, ...$columns]))
        ->toThrow(QueryException::class, $constraint);
})->with([
    'diff en la versión 1' => [1, ['diff' => '{}'], 'load_snapshots_diff_check'],
    'motivo desconocido' => [2, ['reason' => 'initial'], 'load_snapshots_reason_check'],
]);
