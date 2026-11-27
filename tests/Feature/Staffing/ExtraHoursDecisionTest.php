<?php

use App\Modules\Academic\Models\Department;
use App\Modules\Academic\Models\Subject;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\User;
use App\Modules\Faculty\Enums\PositionKind;
use App\Modules\Faculty\Models\Teacher;
use App\Modules\Faculty\Models\TeacherPosition;
use App\Modules\Scheduling\Models\Group;
use App\Modules\Staffing\Actions\DecideExtraHours;
use App\Modules\Staffing\Actions\RefreshTeacherTermLoads;
use App\Modules\Staffing\Enums\ExtraHoursDecision;
use App\Modules\Staffing\Enums\ExtraHoursState;
use App\Modules\Staffing\Models\TeacherExtraHoursDecision;
use App\Modules\Staffing\Models\TeacherTermLoad;
use App\Modules\Staffing\Queries\ExtraHoursStatusQuery;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;

/*
| PL-04 (tarea 1.81, confirmado el 27 de noviembre de 2026): lo que pasa de frente a grupo + 2 son horas extra (honorarios);
| el jefe de departamento las autoriza o no; quien tiene jefatura o más no tiene honorarios; sin plaza docente, todas las
| horas son honorarios.
*/

beforeEach(function () {
    $this->term = Term::factory()->current()->create(['starts_on' => '2027-01-18']);
    $this->campus = Campus::factory()->create();
    $this->sc = Department::factory()->create(['code' => 'SC']);
    $this->cb = Department::factory()->create(['code' => 'CB']);
    $this->modality = modalityOf('PRES');
    $this->jefe = User::factory()->withRole(Role::JefeDepartamento, ['campus_id' => $this->campus->id, 'department_id' => $this->sc->id])->create();
});

/** Un docente del departamento con una plaza de tiempo completo (22 h frente a grupo) o sin plaza, con `$hours` h de clase. */
function extraHoursTeacher(?string $kind, int $hours, ?User $user = null): Teacher
{
    $teacher = Teacher::factory()->adscribedTo(test()->sc)->create(['user_id' => $user?->id]);

    if ($kind === 'tc') {
        TeacherPosition::factory()->for($teacher)->create(['kind' => PositionKind::TiempoCompleto, 'base_hours' => 40, 'category' => 'E3817']);
    }

    extraHoursGroup($teacher, $hours);
    app(RefreshTeacherTermLoads::class)->handle(test()->term, [$teacher->id]);

    return $teacher;
}

function extraHoursGroup(Teacher $teacher, int $hours): Group
{
    return Group::factory()->create([
        'term_id' => test()->term->id, 'campus_id' => test()->campus->id, 'teacher_id' => $teacher->id,
        'subject_id' => Subject::factory()->create(['theory_hours' => $hours, 'practice_hours' => 0])->id,
    ]);
}

it('PL-04: TC de 40 h con 22 h frente a grupo: 24 h sin extra, 26 h con 2 h «por autorizar»', function () {
    $within = extraHoursTeacher('tc', 24);
    $over = extraHoursTeacher('tc', 26);

    $statuses = app(ExtraHoursStatusQuery::class)->forTerm($this->term, [$within->id, $over->id]);

    expect(TeacherTermLoad::query()->where('teacher_id', $over->id)->sole())->extra_hours->toBe(2)->with_honorarios_max_hours->toBe(44)
        ->and($statuses[$within->id]->hasExtra())->toBeFalse()
        ->and($statuses[$over->id])->extra->toBe(2)->state->toBe(ExtraHoursState::Pending)
        ->and($statuses[$over->id]->label())->toBe('2 h extra · honorarios por autorizar (jefe de depto)');
});

it('PL-04: el jefe autoriza las horas extra como honorarios y queda con bitácora', function () {
    $teacher = extraHoursTeacher('tc', 26);

    app(DecideExtraHours::class)->handle($teacher, $this->term, ExtraHoursDecision::Authorized, $this->jefe);
    $status = app(ExtraHoursStatusQuery::class)->forTeacher($this->term, $teacher->id);

    expect($status->state)->toBe(ExtraHoursState::Authorized)
        ->and($status->label())->toBe('2 h de honorarios autorizadas')
        ->and(Activity::query()->where('event', 'extra_hours_decided')->count())->toBe(1);
});

