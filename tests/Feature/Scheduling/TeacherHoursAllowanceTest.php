<?php

use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\User;
use App\Modules\Faculty\Actions\GrantHourAllowance;
use App\Modules\Faculty\Models\Teacher;
use App\Modules\Faculty\Models\TeacherHourAllowance;
use App\Modules\Faculty\Models\TeacherPosition;
use App\Modules\Scheduling\Models\Group;
use App\Modules\Scheduling\Models\GroupSession;
use App\Modules\Scheduling\Validation\ScheduleValidator;
use Illuminate\Database\QueryException;
use Spatie\Activitylog\Models\Activity;

/*
| Horas autorizadas de un docente en un periodo (tarea 1.33, SEL-12): el límite de HC-08 es el mayor entre base más
| tolerancia y esas horas. Las da Staffing cuando el docente termina su carga.
*/

beforeEach(function () {
    $this->actor = User::factory()->admin()->create();
    $this->teacher = Teacher::factory()->create(['name' => 'Docente Horas']);
    TeacherPosition::factory()->for($this->teacher)->asignatura(2)->create();
    $this->group = Group::factory()->create(['teacher_id' => $this->teacher->id]);
    GroupSession::factory()->for($this->group)->on(1, '09:00', '10:00')->create();
    $this->term = Term::query()->findOrFail($this->group->term_id);
});

function allowanceHoursViolations(Teacher $teacher, Term $term): array
{
    $validator = app(ScheduleValidator::class);
    $validator->forget();

    return $validator->validateTeacher($teacher, $term)->forRule('HC-08');
}

function grantAllowanceFor(Teacher $teacher, Term $term, int $hours, User $actor): TeacherHourAllowance
{
    return app(GrantHourAllowance::class)->handle($teacher, $term, $hours, 'Cerró su carga con horas de más (SEL-05)', $actor);
}

it('SEL-12: con horas autorizadas el límite de HC-08 es el mayor de los dos', function () {
    expect(allowanceHoursViolations($this->teacher, $this->term))->toHaveCount(1);

    grantAllowanceFor($this->teacher, $this->term, 5, $this->actor);

    expect(allowanceHoursViolations($this->teacher, $this->term))->toBe([]);
});

it('SEL-12: unas horas autorizadas menores que la base no bajan el límite', function () {
    $this->teacher->positions()->update(['base_hours' => 10]);

    grantAllowanceFor($this->teacher, $this->term, 1, $this->actor);

    expect(allowanceHoursViolations($this->teacher, $this->term))->toBe([]);
});

it('SEL-12: las horas autorizadas de otro periodo no cuentan', function () {
    grantAllowanceFor($this->teacher, Term::factory()->create(), 5, $this->actor);

    expect(allowanceHoursViolations($this->teacher, $this->term))->toHaveCount(1);
});

it('GrantHourAllowance actualiza la fila del docente en el periodo y deja bitácora', function () {
    grantAllowanceFor($this->teacher, $this->term, 5, $this->actor);
    $allowance = grantAllowanceFor($this->teacher, $this->term, 7, $this->actor);

    expect(TeacherHourAllowance::query()->sole())
        ->id->toBe($allowance->id)
        ->hours->toBe(7)
        ->reason->toBe('Cerró su carga con horas de más (SEL-05)')
        ->granted_by->toBe($this->actor->id)
        ->granted_at->not->toBeNull()
        ->and(Activity::query()->where('event', 'hour_allowance_granted')->count())->toBe(2)
        ->and(Activity::query()->where('event', 'hour_allowance_granted')->latest('id')->first()->properties['hours'])->toBe(7);
});

it('la base no admite dos filas del mismo docente en el periodo', function () {
    grantAllowanceFor($this->teacher, $this->term, 5, $this->actor);

    expect(fn () => TeacherHourAllowance::query()->create([
        'teacher_id' => $this->teacher->id, 'term_id' => $this->term->id, 'hours' => 6, 'reason' => 'otra', 'granted_at' => now(),
    ]))->toThrow(QueryException::class);
});
