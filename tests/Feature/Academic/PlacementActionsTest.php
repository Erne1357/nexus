<?php

use App\Modules\Academic\Actions\PlaceSubject;
use App\Modules\Academic\Actions\RemovePlacement;
use App\Modules\Academic\Actions\UpdatePlacement;
use App\Modules\Academic\Enums\PrerequisiteKind;
use App\Modules\Academic\Events\CurriculumChanged;
use App\Modules\Academic\Models\Specialty;
use App\Modules\Academic\Models\StudyPlan;
use App\Modules\Academic\Models\StudyPlanSubject;
use App\Modules\Academic\Models\Subject;
use App\Modules\Academic\Models\SubjectPrerequisite;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;

/*
| Actions de la retícula de un plan (tarea 1.8; reglas-de-negocio.md, "Retícula del plan"): poner una materia en
| un semestre, cambiar su lugar (semestre, especialidad y si es obligatoria) y quitarla con su seriación. Volver a
| poner una materia quitada restaura su fila (único plan y materia, también contra las quitadas). Cada una deja
| bitácora y avisa con CurriculumChanged, porque cambia el avance de los alumnos del plan.
*/

beforeEach(function () {
    $this->plan = StudyPlan::factory()->create(['code' => 'ISIC-2010-224', 'semesters_count' => 9]);
    $this->software = Specialty::factory()->for($this->plan)->create(['code' => 'ISIC-ISW-2020', 'name' => 'Ingeniería de Software']);
    $this->subject = Subject::factory()->create(['code' => 'SCD-1022', 'name' => 'Simulación']);
});

function placementErrors(Closure $callback): array
{
    try {
        $callback();
    } catch (ValidationException $exception) {
        return $exception->errors();
    }

    test()->fail('Debió rechazar.');
}

function placeSubject(StudyPlan $plan, Subject $subject, int $semester = 5, ?Specialty $specialty = null, bool $isRequired = true): StudyPlanSubject
{
    return app(PlaceSubject::class)->handle($plan, $subject, $semester, $specialty, $isRequired);
}

function placementActivities(): int
{
    return Activity::where('subject_type', (new StudyPlanSubject)->getMorphClass())->count();
}