it('PL-04: si el jefe no autoriza, queda «requiere oficio del docente (pendiente)»', function () {
    $teacher = extraHoursTeacher('tc', 26);

    app(DecideExtraHours::class)->handle($teacher, $this->term, ExtraHoursDecision::Unauthorized, $this->jefe);

    expect(app(ExtraHoursStatusQuery::class)->forTeacher($this->term, $teacher->id)->label())
        ->toBe('2 h extra no autorizadas · requiere oficio del docente (pendiente)');
});

it('PL-04: decidir otra vez reemplaza la decisión, y si suben las horas extra la decisión se reabre «por autorizar» (supuesto por confirmar)', function () {
    $teacher = extraHoursTeacher('tc', 26);
    $decide = fn (ExtraHoursDecision $decision) => app(DecideExtraHours::class)->handle($teacher, $this->term, $decision, $this->jefe);

    $decide(ExtraHoursDecision::Unauthorized);
    $decide(ExtraHoursDecision::Authorized);
    extraHoursGroup($teacher, 1);
    app(RefreshTeacherTermLoads::class)->handle($this->term, [$teacher->id]);

    $reopened = app(ExtraHoursStatusQuery::class)->forTeacher($this->term, $teacher->id);

    expect(TeacherExtraHoursDecision::query()->where('teacher_id', $teacher->id)->count())->toBe(1)
        ->and($reopened)->extra->toBe(3)->state->toBe(ExtraHoursState::Pending)->decidedHours->toBe(2);
});

it('PL-04: quien tiene jefatura no tiene honorarios: sus horas extra van directo al oficio y nadie las autoriza', function () {
    $other = User::factory()->withRole(Role::JefeDepartamento, ['campus_id' => $this->campus->id, 'department_id' => $this->cb->id])->create();
    $teacher = extraHoursTeacher('tc', 26, $other);

    $status = app(ExtraHoursStatusQuery::class)->forTeacher($this->term, $teacher->id);
    $attempt = fn () => app(DecideExtraHours::class)->handle($teacher, $this->term, ExtraHoursDecision::Authorized, $this->jefe);

    expect($status->state)->toBe(ExtraHoursState::Jefatura)
        ->and($status->label())->toContain('Jefatura: sin honorarios · requiere oficio del docente (pendiente)')
        ->and($status->isDecidable())->toBeFalse()
        ->and($attempt)->toThrow(ValidationException::class);
});

it('PL-04: quien no tiene horas extra no se puede decidir', function () {
    $teacher = extraHoursTeacher('tc', 24);

    expect(fn () => app(DecideExtraHours::class)->handle($teacher, $this->term, ExtraHoursDecision::Authorized, $this->jefe))->toThrow(ValidationException::class);
});

it('PL-04: sin plaza docente todas sus horas son extra y pasan por la misma autorización (supuesto por confirmar)', function () {
    $teacher = extraHoursTeacher(null, 6);

    $status = app(ExtraHoursStatusQuery::class)->forTeacher($this->term, $teacher->id);

    expect($status)->extra->toBe(6)->state->toBe(ExtraHoursState::Pending);
});

it('PL-04: decide quien tiene el permiso en un departamento del docente; otro jefe, un docente u otro rol, no (alcance)', function () {
    $teacher = extraHoursTeacher('tc', 26);
    $otherJefe = User::factory()->withRole(Role::JefeDepartamento, ['campus_id' => $this->campus->id, 'department_id' => $this->cb->id])->create();
    $docente = User::factory()->withRole(Role::Docente)->create();
    $subdireccion = User::factory()->withRole(Role::Subdireccion)->create();
    $admin = User::factory()->admin()->create();
    $can = fn (User $user): bool => Gate::forUser($user)->allows('decide', [TeacherExtraHoursDecision::class, $teacher, $this->modality]);

    expect($can($this->jefe))->toBeTrue()
        ->and($can($admin))->toBeTrue()
        ->and($can($otherJefe))->toBeFalse()
        ->and($can($docente))->toBeFalse()
        ->and($can($subdireccion))->toBeFalse();
});
