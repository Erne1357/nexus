<?php

use App\Modules\Academic\Actions\CreateStudyPlan;
use App\Modules\Academic\Actions\UpdateStudyPlan;
use App\Modules\Academic\Events\CurriculumChanged;
use App\Modules\Academic\Events\PlanCareerChanging;
use App\Modules\Academic\Events\StudyPlanCreated;
use App\Modules\Academic\Events\StudyPlanUpdated;
use App\Modules\Academic\Models\Career;
use App\Modules\Academic\Models\StudyPlan;
use App\Modules\Students\Models\Student;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;

/*
| Actions de Plan de estudios. Crear y editar hacen cumplir la clave normalizada y única (también
| entre los borrados), semestres > 0 o vacíos, créditos >= 0 o vacíos y una carrera que existe. Cambiar
| la carrera solo si ningún alumno tiene el plan (PlanCareerChanging, que Students veta); cambiar los
| créditos o la carrera avisa CurriculumChanged.
*/

beforeEach(function () {
    $this->isc = Career::factory()->create(['code' => 'ISC']);
    $this->iia = Career::factory()->create(['code' => 'IIA']);
    $this->plan = StudyPlan::factory()->for($this->isc)->create(['code' => 'ISIC-2010-224', 'total_credits' => 260])->fresh();
});

function newPlan(array $overrides = []): StudyPlan
{
    return app(CreateStudyPlan::class)->handle([
        'career_id' => Career::where('code', 'ISC')->value('id'),
        'code' => 'isic-2025-001', 'name' => 'Plan 2025', 'version' => '2025',
        'semesters_count' => 9, 'total_credits' => 270, 'valid_from' => '2025-08-01',
        ...$overrides,
    ]);
}

function planErrors(Closure $callback): array
{
    try {
        $callback();
    } catch (ValidationException $exception) {
        return $exception->errors();
    }

    test()->fail('Debió rechazar.');
}

describe('CreateStudyPlan', function () {
    it('da de alta un plan activo con la clave en mayúsculas, bitácora y aviso', function () {
        Event::fake([StudyPlanCreated::class, CurriculumChanged::class]);

        $plan = newPlan();

        expect($plan->fresh())
            ->code->toBe('ISIC-2025-001')->is_active->toBeTrue()->career_id->toBe($this->isc->id)
            ->semesters_count->toBe(9)->total_credits->toBe(270)->valid_from->toDateString()->toBe('2025-08-01');

        $activity = Activity::where('subject_id', $plan->id)->sole();
        expect($activity)->description->toBe('Dio de alta el plan ISIC-2025-001')->event->toBe('created');
        Event::assertDispatched(StudyPlanCreated::class);
        Event::assertNotDispatched(CurriculumChanged::class);
    });

    it('acepta semestres, créditos y fecha vacíos', function () {
        $plan = newPlan(['semesters_count' => null, 'total_credits' => null, 'valid_from' => null])->fresh();

        expect($plan->semesters_count)->toBeNull()->and($plan->total_credits)->toBeNull()->and($plan->valid_from)->toBeNull();
    });

    it('rechaza una clave repetida aun entre los borrados', function () {
        $this->plan->delete();

        expect(planErrors(fn () => newPlan(['code' => 'isic-2010-224'])))->toHaveKey('code')
            ->and(StudyPlan::withTrashed()->count())->toBe(1);
    });

    it('rechaza semestres en cero, créditos negativos y una carrera que no existe', function () {
        expect(planErrors(fn () => newPlan(['semesters_count' => 0])))->toHaveKey('semesters_count')
            ->and(planErrors(fn () => newPlan(['total_credits' => -1])))->toHaveKey('total_credits')
            ->and(planErrors(fn () => newPlan(['career_id' => fakeKey(77)])))->toHaveKey('career_id')
            ->and(planErrors(fn () => newPlan(['career_id' => 'no-es-uuid'])))->toHaveKey('career_id');

        $this->iia->delete();
        expect(planErrors(fn () => newPlan(['career_id' => $this->iia->id])))->toHaveKey('career_id');
    });
});

