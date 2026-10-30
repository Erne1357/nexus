<?php

use App\Modules\Academic\Actions\AddPrerequisite;
use App\Modules\Academic\Actions\RemovePrerequisite;
use App\Modules\Academic\Actions\UpdatePrerequisite;
use App\Modules\Academic\Enums\PrerequisiteKind;
use App\Modules\Academic\Events\CurriculumChanged;
use App\Modules\Academic\Models\StudyPlan;
use App\Modules\Academic\Models\Subject;
use App\Modules\Academic\Models\SubjectPrerequisite;
use App\Modules\Academic\Rules\SeriationRules;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;

/*
| Actions de la seriación de un plan (tarea 1.8; reglas-de-negocio.md, "Reglas de datos" y "Seriación del plan"):
| agregar un requisito (seriación o correquisito), cambiar su tipo y quitarlo. Las dos materias están en la
| retícula del plan y una materia no se pide a sí misma; una seriación que cierra un ciclo se rechaza, un
| correquisito no forma ciclos. Una vez por plan, materia y requisito: agregar una quitada restaura su fila. Cada
| una deja bitácora y avisa con CurriculumChanged, porque cambia el avance de los alumnos del plan.
*/

beforeEach(function () {
    $this->plan = StudyPlan::factory()->create(['code' => 'ISIC-2010-224', 'semesters_count' => 9]);
    $subject = fn (string $code, int $semester): Subject => tap(
        Subject::factory()->create(['code' => $code, 'name' => "Materia {$code}"]),
        fn (Subject $subject) => placeInPlan($this->plan, $subject, $semester),
    );
    $this->calculus = $subject('ACF-0901', 1);
    $this->integral = $subject('ACF-0902', 2);
    $this->vectorial = $subject('ACF-0904', 3);
    $this->equations = $subject('ACF-0905', 4);
    $this->numerical = $subject('SCC-1017', 2);
});

function prerequisiteErrors(Closure $callback): array
{
    try {
        $callback();
    } catch (ValidationException $exception) {
        return $exception->errors();
    }

    test()->fail('Debió rechazar.');
}

function addPrerequisite(StudyPlan $plan, Subject $subject, Subject $prerequisite, PrerequisiteKind $kind = PrerequisiteKind::Seriacion): SubjectPrerequisite
{
    return app(AddPrerequisite::class)->handle($plan, $subject, $prerequisite, $kind);
}

function prerequisiteActivities(): int
{
    return Activity::where('subject_type', (new SubjectPrerequisite)->getMorphClass())->count();
}

/**
 * La cadena de cálculo del plan: ACF-0902 pide ACF-0901, ACF-0904 pide ACF-0902 y ACF-0905 pide ACF-0904.
 */
function calculusChain(object $test): void
{
    seriate($test->plan, $test->integral, $test->calculus);
    seriate($test->plan, $test->vectorial, $test->integral);
    seriate($test->plan, $test->equations, $test->vectorial);
}

describe('SeriationRules::edgesFor', function () {
    it('da la seriación sin quitar del plan, sin correquisitos ni la de otros planes', function () {
        seriate($this->plan, $this->integral, $this->calculus);
        seriate($this->plan, $this->vectorial, $this->integral);
        seriate($this->plan, $this->integral, $this->numerical, PrerequisiteKind::Correquisito);
        seriate($this->plan, $this->equations, $this->vectorial)->delete();
        $other = StudyPlan::factory()->create();
        placeInPlan($other, $this->calculus);
        placeInPlan($other, $this->equations, 2);
        seriate($other, $this->calculus, $this->equations);

        expect(SeriationRules::edgesFor($this->plan->id))->toEqual([
            $this->integral->id => [$this->calculus->id => true],
            $this->vectorial->id => [$this->integral->id => true],
        ]);
    });
});

