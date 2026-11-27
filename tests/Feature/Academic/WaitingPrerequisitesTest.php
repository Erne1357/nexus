<?php

use App\Modules\Academic\Actions\ResolvePendingPlacement;
use App\Modules\Academic\Actions\WaitingPrerequisiteResult;
use App\Modules\Academic\Enums\PrerequisiteKind;
use App\Modules\Academic\Enums\WaitingPrerequisiteOutcome;
use App\Modules\Academic\Events\CurriculumChanged;
use App\Modules\Academic\Models\StudyPlan;
use App\Modules\Academic\Models\StudyPlanPrerequisitePending;
use App\Modules\Academic\Models\StudyPlanSubjectPending;
use App\Modules\Academic\Models\Subject;
use App\Modules\Academic\Models\SubjectPrerequisite;
use App\Modules\Core\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Spatie\Activitylog\Models\Activity;

/*
| Requisitos que esperan (migración de la seriación del SII; decisión del usuario del 10 de noviembre de 2026,
| confirmada): un requisito del SII con una materia en «Por validar» se guarda aparte, ligado al plan, y entra solo a
| la seriación cuando Servicios Escolares le asigna semestre a la materia (y a la otra, si también esperaba). Al final de
| ResolvePendingPlacement, en la misma transacción, pasan a `subject_prerequisites` los que ya tienen sus dos materias
| en la retícula y quedan con `applied_at`; uno que cerraría un ciclo de seriación no entra y queda esperando con una
| nota.
*/

beforeEach(function () {
    $this->plan = StudyPlan::factory()->create(['code' => 'IIND-2004', 'semesters_count' => 9]);
    $this->user = User::factory()->create();
    $this->calculus = Subject::factory()->create(['code' => 'ACF-0901']);
    placeInPlan($this->plan, $this->calculus, 1);
    $this->physics = StudyPlanSubjectPending::factory()->for($this->plan)->for(Subject::factory()->create(['code' => 'AE22']), 'subject')->create();
    $this->drawing = StudyPlanSubjectPending::factory()->for($this->plan)->for(Subject::factory()->create(['code' => 'AE9']), 'subject')->create();
});

/**
 * Un requisito que espera en el plan: `$subject` pide a `$prerequisite`.
 */
function waitingPrerequisite(StudyPlan $plan, Subject $subject, Subject $prerequisite, PrerequisiteKind $kind = PrerequisiteKind::Seriacion): StudyPlanPrerequisitePending
{
    return StudyPlanPrerequisitePending::factory()->create([
        'study_plan_id' => $plan->id, 'subject_id' => $subject->id, 'prerequisite_subject_id' => $prerequisite->id, 'kind' => $kind, 'sii_grid' => 'A/3',
    ]);
}

describe('tabla', function () {
    it('un requisito espera una sola vez por plan, materia y requisito; otro plan puede repetirlo', function () {
        waitingPrerequisite($this->plan, $this->physics->subject, $this->calculus);
        waitingPrerequisite(StudyPlan::factory()->create(), $this->physics->subject, $this->calculus);

        expect(fn () => waitingPrerequisite($this->plan, $this->physics->subject, $this->calculus, PrerequisiteKind::Correquisito))
            ->toThrow(UniqueConstraintViolationException::class);
    });

    it('una materia no se pide a sí misma y el tipo es seriación o correquisito, aunque la escritura no pase por el modelo', function (string $constraint, Closure $row) {
        expect(fn () => DB::table('study_plan_prerequisite_pendings')->insert($row($this)))->toThrow(QueryException::class, $constraint);
    })->with([
        'a sí misma' => ['study_plan_prerequisite_pendings_not_itself_check', fn ($test): array => [
            'study_plan_id' => $test->plan->id, 'subject_id' => $test->calculus->id, 'prerequisite_subject_id' => $test->calculus->id, 'kind' => 'seriacion',
        ]],
        'otro tipo' => ['study_plan_prerequisite_pendings_kind_check', fn ($test): array => [
            'study_plan_id' => $test->plan->id, 'subject_id' => $test->physics->subject_id, 'prerequisite_subject_id' => $test->calculus->id, 'kind' => 'P',
        ]],
    ]);

    it('nace sin aplicar, del SII y con su tipo', function () {
        $waiting = waitingPrerequisite($this->plan, $this->physics->subject, $this->calculus, PrerequisiteKind::Correquisito)->fresh();

        expect($waiting)->applied_at->toBeNull()->source->toBe('sii')->kind->toBe(PrerequisiteKind::Correquisito)->note->toBeNull()
            ->and($waiting->subject->code)->toBe('AE22')->and($waiting->prerequisite->code)->toBe('ACF-0901')->and($waiting->studyPlan->code)->toBe('IIND-2004');
    });
});