describe('UpdateStudyPlan', function () {
    it('cambia el nombre con bitácora de lo anterior y lo nuevo, sin CurriculumChanged', function () {
        Event::fake([StudyPlanUpdated::class, CurriculumChanged::class]);

        $name = $this->plan->name;
        app(UpdateStudyPlan::class)->handle($this->plan, ['name' => 'Nuevo nombre']);

        $activity = Activity::where('subject_id', $this->plan->id)->sole();
        expect($this->plan->fresh()->name)->toBe('Nuevo nombre')
            ->and($activity->description)->toBe('Actualizó el plan ISIC-2010-224')
            ->and($activity->attribute_changes->toArray())->toBe(['attributes' => ['name' => 'Nuevo nombre'], 'old' => ['name' => $name]]);
        Event::assertDispatched(StudyPlanUpdated::class, fn ($event) => $event->changed === ['name']);
        Event::assertNotDispatched(CurriculumChanged::class);
    });

    it('si nada cambió no escribe, no registra ni avisa', function () {
        Event::fake([StudyPlanUpdated::class]);

        app(UpdateStudyPlan::class)->handle($this->plan, ['code' => 'isic-2010-224', 'name' => $this->plan->name]);

        expect(Activity::count())->toBe(0);
        Event::assertNotDispatched(StudyPlanUpdated::class);
    });

    it('conserva su clave pero rechaza la de otro plan, también borrado', function () {
        $other = StudyPlan::factory()->create(['code' => 'OTRO-1']);
        $other->delete();

        expect(planErrors(fn () => app(UpdateStudyPlan::class)->handle($this->plan, ['code' => 'otro-1'])))->toHaveKey('code')
            ->and($this->plan->fresh()->code)->toBe('ISIC-2010-224');
    });

    it('valida semestres y créditos', function () {
        expect(planErrors(fn () => app(UpdateStudyPlan::class)->handle($this->plan, ['semesters_count' => 0])))->toHaveKey('semesters_count')
            ->and(planErrors(fn () => app(UpdateStudyPlan::class)->handle($this->plan, ['total_credits' => -5])))->toHaveKey('total_credits');

        app(UpdateStudyPlan::class)->handle($this->plan, ['semesters_count' => null, 'total_credits' => null]);
        expect($this->plan->fresh())->semesters_count->toBeNull()->total_credits->toBeNull();
    });

    it('cambiar total_credits despacha CurriculumChanged con el plan', function () {
        Event::fake([CurriculumChanged::class]);

        app(UpdateStudyPlan::class)->handle($this->plan, ['total_credits' => 270]);

        Event::assertDispatched(CurriculumChanged::class, fn ($event) => $event->studyPlanId === $this->plan->id && $event->subjectId === null);
    });

    it('activa y desactiva con bitácora', function () {
        app(UpdateStudyPlan::class)->handle($this->plan, ['is_active' => false]);
        app(UpdateStudyPlan::class)->handle($this->plan, ['is_active' => true]);

        expect(Activity::where('subject_id', $this->plan->id)->orderBy('id')->get()->map(fn ($a) => $a->attribute_changes['attributes'])->all())
            ->toBe([['is_active' => false], ['is_active' => true]]);
    });

    it('sin alumnos permite cambiar la carrera y despacha PlanCareerChanging y CurriculumChanged', function () {
        Event::fake([PlanCareerChanging::class, CurriculumChanged::class]);

        app(UpdateStudyPlan::class)->handle($this->plan, ['career_id' => $this->iia->id]);

        expect($this->plan->fresh()->career_id)->toBe($this->iia->id);
        Event::assertDispatched(PlanCareerChanging::class, fn ($event) => $event->fromCareerId === $this->isc->id && $event->studyPlan->career_id === $this->iia->id);
        Event::assertDispatched(CurriculumChanged::class, fn ($event) => $event->studyPlanId === $this->plan->id);
    });

    it('no despacha PlanCareerChanging si la carrera no cambia', function () {
        Event::fake([PlanCareerChanging::class]);

        app(UpdateStudyPlan::class)->handle($this->plan, ['career_id' => $this->isc->id, 'name' => 'Otro']);

        Event::assertNotDispatched(PlanCareerChanging::class);
    });

    it('con alumnos rechaza cambiar la carrera con «Tiene N alumnos» y no escribe nada', function () {
        Student::factory()->for($this->plan)->count(2)->create(['career_id' => $this->isc->id]);
        Event::fake([StudyPlanUpdated::class, CurriculumChanged::class]);

        $errors = planErrors(fn () => app(UpdateStudyPlan::class)->handle($this->plan, ['career_id' => $this->iia->id, 'name' => 'Cambiado']));

        expect($errors)->toBe(['career_id' => ['Tiene 2 alumnos inscritos en este plan.']])
            ->and($this->plan->fresh())->career_id->toBe($this->isc->id)->name->toBe($this->plan->name)
            ->and(Activity::count())->toBe(0);
        Event::assertNotDispatched(StudyPlanUpdated::class);
        Event::assertNotDispatched(CurriculumChanged::class);
    });

    it('con un alumno lo dice en singular', function () {
        Student::factory()->for($this->plan)->create(['career_id' => $this->isc->id]);

        expect(planErrors(fn () => app(UpdateStudyPlan::class)->handle($this->plan, ['career_id' => $this->iia->id])))
            ->toBe(['career_id' => ['Tiene 1 alumno inscrito en este plan.']]);
    });

    it('un alumno borrado no cuenta', function () {
        Student::factory()->for($this->plan)->create(['career_id' => $this->isc->id])->delete();

        app(UpdateStudyPlan::class)->handle($this->plan, ['career_id' => $this->iia->id]);

        expect($this->plan->fresh()->career_id)->toBe($this->iia->id);
    });

    it('rechaza una carrera que no existe', function () {
        expect(planErrors(fn () => app(UpdateStudyPlan::class)->handle($this->plan, ['career_id' => fakeKey(77)])))->toHaveKey('career_id');
    });

    it('los alumnos de otro plan no estorban', function () {
        Student::factory()->for(StudyPlan::factory()->for($this->isc)->create())->create(['career_id' => $this->isc->id]);

        app(UpdateStudyPlan::class)->handle($this->plan, ['career_id' => $this->iia->id]);

        expect($this->plan->fresh()->career_id)->toBe($this->iia->id);
    });
});