describe('AddPrerequisite', function () {
    it('agrega una seriación con bitácora y CurriculumChanged', function () {
        Event::fake([CurriculumChanged::class]);

        $prerequisite = addPrerequisite($this->plan, $this->integral, $this->calculus);

        expect($prerequisite->fresh())
            ->study_plan_id->toBe($this->plan->id)->subject_id->toBe($this->integral->id)
            ->prerequisite_subject_id->toBe($this->calculus->id)->kind->toBe(PrerequisiteKind::Seriacion)
            ->and($prerequisite->subject->code)->toBe('ACF-0902')
            ->and($prerequisite->prerequisite->code)->toBe('ACF-0901');

        $activity = Activity::where('subject_id', $prerequisite->id)->sole();
        expect($activity)->description->toBe('Agregó a ACF-0902 el requisito ACF-0901 (seriación) en el plan ISIC-2010-224')->event->toBe('created')
            ->and($activity->attribute_changes->toArray())->toBe(['attributes' => [
                'subject_id' => $this->integral->id, 'prerequisite_subject_id' => $this->calculus->id, 'kind' => 'seriacion',
            ]]);
        Event::assertDispatched(CurriculumChanged::class, fn (CurriculumChanged $event): bool => $event->studyPlanId === $this->plan->id && $event->subjectId === null);
    });

    it('agrega un correquisito', function () {
        $prerequisite = addPrerequisite($this->plan, $this->integral, $this->numerical, PrerequisiteKind::Correquisito);

        expect($prerequisite->fresh()->kind)->toBe(PrerequisiteKind::Correquisito)
            ->and(Activity::where('subject_id', $prerequisite->id)->sole()->description)
            ->toBe('Agregó a ACF-0902 el requisito SCC-1017 (correquisito) en el plan ISIC-2010-224');
    });

    it('rechaza una seriación que cierra un ciclo, directo o en cadena, y no escribe', function (string $subject, string $prerequisite, string $message) {
        Event::fake([CurriculumChanged::class]);
        calculusChain($this);

        expect(prerequisiteErrors(fn () => addPrerequisite($this->plan, $this->{$subject}, $this->{$prerequisite})))->toBe(['kind' => [$message]])
            ->and(SubjectPrerequisite::count())->toBe(3)
            ->and(prerequisiteActivities())->toBe(0);
        Event::assertNotDispatched(CurriculumChanged::class);
    })->with([
        'directo' => ['calculus', 'integral', 'Cierra un ciclo: ACF-0902 ya pide, en cadena, a ACF-0901.'],
        'en cadena de 4' => ['calculus', 'equations', 'Cierra un ciclo: ACF-0905 ya pide, en cadena, a ACF-0901.'],
    ]);

    it('acepta como correquisito lo que como seriación cerraría un ciclo', function () {
        calculusChain($this);

        expect(addPrerequisite($this->plan, $this->calculus, $this->equations, PrerequisiteKind::Correquisito)->fresh()->kind)
            ->toBe(PrerequisiteKind::Correquisito);
    });

    it('no cuenta la seriación quitada ni la de otros planes para el ciclo', function () {
        seriate($this->plan, $this->integral, $this->calculus)->delete();
        $other = StudyPlan::factory()->create();
        placeInPlan($other, $this->calculus);
        placeInPlan($other, $this->vectorial, 2);
        seriate($other, $this->vectorial, $this->calculus);

        addPrerequisite($this->plan, $this->calculus, $this->integral);
        addPrerequisite($this->plan, $this->calculus, $this->vectorial);

        expect(SubjectPrerequisite::where('study_plan_id', $this->plan->id)->where('subject_id', $this->calculus->id)->count())->toBe(2);
    });

    it('un requisito o una materia fuera de la retícula del plan es error y no escribe (Review Focus 2)', function (Closure $change, string $field, string $code) {
        Event::fake([CurriculumChanged::class]);
        [$subject, $prerequisite] = $change($this);

        expect(prerequisiteErrors(fn () => addPrerequisite($this->plan, $subject, $prerequisite)))
            ->toBe([$field => ["{$code} no está en la retícula de este plan."]])
            ->and(SubjectPrerequisite::count())->toBe(0)
            ->and(prerequisiteActivities())->toBe(0);
        Event::assertNotDispatched(CurriculumChanged::class);
    })->with([
        'requisito fuera de la retícula' => [fn ($test) => [$test->integral, Subject::factory()->create(['code' => 'SCD-1018'])], 'prerequisite_subject_id', 'SCD-1018'],
        'requisito de otro plan' => [fn ($test) => [$test->integral, tap(Subject::factory()->create(['code' => 'SCD-1018']), fn ($subject) => placeInPlan(StudyPlan::factory()->create(), $subject))], 'prerequisite_subject_id', 'SCD-1018'],
        'requisito quitado de la retícula' => [fn ($test) => [$test->integral, tap($test->calculus, fn ($subject) => $test->plan->placements()->where('subject_id', $subject->id)->delete())], 'prerequisite_subject_id', 'ACF-0901'],
        'requisito borrado del catálogo' => [fn ($test) => [$test->integral, tap($test->calculus)->delete()], 'prerequisite_subject_id', 'ACF-0901'],
        'materia fuera de la retícula' => [fn ($test) => [Subject::factory()->create(['code' => 'SCD-1018']), $test->calculus], 'subject_id', 'SCD-1018'],
    ]);

    it('una materia no se pide a sí misma', function () {
        expect(prerequisiteErrors(fn () => addPrerequisite($this->plan, $this->integral, $this->integral, PrerequisiteKind::Correquisito)))
            ->toBe(['prerequisite_subject_id' => ['ACF-0902 no puede pedirse a sí misma.']])
            ->and(SubjectPrerequisite::count())->toBe(0);
    });

    it('un requisito que ya pide es error, sea del tipo que sea, y no cambia nada', function (PrerequisiteKind $kind) {
        Event::fake([CurriculumChanged::class]);
        seriate($this->plan, $this->integral, $this->calculus);

        expect(prerequisiteErrors(fn () => addPrerequisite($this->plan, $this->integral, $this->calculus, $kind)))
            ->toBe(['prerequisite_subject_id' => ['ACF-0902 ya pide ACF-0901 como seriación.']])
            ->and(SubjectPrerequisite::sole()->kind)->toBe(PrerequisiteKind::Seriacion)
            ->and(prerequisiteActivities())->toBe(0);
        Event::assertNotDispatched(CurriculumChanged::class);
    })->with([PrerequisiteKind::Seriacion, PrerequisiteKind::Correquisito]);

    it('agregar una seriación quitada restaura su fila con el tipo nuevo (Review Focus 3)', function () {
        Event::fake([CurriculumChanged::class]);
        $removed = seriate($this->plan, $this->integral, $this->calculus);
        app(RemovePrerequisite::class)->handle($removed);

        $prerequisite = addPrerequisite($this->plan, $this->integral, $this->calculus, PrerequisiteKind::Correquisito);

        expect($prerequisite->id)->toBe($removed->id)
            ->and(SubjectPrerequisite::withTrashed()->count())->toBe(1)
            ->and($prerequisite->fresh())->trashed()->toBeFalse()->kind->toBe(PrerequisiteKind::Correquisito);

        $activity = Activity::where('subject_id', $removed->id)->where('event', 'restored')->sole();
        expect($activity->description)->toBe('Volvió a agregar a ACF-0902 el requisito ACF-0901 (correquisito) en el plan ISIC-2010-224')
            ->and($activity->attribute_changes->toArray())->toBe(['attributes' => ['kind' => 'correquisito'], 'old' => ['kind' => 'seriacion']]);
        Event::assertDispatchedTimes(CurriculumChanged::class, 2);
    });

    it('restaurar una seriación quitada también revisa el ciclo', function () {
        calculusChain($this);
        $removed = seriate($this->plan, $this->calculus, $this->equations, PrerequisiteKind::Correquisito);
        $removed->delete();

        expect(prerequisiteErrors(fn () => addPrerequisite($this->plan, $this->calculus, $this->equations)))
            ->toBe(['kind' => ['Cierra un ciclo: ACF-0905 ya pide, en cadena, a ACF-0901.']])
            ->and($removed->fresh()->trashed())->toBeTrue();
    });
});

