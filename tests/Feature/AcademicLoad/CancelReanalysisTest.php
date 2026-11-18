<?php

use App\Modules\Academic\Models\CareerCampus;
use App\Modules\Academic\Models\Subject;
use App\Modules\AcademicLoad\Actions\Transitions\CancelReanalysis;
use App\Modules\AcademicLoad\Actions\Transitions\PublishLoad;
use App\Modules\AcademicLoad\Actions\Transitions\TransitionLoad;
use App\Modules\AcademicLoad\Enums\LoadStatus;
use App\Modules\AcademicLoad\Events\LoadTransitioned;
use App\Modules\AcademicLoad\Models\AcademicLoad;
use App\Modules\AcademicLoad\Models\LoadSnapshot;
use App\Modules\AcademicLoad\Models\LoadTransition;
use App\Modules\AcademicLoad\Queries\ReanalysisChangesQuery;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\User;
use App\Modules\Facilities\Models\Building;
use App\Modules\Facilities\Models\Room;
use App\Modules\Faculty\Models\Teacher;
use App\Modules\Faculty\Models\TeacherPosition;
use App\Modules\Scheduling\Models\Group;
use App\Modules\Scheduling\Models\GroupSession;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;

/*
| Cancelar el re-análisis (tarea 8.1, seguimiento #600; RA-10): `reanalysis` → `published` sin versión nueva, solo mientras
| los grupos de la carga sigan igual que en la última versión publicada (SnapshotDiff). Si el jefe ya cambió algo, se
| rechaza y hay que cerrar y republicar. La guarda de la publicación no deja publicar desde re-análisis.
*/

beforeEach(function () {
    $this->head = null;
    $this->admin = User::factory()->admin()->create();

    $this->load = AcademicLoad::factory()->create(['status' => LoadStatus::Closed, 'department_id' => departmentKey(10)]);
    $room = Room::factory()->capacity(40)->create(['building_id' => Building::factory()->create(['campus_id' => loadCampus()->id])->id, 'department_id' => departmentKey(10)]);

    $this->teacher = cancelReanalysisTeacher();
    $this->taught = cancelReanalysisGroup(['code' => 'A', 'teacher_id' => $this->teacher->id]);
    $this->session = GroupSession::factory()->for($this->taught)->inRoom($room)->on(1, '07:00', '09:00')->create();
    $this->other = cancelReanalysisGroup(['code' => 'B', 'teacher_id' => cancelReanalysisTeacher()->id]);
    GroupSession::factory()->for($this->other)->inRoom($room)->on(2, '07:00', '09:00')->create();

    app(PublishLoad::class)->handle($this->load->fresh(), $this->admin, 'Carga lista');
    app(TransitionLoad::class)->handle($this->load->fresh(), LoadStatus::Reanalysis, $this->admin, 'Llegaron las calificaciones', fn (): array => []);
});

/**
 * @param  array<string, mixed>  $attributes
 */
function cancelReanalysisGroup(array $attributes): Group
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
 * El jefe del departamento 10 (solo hay uno por departamento).
 */
function cancelReanalysisHead(): User
{
    return test()->head ??= User::factory()->withRole(Role::JefeDepartamento, ['department_id' => departmentKey(10)])->create();
}

function cancelReanalysisTeacher(): Teacher
{
    $teacher = Teacher::factory()->create();
    TeacherPosition::factory()->asignatura(20)->create(['teacher_id' => $teacher->id]);

    return $teacher;
}

it('RA-10: sin cambios desde la última versión, cancelar devuelve la carga a publicada sin versión nueva', function () {
    $publishedAt = $this->load->fresh()->published_at;
    $publishedBy = $this->load->fresh()->published_by;
    $load = app(CancelReanalysis::class)->handle($this->load->fresh(), cancelReanalysisHead());

    expect($load->status)->toBe(LoadStatus::Published)
        ->and($this->load->fresh()->status)->toBe(LoadStatus::Published)
        ->and($this->load->fresh()->current_version)->toBe(1)
        ->and($this->load->fresh()->published_at->equalTo($publishedAt))->toBeTrue()
        ->and($this->load->fresh()->published_by)->toBe($publishedBy)
        ->and(LoadSnapshot::query()->where('academic_load_id', $this->load->id)->pluck('version')->all())->toBe([1]);

    $row = LoadTransition::query()->latest('created_at')->latest('id')->first();
    expect($row->from_status)->toBe(LoadStatus::Reanalysis)
        ->and($row->to_status)->toBe(LoadStatus::Published)
        ->and($row->user_id)->toBe(cancelReanalysisHead()->id)
        ->and($row->comment)->toBe('Re-análisis cancelado sin cambios');
    expect(Activity::query()->where('event', 'transitioned')->latest('id')->first()->attribute_changes['attributes'])->toBe(['status' => 'published']);
});