describe('PlaceSubject', function () {
    it('pone una materia en un semestre con su especialidad y si es obligatoria, con bitácora y CurriculumChanged', function () {
        Event::fake([CurriculumChanged::class]);

        $placement = placeSubject($this->plan, $this->subject, 5, $this->software, false);

        expect($placement->fresh())
            ->study_plan_id->toBe($this->plan->id)->subject_id->toBe($this->subject->id)
            ->semester->toBe(5)->specialty_id->toBe($this->software->id)->is_required->toBeFalse();

        $activity = Activity::where('subject_id', $placement->id)->sole();
        expect($activity)->description->toBe('Puso la materia SCD-1022 en el semestre 5 del plan ISIC-2010-224')->event->toBe('created')
            ->and($activity->attribute_changes->toArray())->toBe(['attributes' => ['semester' => 5, 'specialty_id' => $this->software->id, 'is_required' => false]]);
        Event::assertDispatched(CurriculumChanged::class, fn (CurriculumChanged $event): bool => $event->studyPlanId === $this->plan->id && $event->subjectId === null);
    });

    it('sin especialidad la pone en el tronco común y obligatoria', function () {
        $placement = placeSubject($this->plan, $this->subject, 1);

        expect($placement->fresh())->semester->toBe(1)->specialty_id->toBeNull()->is_required->toBeTrue();
    });

    it('volver a poner una materia quitada restaura su fila con el lugar nuevo, sin su seriación vieja (Review Focus 3)', function () {
        Event::fake([CurriculumChanged::class]);
        $calculus = Subject::factory()->create();
        placeInPlan($this->plan, $calculus);
        $row = placeInPlan($this->plan, $this->subject, 2);
        $seriation = seriate($this->plan, $this->subject, $calculus);
        app(RemovePlacement::class)->handle($row);

        $placement = placeSubject($this->plan, $this->subject, 4, $this->software, false);

        expect($placement->id)->toBe($row->id)
            ->and(StudyPlanSubject::withTrashed()->where('subject_id', $this->subject->id)->count())->toBe(1)
            ->and($placement->fresh())->trashed()->toBeFalse()->semester->toBe(4)->specialty_id->toBe($this->software->id)->is_required->toBeFalse()
            ->and($seriation->fresh()->trashed())->toBeTrue();

        $activity = Activity::where('subject_id', $row->id)->where('event', 'restored')->sole();
        expect($activity)->description->toBe('Volvió a poner la materia SCD-1022 en el semestre 4 del plan ISIC-2010-224')->event->toBe('restored')
            ->and($activity->attribute_changes->toArray())->toBe([
                'attributes' => ['semester' => 4, 'specialty_id' => $this->software->id, 'is_required' => false],
                'old' => ['semester' => 2, 'specialty_id' => null, 'is_required' => true],
            ]);
        Event::assertDispatched(CurriculumChanged::class);
    });

    it('una materia que ya está en la retícula es error y no cambia nada', function () {
        Event::fake([CurriculumChanged::class]);
        placeInPlan($this->plan, $this->subject, 2);

        expect(placementErrors(fn () => placeSubject($this->plan, $this->subject, 5)))
            ->toBe(['subject_id' => ['SCD-1022 ya está en la retícula de este plan, en el semestre 2.']])
            ->and(StudyPlanSubject::sole()->semester)->toBe(2)
            ->and(placementActivities())->toBe(0);
        Event::assertNotDispatched(CurriculumChanged::class);
    });

    it('la misma materia sí se pone en otro plan', function () {
        placeInPlan(StudyPlan::factory()->create(), $this->subject, 2);

        expect(placeSubject($this->plan, $this->subject, 3)->study_plan_id)->toBe($this->plan->id);
    });

    it('el semestre va de 1 a los semestres del plan', function (int $semester) {
        expect(placementErrors(fn () => placeSubject($this->plan, $this->subject, $semester)))
            ->toBe(['semester' => ['El semestre debe ser de 1 a 9: el plan tiene 9 semestres.']])
            ->and(StudyPlanSubject::count())->toBe(0);
    })->with(['cero' => 0, 'negativo' => -1, 'después del último' => 10]);

    it('sin semestres capturados, el semestre llega hasta 12 (supuesto por confirmar)', function () {
        $this->plan->update(['semesters_count' => null]);

        expect(placeSubject($this->plan, $this->subject, 12)->semester)->toBe(12)
            ->and(placementErrors(fn () => placeSubject($this->plan, Subject::factory()->create(), 13)))
            ->toBe(['semester' => ['El semestre debe ser de 1 a 12 mientras el plan no tenga capturados sus semestres.']]);
    });

    it('lee los semestres del plan en la base, no los del modelo que recibe', function () {
        $stale = StudyPlan::find($this->plan->id);
        $this->plan->update(['semesters_count' => 4]);

        expect(placementErrors(fn () => placeSubject($stale, $this->subject, 6)))->toHaveKey('semester');
    });

    it('una especialidad de otro plan, quitada o inactiva es error en specialty_id y no escribe (Review Focus 2)', function (Closure $specialty, string $message) {
        Event::fake([CurriculumChanged::class]);

        expect(placementErrors(fn () => placeSubject($this->plan, $this->subject, 5, $specialty($this))))->toBe(['specialty_id' => [$message]])
            ->and(StudyPlanSubject::count())->toBe(0)
            ->and(placementActivities())->toBe(0);
        Event::assertNotDispatched(CurriculumChanged::class);
    })->with([
        'de otro plan' => [fn () => Specialty::factory()->create(), 'La especialidad no es de este plan o se quitó.'],
        'quitada' => [fn ($test) => tap($test->software)->delete(), 'La especialidad no es de este plan o se quitó.'],
        'inactiva' => [fn ($test) => tap($test->software)->update(['is_active' => false]), 'La especialidad ISIC-ISW-2020 está inactiva: actívala antes de ponerle materias.'],
    ]);

    it('una materia inactiva o borrada del catálogo no se puede poner, tampoco restaurar su fila', function (Closure $change, string $message) {
        $row = placeInPlan($this->plan, $this->subject, 2);
        $row->delete();
        $change($this->subject);

        expect(placementErrors(fn () => placeSubject($this->plan, $this->subject, 5)))->toBe(['subject_id' => [$message]])
            ->and($row->fresh()->trashed())->toBeTrue()
            ->and(placementActivities())->toBe(0);
    })->with([
        'inactiva' => [fn (Subject $subject) => $subject->update(['is_active' => false]), 'SCD-1022 está inactiva en el catálogo: actívala antes de ponerla en una retícula.'],
        'borrada' => [fn (Subject $subject) => $subject->delete(), 'SCD-1022 está borrada del catálogo.'],
    ]);
});