describe('ResolvePendingPlacement', function () {
    it('al asignarle semestre a la materia que esperaba, sus requisitos entran a la seriación como seriación o correquisito y quedan aplicados', function () {
        $algebra = Subject::factory()->create(['code' => 'ACF-0903']);
        placeInPlan($this->plan, $algebra, 3);
        $requires = waitingPrerequisite($this->plan, $this->physics->subject, $this->calculus);
        $requiredBy = waitingPrerequisite($this->plan, $algebra, $this->physics->subject, PrerequisiteKind::Correquisito);

        app(ResolvePendingPlacement::class)->handle($this->physics, 2, $this->user);

        expect(SubjectPrerequisite::query()->where('study_plan_id', $this->plan->id)->get()->map(fn (SubjectPrerequisite $row): array => [$row->subject->code, $row->prerequisite->code, $row->kind])->sortBy(0)->values()->all())
            ->toBe([['ACF-0903', 'AE22', PrerequisiteKind::Correquisito], ['AE22', 'ACF-0901', PrerequisiteKind::Seriacion]])
            ->and($requires->fresh()->applied_at)->not->toBeNull()
            ->and($requiredBy->fresh()->applied_at)->not->toBeNull();
    });

    it('si las dos materias esperaban, el requisito entra cuando se valida la segunda', function () {
        $waiting = waitingPrerequisite($this->plan, $this->physics->subject, $this->drawing->subject);

        app(ResolvePendingPlacement::class)->handle($this->physics, 2, $this->user);

        expect($waiting->fresh()->applied_at)->toBeNull()
            ->and(SubjectPrerequisite::query()->count())->toBe(0);

        app(ResolvePendingPlacement::class)->handle($this->drawing, 1, $this->user);

        expect($waiting->fresh()->applied_at)->not->toBeNull()
            ->and(SubjectPrerequisite::query()->sole())->subject_id->toBe($this->physics->subject_id)->prerequisite_subject_id->toBe($this->drawing->subject_id);
    });

    it('un requisito que cerraría un ciclo de seriación no entra: queda esperando con una nota que lo dice', function () {
        $first = waitingPrerequisite($this->plan, $this->physics->subject, $this->calculus);
        $cycle = waitingPrerequisite($this->plan, $this->calculus, $this->physics->subject);

        app(ResolvePendingPlacement::class)->handle($this->physics, 2, $this->user);

        expect($first->fresh()->applied_at)->not->toBeNull()
            ->and($cycle->fresh())->applied_at->toBeNull()->note->toBe('Cierra un ciclo de seriación: AE22 ya pide, en cadena, a ACF-0901. Sigue esperando.')
            ->and(SubjectPrerequisite::query()->sole()->subject_id)->toBe($this->physics->subject_id);
    });

    it('un correquisito no forma ciclo', function () {
        waitingPrerequisite($this->plan, $this->physics->subject, $this->calculus);
        $corequisite = waitingPrerequisite($this->plan, $this->calculus, $this->physics->subject, PrerequisiteKind::Correquisito);

        app(ResolvePendingPlacement::class)->handle($this->physics, 2, $this->user);

        expect($corequisite->fresh()->applied_at)->not->toBeNull()
            ->and(SubjectPrerequisite::query()->count())->toBe(2);
    });

    it('deja en la bitácora cada requisito que entró, con quién validó la materia', function () {
        $waiting = waitingPrerequisite($this->plan, $this->physics->subject, $this->calculus);

        app(ResolvePendingPlacement::class)->handle($this->physics, 2, $this->user);

        $row = SubjectPrerequisite::query()->sole();
        $entry = Activity::query()->where('subject_type', $row->getMorphClass())->where('subject_id', $row->id)->sole();

        expect($entry->description)->toBe('Agregó a AE22 el requisito ACF-0901 (seriación) en el plan IIND-2004, que esperaba de la migración del SII')
            ->and($entry->causer_id)->toBe($this->user->id)
            ->and($entry->attribute_changes['attributes'])->toBe(['subject_id' => $row->subject_id, 'prerequisite_subject_id' => $row->prerequisite_subject_id, 'kind' => 'seriacion'])
            ->and($entry->properties['waiting_prerequisite_id'])->toBe($waiting->id);
    });

    it('R23: si la materia ya estaba en la retícula, igual entran sus requisitos y avisa con CurriculumChanged', function () {
        Event::fake([CurriculumChanged::class]);
        placeInPlan($this->plan, $this->physics->subject, 4);
        $waiting = waitingPrerequisite($this->plan, $this->physics->subject, $this->calculus);

        app(ResolvePendingPlacement::class)->handle($this->physics, 2, $this->user);

        expect($waiting->fresh()->applied_at)->not->toBeNull()
            ->and(SubjectPrerequisite::query()->count())->toBe(1);
        Event::assertDispatched(CurriculumChanged::class, fn (CurriculumChanged $event): bool => $event->studyPlanId === $this->plan->id);
    });

    it('si la seriación del plan ya tiene ese requisito, se queda con su tipo hasta la siguiente corrida y el que esperaba queda aplicado, sin nota', function () {
        Event::fake([CurriculumChanged::class]);
        placeInPlan($this->plan, $this->physics->subject, 4);
        seriate($this->plan, $this->physics->subject, $this->calculus, PrerequisiteKind::Correquisito);
        $waiting = waitingPrerequisite($this->plan, $this->physics->subject, $this->calculus);
        $waiting->forceFill(['note' => 'Cierra un ciclo de seriación: de una vuelta anterior.'])->save();

        $resolution = app(ResolvePendingPlacement::class)->handle($this->physics, 2, $this->user);

        expect(SubjectPrerequisite::query()->sole()->kind)->toBe(PrerequisiteKind::Correquisito)
            ->and($waiting->fresh())->applied_at->not->toBeNull()->note->toBeNull()
            ->and(array_map(fn (WaitingPrerequisiteResult $result): array => [$result->waiting->id, $result->outcome], $resolution->prerequisites))
            ->toBe([[$waiting->id, WaitingPrerequisiteOutcome::AlreadyThere]]);
        Event::assertNotDispatched(CurriculumChanged::class);
    });

    it('un requisito que se quitó de la seriación en Nexus no se restaura: el que esperaba queda aplicado con una nota y bitácora (supuesto por confirmar)', function () {
        placeInPlan($this->plan, $this->physics->subject, 4);
        $removed = seriate($this->plan, $this->physics->subject, $this->calculus);
        $removed->delete();
        $waiting = waitingPrerequisite($this->plan, $this->physics->subject, $this->calculus, PrerequisiteKind::Correquisito);

        $resolution = app(ResolvePendingPlacement::class)->handle($this->physics, 2, $this->user);

        expect($removed->fresh())->trashed()->toBeTrue()->kind->toBe(PrerequisiteKind::Seriacion)
            ->and($waiting->fresh())->applied_at->not->toBeNull()->note->toBe('No entró: se quitó de la seriación en Nexus.')
            ->and($resolution->prerequisites[0]->outcome)->toBe(WaitingPrerequisiteOutcome::RemovedInNexus)
            ->and(Activity::query()->where('subject_type', $waiting->getMorphClass())->where('subject_id', $waiting->id)->sole()->description)
            ->toBe('El requisito ACF-0901 de AE22 no entró a la seriación del plan IIND-2004: se quitó en Nexus');
    });

    it('devuelve, por cada requisito que tocó, si entró o por qué no', function () {
        $entered = waitingPrerequisite($this->plan, $this->physics->subject, $this->calculus);
        $cycle = waitingPrerequisite($this->plan, $this->calculus, $this->physics->subject);
        $later = waitingPrerequisite($this->plan, $this->physics->subject, $this->drawing->subject);

        $resolution = app(ResolvePendingPlacement::class)->handle($this->physics, 2, $this->user);

        expect($resolution->placement->subject_id)->toBe($this->physics->subject_id)
            ->and(array_map(fn (WaitingPrerequisiteResult $result): array => [$result->waiting->id, $result->outcome], $resolution->prerequisites))
            ->toBe([[$entered->id, WaitingPrerequisiteOutcome::Entered], [$cycle->id, WaitingPrerequisiteOutcome::Cycle]])
            ->and($later->fresh()->applied_at)->toBeNull();
    });

    it('al aplicar varios, primero los que respetan el orden de los semestres y luego por orden de alta: en un ciclo entra el que pide una materia de un semestre anterior', function () {
        $backwards = waitingPrerequisite($this->plan, $this->calculus, $this->physics->subject);
        $forward = waitingPrerequisite($this->plan, $this->physics->subject, $this->calculus);

        app(ResolvePendingPlacement::class)->handle($this->physics, 2, $this->user);

        expect($forward->fresh()->applied_at)->not->toBeNull()
            ->and($backwards->fresh())->applied_at->toBeNull()->note->toBe('Cierra un ciclo de seriación: AE22 ya pide, en cadena, a ACF-0901. Sigue esperando.')
            ->and(SubjectPrerequisite::query()->sole()->subject_id)->toBe($this->physics->subject_id);
    });

    it('bloquea primero el plan para escribir, como al agregar una seriación, para que dos validaciones no se crucen', function () {
        DB::flushQueryLog();
        DB::enableQueryLog();

        app(ResolvePendingPlacement::class)->handle($this->physics, 2, $this->user);

        $locks = collect(DB::getQueryLog())->pluck('query')->filter(fn (string $query): bool => preg_match('/ for (update|share)/', $query) === 1)->values();

        expect($locks->first())->toContain('from "study_plans"')->toEndWith('for update');
    });

    it('no toca los requisitos de otro plan ni los que ya entraron, aunque sus dos materias estén en la retícula', function () {
        $other = StudyPlan::factory()->create();
        placeInPlan($other, $this->calculus, 1);
        placeInPlan($other, $this->physics->subject, 2);
        $elsewhere = waitingPrerequisite($other, $this->physics->subject, $this->calculus);
        $applied = waitingPrerequisite($this->plan, $this->physics->subject, $this->calculus);
        $applied->forceFill(['applied_at' => now()->subDay()])->save();

        $resolution = app(ResolvePendingPlacement::class)->handle($this->physics, 2, $this->user);

        expect($elsewhere->fresh()->applied_at)->toBeNull()
            ->and($applied->fresh()->applied_at->isYesterday())->toBeTrue()
            ->and($resolution->prerequisites)->toBe([])
            ->and(SubjectPrerequisite::query()->count())->toBe(0);
    });
});