it('RA-10: cancelar el re-análisis no avisa una republicación ni crea otra versión aunque la carga ya tenga la 2', function () {
    $transition = app(TransitionLoad::class);
    $transition->handle($this->load->fresh(), LoadStatus::Closed, $this->admin, null, fn (): array => []);
    $this->session->update(['day' => 3]);
    app(PublishLoad::class)->handle($this->load->fresh(), $this->admin);
    $transition->handle($this->load->fresh(), LoadStatus::Reanalysis, $this->admin, 'Otra vez', fn (): array => []);

    Notification::fake();
    Event::fake([LoadTransitioned::class]);
    app(CancelReanalysis::class)->handle($this->load->fresh(), $this->admin);

    Notification::assertNothingSent();
    Event::assertDispatched(LoadTransitioned::class, fn (LoadTransitioned $event): bool => $event->from === LoadStatus::Reanalysis && $event->to === LoadStatus::Published);
    expect($this->load->fresh()->current_version)->toBe(2)
        ->and(LoadSnapshot::query()->where('academic_load_id', $this->load->id)->count())->toBe(2);
});

it('RA-10: el listener de la republicación no avisa al salir de re-análisis', function () {
    Notification::fake();

    app(CancelReanalysis::class)->handle($this->load->fresh(), $this->admin);

    Notification::assertNothingSent();
});

it('RA-10: la consulta compara el estado actual contra la última versión con SnapshotDiff', function () {
    $query = app(ReanalysisChangesQuery::class);

    expect($query->hasChanges($this->load->fresh()))->toBeFalse()
        ->and($query->changedGroups($this->load->fresh()))->toBe(0)
        ->and($query->since($this->load->fresh())['summary'])->toMatchArray(['added' => 0, 'cancelled' => 0, 'changed' => 0]);

    $this->session->update(['day' => 3]);
    $this->other->update(['teacher_id' => null]);

    expect($query->hasChanges($this->load->fresh()))->toBeTrue()
        ->and($query->changedGroups($this->load->fresh()))->toBe(2)
        ->and($query->since($this->load->fresh())['summary']['changed'])->toBe(2);
});

it('RA-10: con un grupo cambiado, cancelar se rechaza con cuántos y la carga sigue en re-análisis', function () {
    $this->session->update(['day' => 3]);

    expect(fn () => app(CancelReanalysis::class)->handle($this->load->fresh(), cancelReanalysisHead()))->toThrow(
        fn (ValidationException $e) => expect($e->errors()['status'])->toBe(['Ya cambiaste 1 grupo desde la versión 1: ciérralo y publica la versión 2.']),
    );

    expect($this->load->fresh()->status)->toBe(LoadStatus::Reanalysis)
        ->and($this->load->fresh()->current_version)->toBe(1)
        ->and(LoadTransition::query()->where('to_status', LoadStatus::Published)->count())->toBe(1);
});

it('RA-10: cambiar el docente o quitar un grupo cuenta como cambio', function (Closure $change, int $groups) {
    $change($this);

    expect(fn () => app(CancelReanalysis::class)->handle($this->load->fresh(), $this->admin))->toThrow(
        fn (ValidationException $e) => expect($e->errors()['status'][0])->toContain("Ya cambiaste {$groups} grupo"),
    );
})->with([
    'otro docente' => [fn ($t) => $t->taught->update(['teacher_id' => cancelReanalysisTeacher()->id]), 1],
    'grupo cancelado (borrado)' => [function ($t) {
        GroupSession::query()->where('group_id', $t->other->id)->delete();
        $t->other->quotas()->delete();
        $t->other->forceDelete();
    }, 1],
    'grupo nuevo' => [fn ($t) => cancelReanalysisGroup(['code' => 'C']), 1],
    'dos grupos' => [function ($t) {
        $t->taught->update(['teacher_id' => null]);
        $t->other->update(['teacher_id' => null]);
    }, 2],
]);