describe('UpdatePrerequisite', function () {
    it('cambia el tipo con bitácora de lo anterior y lo nuevo, y CurriculumChanged', function () {
        Event::fake([CurriculumChanged::class]);
        $row = seriate($this->plan, $this->integral, $this->numerical, PrerequisiteKind::Correquisito);

        $prerequisite = app(UpdatePrerequisite::class)->handle($row, PrerequisiteKind::Seriacion);

        expect($prerequisite->fresh()->kind)->toBe(PrerequisiteKind::Seriacion)
            ->and($prerequisite->subject->code)->toBe('ACF-0902')
            ->and($prerequisite->prerequisite->code)->toBe('SCC-1017');

        $activity = Activity::where('subject_id', $row->id)->sole();
        expect($activity)->description->toBe('Cambió a seriación el requisito SCC-1017 de ACF-0902 en el plan ISIC-2010-224')->event->toBe('updated')
            ->and($activity->attribute_changes->toArray())->toBe(['attributes' => ['kind' => 'seriacion'], 'old' => ['kind' => 'correquisito']]);
        Event::assertDispatched(CurriculumChanged::class, fn (CurriculumChanged $event): bool => $event->studyPlanId === $this->plan->id);
    });

    it('volver seriación un correquisito revisa el ciclo, y no escribe si lo cierra', function () {
        Event::fake([CurriculumChanged::class]);
        calculusChain($this);
        $row = seriate($this->plan, $this->calculus, $this->equations, PrerequisiteKind::Correquisito);

        expect(prerequisiteErrors(fn () => app(UpdatePrerequisite::class)->handle($row, PrerequisiteKind::Seriacion)))
            ->toBe(['kind' => ['Cierra un ciclo: ACF-0905 ya pide, en cadena, a ACF-0901.']])
            ->and($row->fresh()->kind)->toBe(PrerequisiteKind::Correquisito)
            ->and(prerequisiteActivities())->toBe(0);
        Event::assertNotDispatched(CurriculumChanged::class);
    });

    it('volver correquisito una seriación siempre se puede', function () {
        calculusChain($this);
        $row = SubjectPrerequisite::where('subject_id', $this->vectorial->id)->sole();

        app(UpdatePrerequisite::class)->handle($row, PrerequisiteKind::Correquisito);

        expect($row->fresh()->kind)->toBe(PrerequisiteKind::Correquisito)
            ->and(SeriationRules::edgesFor($this->plan->id))->not->toHaveKey($this->vectorial->id);
    });

    it('si el tipo no cambia no escribe, no registra ni avisa', function () {
        Event::fake([CurriculumChanged::class]);
        $row = seriate($this->plan, $this->integral, $this->calculus);

        $prerequisite = app(UpdatePrerequisite::class)->handle($row, PrerequisiteKind::Seriacion);

        expect($prerequisite->wasChanged())->toBeFalse()
            ->and(prerequisiteActivities())->toBe(0);
        Event::assertNotDispatched(CurriculumChanged::class);
    });

    it('lee el tipo en la base, no el del modelo que recibe', function () {
        $row = seriate($this->plan, $this->integral, $this->calculus);
        $stale = SubjectPrerequisite::find($row->id);
        $row->update(['kind' => PrerequisiteKind::Correquisito]);

        app(UpdatePrerequisite::class)->handle($stale, PrerequisiteKind::Seriacion);

        expect(Activity::where('subject_id', $row->id)->sole()->attribute_changes->toArray())
            ->toBe(['attributes' => ['kind' => 'seriacion'], 'old' => ['kind' => 'correquisito']]);
    });

    it('una quitada mientras tanto no existe', function () {
        $row = seriate($this->plan, $this->integral, $this->calculus);
        SubjectPrerequisite::whereKey($row->id)->delete();

        app(UpdatePrerequisite::class)->handle($row, PrerequisiteKind::Correquisito);
    })->throws(ModelNotFoundException::class);
});

describe('RemovePrerequisite', function () {
    it('la quita (borrado lógico) con bitácora del tipo que tenía, y CurriculumChanged', function () {
        Event::fake([CurriculumChanged::class]);
        $row = seriate($this->plan, $this->integral, $this->calculus);

        app(RemovePrerequisite::class)->handle($row);

        expect($row->fresh()->trashed())->toBeTrue()
            ->and(SubjectPrerequisite::count())->toBe(0);

        $activity = Activity::where('subject_id', $row->id)->sole();
        expect($activity)->description->toBe('Quitó a ACF-0902 el requisito ACF-0901 (seriación) en el plan ISIC-2010-224')->event->toBe('deleted')
            ->and($activity->attribute_changes->toArray())->toBe(['old' => ['kind' => 'seriacion']]);
        Event::assertDispatched(CurriculumChanged::class, fn (CurriculumChanged $event): bool => $event->studyPlanId === $this->plan->id);
    });

    it('una ya quitada no existe', function () {
        $row = seriate($this->plan, $this->integral, $this->calculus);
        SubjectPrerequisite::whereKey($row->id)->delete();

        app(RemovePrerequisite::class)->handle($row);
    })->throws(ModelNotFoundException::class);
});