describe('UpdatePlacement', function () {
    beforeEach(function () {
        $this->row = placeInPlan($this->plan, $this->subject, 2)->fresh();
    });

    it('mueve de semestre, cambia la especialidad y si es obligatoria, con bitácora de lo anterior y lo nuevo, y CurriculumChanged', function () {
        Event::fake([CurriculumChanged::class]);

        $placement = app(UpdatePlacement::class)->handle($this->row, ['semester' => 5, 'specialty_id' => $this->software->id, 'is_required' => false, 'subject_id' => fakeKey(1)]);

        expect($placement->fresh())->semester->toBe(5)->specialty_id->toBe($this->software->id)->is_required->toBeFalse()->subject_id->toBe($this->subject->id);

        $activity = Activity::where('subject_id', $this->row->id)->sole();
        expect($activity)->description->toBe('Cambió el lugar de la materia SCD-1022 en el plan ISIC-2010-224')->event->toBe('updated')
            ->and($activity->attribute_changes->toArray())->toBe([
                'attributes' => ['semester' => 5, 'specialty_id' => $this->software->id, 'is_required' => false],
                'old' => ['semester' => 2, 'specialty_id' => null, 'is_required' => true],
            ]);
        Event::assertDispatched(CurriculumChanged::class, fn (CurriculumChanged $event): bool => $event->studyPlanId === $this->plan->id);
    });

    it('registra solo lo que cambió, y vuelve al tronco común con una especialidad vacía', function () {
        $this->row->update(['specialty_id' => $this->software->id]);

        app(UpdatePlacement::class)->handle($this->row->fresh(), ['semester' => 2, 'specialty_id' => '', 'is_required' => true]);

        expect($this->row->fresh()->specialty_id)->toBeNull()
            ->and(Activity::where('subject_id', $this->row->id)->sole()->attribute_changes->toArray())
            ->toBe(['attributes' => ['specialty_id' => null], 'old' => ['specialty_id' => $this->software->id]]);
    });

    it('si nada cambió no escribe, no registra ni avisa, aunque su especialidad ya esté inactiva', function () {
        Event::fake([CurriculumChanged::class]);
        $this->row->update(['specialty_id' => $this->software->id]);
        $this->software->update(['is_active' => false]);

        app(UpdatePlacement::class)->handle($this->row->fresh(), ['semester' => 2, 'specialty_id' => $this->software->id, 'is_required' => true]);

        expect(placementActivities())->toBe(0);
        Event::assertNotDispatched(CurriculumChanged::class);
    });

    it('revisa el semestre y la especialidad nuevos como al poner, y no escribe (Review Focus 2)', function (array $data, array $errors) {
        Event::fake([CurriculumChanged::class]);
        $data = array_map(fn (mixed $value): mixed => $value instanceof Closure ? $value($this) : $value, $data);

        expect(placementErrors(fn () => app(UpdatePlacement::class)->handle($this->row, $data)))->toBe($errors)
            ->and($this->row->fresh())->semester->toBe(2)->specialty_id->toBeNull()
            ->and(placementActivities())->toBe(0);
        Event::assertNotDispatched(CurriculumChanged::class);
    })->with([
        'semestre cero' => [['semester' => 0], ['semester' => ['El semestre debe ser de 1 a 9: el plan tiene 9 semestres.']]],
        'semestre después del último' => [['semester' => 10], ['semester' => ['El semestre debe ser de 1 a 9: el plan tiene 9 semestres.']]],
        'especialidad de otro plan' => [['specialty_id' => fn () => Specialty::factory()->create()->id], ['specialty_id' => ['La especialidad no es de este plan o se quitó.']]],
        'especialidad quitada' => [['specialty_id' => fn ($test) => tap($test->software)->delete()->id], ['specialty_id' => ['La especialidad no es de este plan o se quitó.']]],
        'especialidad que no es uuid' => [['specialty_id' => 'no-es-uuid'], ['specialty_id' => ['La especialidad no es de este plan o se quitó.']]],
        'especialidad inactiva' => [['specialty_id' => fn ($test) => tap($test->software)->update(['is_active' => false])->id], ['specialty_id' => ['La especialidad ISIC-ISW-2020 está inactiva: actívala antes de ponerle materias.']]],
    ]);

    it('una fila quitada mientras tanto no existe', function () {
        StudyPlanSubject::whereKey($this->row->id)->delete();

        app(UpdatePlacement::class)->handle($this->row, ['semester' => 3]);
    })->throws(ModelNotFoundException::class);
});

