<?php

use App\Modules\Academic\Models\Department;
use App\Modules\Faculty\Actions\AddPosition;
use App\Modules\Faculty\Actions\CreateTeacher;
use App\Modules\Faculty\Actions\RemovePosition;
use App\Modules\Faculty\Actions\UpdatePosition;
use App\Modules\Faculty\Enums\PositionKind;
use App\Modules\Faculty\Events\PositionChanged;
use App\Modules\Faculty\Models\TeacherPosition;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;

/*
| Plazas de un docente (S3, supuesto por confirmar): cada tipo admite solo sus horas; la vigencia va en orden;
| alta, cambio y baja dejan bitácora con el valor anterior y avisan tras el commit. Un cambio sin diferencias no
| escribe. Un docente puede tener varias vigentes a la vez (confirmado el 30 de octubre de 2026).
*/

beforeEach(function () {
    $department = Department::factory()->create(['code' => 'SC']);
    $this->teacher = app(CreateTeacher::class)->handle(['employee_number' => '1234567', 'name' => 'Ana Pérez Soto'], $department);
    $this->activityBase = (int) Activity::max('id');
});

/**
 * @return array<string, list<string>>
 */
function positionErrorsOf(Closure $action): array
{
    try {
        $action();
    } catch (ValidationException $exception) {
        return $exception->errors();
    }

    test()->fail('Debió rechazar con un error de validación.');
}

describe('agregar', function () {
    it('agrega una plaza con su bitácora y avisa tras el commit', function () {
        Event::fake([PositionChanged::class]);

        $position = app(AddPosition::class)->handle($this->teacher, ['kind' => 'tiempo_completo', 'base_hours' => 40, 'valid_from' => '2027-01-01', 'valid_to' => null]);
        $activity = activitySince()->sole();

        expect($position)->kind->toBe(PositionKind::TiempoCompleto)->base_hours->toBe(40)
            ->and($position->valid_from->toDateString())->toBe('2027-01-01')
            ->and($position->valid_to)->toBeNull()
            ->and($activity->event)->toBe('created')
            ->and($activity->attribute_changes['attributes'])->toMatchArray(['kind' => 'tiempo_completo', 'base_hours' => 40, 'valid_from' => '2027-01-01'])
            ->and(activitySince()->sole()->properties['teacher_id'])->toBe($this->teacher->id);
        Event::assertDispatched(PositionChanged::class);
    });

    it('un docente puede tener varias plazas vigentes a la vez', function () {
        app(AddPosition::class)->handle($this->teacher, ['kind' => 'medio_tiempo', 'base_hours' => 20]);
        app(AddPosition::class)->handle($this->teacher, ['kind' => 'asignatura', 'base_hours' => 6]);

        expect($this->teacher->positions()->count())->toBe(2);
    });

    it('S3: cada tipo admite solo sus horas', function (string $kind, int $hours, bool $valid) {
        $attempt = fn () => app(AddPosition::class)->handle($this->teacher, ['kind' => $kind, 'base_hours' => $hours]);

        if ($valid) {
            expect($attempt()->base_hours)->toBe($hours);

            return;
        }

        expect(positionErrorsOf($attempt))->toHaveKey('base_hours')
            ->and(TeacherPosition::count())->toBe(0);
    })->with([
        'tiempo completo 40' => ['tiempo_completo', 40, true],
        'tiempo completo 39' => ['tiempo_completo', 39, false],
        'tres cuartos 30' => ['tres_cuartos', 30, true],
        'tres cuartos 40' => ['tres_cuartos', 40, false],
        'medio tiempo 20' => ['medio_tiempo', 20, true],
        'medio tiempo 21' => ['medio_tiempo', 21, false],
        'honorarios 0' => ['honorarios', 0, true],
        'honorarios 5' => ['honorarios', 5, false],
        'asignatura 1' => ['asignatura', 1, true],
        'asignatura 40' => ['asignatura', 40, true],
        'asignatura 0' => ['asignatura', 0, false],
        'asignatura 41' => ['asignatura', 41, false],
    ]);

    it('el tipo con horas fijas las toma de sí mismo si no llegan', function () {
        expect(app(AddPosition::class)->handle($this->teacher, ['kind' => 'tres_cuartos'])->base_hours)->toBe(30);
    });

    it('rechaza un tipo que no existe', function () {
        expect(positionErrorsOf(fn () => app(AddPosition::class)->handle($this->teacher, ['kind' => 'otra', 'base_hours' => 5])))->toHaveKey('kind');
    });

    it('rechaza una vigencia que termina antes de empezar, pero acepta un solo día', function () {
        expect(positionErrorsOf(fn () => app(AddPosition::class)->handle($this->teacher, ['kind' => 'medio_tiempo', 'base_hours' => 20, 'valid_from' => '2027-02-01', 'valid_to' => '2027-01-31'])))->toHaveKey('valid_to');

        $day = app(AddPosition::class)->handle($this->teacher, ['kind' => 'medio_tiempo', 'base_hours' => 20, 'valid_from' => '2027-02-01', 'valid_to' => '2027-02-01']);

        expect($day->exists)->toBeTrue();
    });
});