it('RA-10: lo que se editó y se revirtió no cuenta: sin diferencias contra la versión, se cancela', function () {
    $this->session->update(['day' => 3]);
    $this->session->update(['day' => 1]);

    app(CancelReanalysis::class)->handle($this->load->fresh(), cancelReanalysisHead());

    expect($this->load->fresh()->status)->toBe(LoadStatus::Published);
});

it('RA-10: solo el jefe del departamento de la carga y el admin cancelan; el resto recibe 403 al llamar la Action', function (?Role $role, ?int $department, bool $allowed) {
    $user = match (true) {
        $role === null => User::factory()->admin()->create(),
        $role === Role::JefeDepartamento && $department === 10 => cancelReanalysisHead(),
        default => User::factory()->withRole($role, array_filter([
            'department_id' => $department === null ? null : departmentKey($department),
            'career_id' => $role === Role::Coordinador ? careerKey(20) : null,
        ]))->create(),
    };

    expect($user->can('cancelReanalysis', $this->load))->toBe($allowed);

    if ($allowed) {
        app(CancelReanalysis::class)->handle($this->load->fresh(), $user);
        expect($this->load->fresh()->status)->toBe(LoadStatus::Published);

        return;
    }

    expect(fn () => app(CancelReanalysis::class)->handle($this->load->fresh(), $user))->toThrow(AuthorizationException::class);
    expect($this->load->fresh()->status)->toBe(LoadStatus::Reanalysis);
})->with([
    'admin' => [null, null, true],
    'jefe del departamento' => [Role::JefeDepartamento, 10, true],
    'jefe de otro departamento' => [Role::JefeDepartamento, 11, false],
    'coordinador' => [Role::Coordinador, null, false],
    'docente' => [Role::Docente, null, false],
    'subdirección' => [Role::Subdireccion, null, false],
]);

it('RA-10: solo se cancela un re-análisis en curso: con el modelo viejo o en otro estado se rechaza', function () {
    $stale = $this->load->fresh();
    app(CancelReanalysis::class)->handle($this->load->fresh(), cancelReanalysisHead());

    expect(fn () => app(CancelReanalysis::class)->handle($stale, cancelReanalysisHead()))
        ->toThrow(ValidationException::class, 'La carga está en Publicada: no puede pasar a Publicada.');

    foreach ([LoadStatus::Closed, LoadStatus::Approved] as $status) {
        $this->load->fresh()->update(['status' => $status]);

        expect(fn () => app(CancelReanalysis::class)->handle($this->load->fresh(), cancelReanalysisHead()))->toThrow(
            fn (ValidationException $e) => expect($e->errors()['status'])->toBe(["La carga está en {$status->label()}: solo se cancela un re-análisis en curso."]),
        );
    }
});

it('RA-10: publicar desde re-análisis sigue rechazado: la flecha es solo de cancelar', function () {
    expect(fn () => app(PublishLoad::class)->handle($this->load->fresh(), $this->admin))->toThrow(
        ValidationException::class, 'La carga está en Re-análisis: no puede pasar a Publicada',
    );

    expect($this->load->fresh()->status)->toBe(LoadStatus::Reanalysis)
        ->and($this->load->fresh()->current_version)->toBe(1)
        ->and(LoadSnapshot::query()->where('academic_load_id', $this->load->id)->count())->toBe(1);
});

it('RA-10: tras cancelar, la carga se puede volver a re-analizar y republicar con normalidad', function () {
    app(CancelReanalysis::class)->handle($this->load->fresh(), cancelReanalysisHead());
    app(TransitionLoad::class)->handle($this->load->fresh(), LoadStatus::Reanalysis, $this->admin, 'Otra vez', fn (): array => []);
    $this->session->update(['day' => 3]);
    app(TransitionLoad::class)->handle($this->load->fresh(), LoadStatus::Closed, $this->admin, null, fn (): array => []);

    $snapshot = app(PublishLoad::class)->handle($this->load->fresh(), $this->admin);

    expect($snapshot->version)->toBe(2)
        ->and($snapshot->diff['summary']['changed'])->toBe(1);
});