describe('RemovePlacement', function () {
    beforeEach(function () {
        $subject = fn (string $code): Subject => Subject::factory()->create(['code' => $code]);
        $this->calculus = $subject('ACF-0901');
        $this->numerical = $subject('SCC-1017');
        $this->networks = $subject('SCD-1021');
        $this->other = $subject('SCD-1015');
        $this->row = placeInPlan($this->plan, $this->subject, 4, $this->software)->fresh();

        foreach ([$this->calculus, $this->numerical, $this->networks, $this->other] as $semester => $placed) {
            placeInPlan($this->plan, $placed, $semester + 1);
        }
    });

    it('quita la fila y todas sus seriaciones, como materia y como requisito, con bitácora de cuántas y CurriculumChanged', function () {
        Event::fake([CurriculumChanged::class]);
        $gone = [
            seriate($this->plan, $this->subject, $this->calculus),
            seriate($this->plan, $this->subject, $this->numerical, PrerequisiteKind::Correquisito),
            seriate($this->plan, $this->networks, $this->subject),
        ];
        $stays = seriate($this->plan, $this->networks, $this->other);
        $otherPlan = StudyPlan::factory()->create();
        placeInPlan($otherPlan, $this->subject);
        placeInPlan($otherPlan, $this->calculus);
        $elsewhere = seriate($otherPlan, $this->subject, $this->calculus);

        expect(app(RemovePlacement::class)->handle($this->row))->toBe(3)
            ->and($this->row->fresh()->trashed())->toBeTrue()
            ->and(collect($gone)->every(fn (SubjectPrerequisite $prerequisite): bool => $prerequisite->fresh()->trashed()))->toBeTrue()
            ->and($stays->fresh()->trashed())->toBeFalse()
            ->and($elsewhere->fresh()->trashed())->toBeFalse()
            ->and(StudyPlanSubject::where('study_plan_id', $otherPlan->id)->count())->toBe(2);

        $activity = Activity::where('subject_id', $this->row->id)->sole();
        expect($activity)->description->toBe('Quitó la materia SCD-1022 de la retícula del plan ISIC-2010-224 con sus 3 seriaciones')->event->toBe('deleted')
            ->and($activity->attribute_changes->toArray())->toBe(['old' => ['semester' => 4, 'specialty_id' => $this->software->id, 'is_required' => true]])
            ->and($activity->properties->toArray())->toBe(['prerequisites_removed' => 3]);
        Event::assertDispatched(CurriculumChanged::class, fn (CurriculumChanged $event): bool => $event->studyPlanId === $this->plan->id);
    });

    it('dice en la bitácora si no tenía seriación o tenía una, y no cuenta las ya quitadas', function (int $seriations, string $description) {
        if ($seriations === 1) {
            seriate($this->plan, $this->networks, $this->subject);
        }

        seriate($this->plan, $this->subject, $this->calculus)->delete();

        expect(app(RemovePlacement::class)->handle($this->row))->toBe($seriations)
            ->and(Activity::where('subject_id', $this->row->id)->sole()->description)->toBe($description);
    })->with([
        'sin seriación' => [0, 'Quitó la materia SCD-1022 de la retícula del plan ISIC-2010-224 sin seriación'],
        'una' => [1, 'Quitó la materia SCD-1022 de la retícula del plan ISIC-2010-224 con 1 seriación'],
    ]);

    it('lo hace en una transacción: si algo falla, la fila y su seriación se quedan', function () {
        $seriation = seriate($this->plan, $this->subject, $this->calculus);
        StudyPlanSubject::deleting(fn () => throw new RuntimeException('Falla al quitar la fila.'));

        expect(fn () => app(RemovePlacement::class)->handle($this->row))->toThrow(RuntimeException::class)
            ->and($this->row->fresh()->trashed())->toBeFalse()
            ->and($seriation->fresh()->trashed())->toBeFalse()
            ->and(placementActivities())->toBe(0);
    });

    it('una fila ya quitada no existe', function () {
        StudyPlanSubject::whereKey($this->row->id)->delete();

        app(RemovePlacement::class)->handle($this->row);
    })->throws(ModelNotFoundException::class);
});