describe('cambiar', function () {
    beforeEach(function () {
        $this->position = TeacherPosition::factory()->for($this->teacher)->asignatura(5)->create(['valid_from' => '2027-01-01']);
        $this->activityBase = (int) Activity::max('id');
    });

    it('cambia horas y vigencia, con el valor anterior en la bitácora, y avisa', function () {
        Event::fake([PositionChanged::class]);

        $updated = app(UpdatePosition::class)->handle($this->position, ['kind' => 'asignatura', 'base_hours' => 12, 'valid_from' => '2027-01-01', 'valid_to' => '2027-06-30']);
        $activity = activitySince()->sole();

        expect($updated->base_hours)->toBe(12)
            ->and($activity->event)->toBe('updated')
            ->and($activity->attribute_changes['attributes'])->toBe(['base_hours' => 12, 'valid_to' => '2027-06-30'])
            ->and($activity->attribute_changes['old'])->toBe(['base_hours' => 5, 'valid_to' => null]);
        Event::assertDispatched(PositionChanged::class);
    });

    it('lee el valor anterior con bloqueo: el de la base, no el del modelo viejo', function () {
        $stale = TeacherPosition::find($this->position->id);
        TeacherPosition::whereKey($this->position->id)->update(['base_hours' => 9]);

        app(UpdatePosition::class)->handle($stale, ['kind' => 'asignatura', 'base_hours' => 12, 'valid_from' => '2027-01-01']);

        expect(activitySince()->sole()->attribute_changes['old'])->toBe(['base_hours' => 9]);
    });

    it('sin cambios no escribe, no registra ni avisa', function () {
        Event::fake([PositionChanged::class]);

        app(UpdatePosition::class)->handle($this->position, ['kind' => 'asignatura', 'base_hours' => 5, 'valid_from' => '2027-01-01', 'valid_to' => null]);

        expect(activitySince()->count())->toBe(0);
        Event::assertNotDispatched(PositionChanged::class);
    });

    it('aplica las mismas reglas del tipo y de las fechas', function () {
        expect(positionErrorsOf(fn () => app(UpdatePosition::class)->handle($this->position, ['kind' => 'medio_tiempo', 'base_hours' => 5, 'valid_from' => '2027-01-01'])))->toHaveKey('base_hours')
            ->and(positionErrorsOf(fn () => app(UpdatePosition::class)->handle($this->position, ['kind' => 'asignatura', 'base_hours' => 5, 'valid_from' => '2027-03-01', 'valid_to' => '2027-02-01'])))->toHaveKey('valid_to')
            ->and($this->position->fresh()->base_hours)->toBe(5);
    });
});

describe('quitar', function () {
    it('quita una plaza sin borrarla de la base, con su bitácora, y avisa', function () {
        Event::fake([PositionChanged::class]);
        $position = TeacherPosition::factory()->for($this->teacher)->tiempoCompleto()->create();

        app(RemovePosition::class)->handle($position);
        $activity = activitySince()->sole();

        expect($position->fresh()->trashed())->toBeTrue()
            ->and(TeacherPosition::withTrashed()->find($position->id)->trashed())->toBeTrue()
            ->and($activity->event)->toBe('deleted')
            ->and($activity->attribute_changes['old'])->toMatchArray(['kind' => 'tiempo_completo', 'base_hours' => 40]);
        Event::assertDispatched(PositionChanged::class);
    });

    it('rechaza quitar una que ya se quitó', function () {
        $position = TeacherPosition::factory()->for($this->teacher)->create();
        app(RemovePosition::class)->handle($position);

        expect(positionErrorsOf(fn () => app(RemovePosition::class)->handle($position)))->toHaveKey('position');
    });
});
