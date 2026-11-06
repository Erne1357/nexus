<?php

use App\Modules\Academic\Actions\ResolvePendingPlacement;
use App\Modules\Academic\Events\CurriculumChanged;
use App\Modules\Academic\Models\Specialty;
use App\Modules\Academic\Models\StudyPlan;
use App\Modules\Academic\Models\StudyPlanSubject;
use App\Modules\Academic\Models\StudyPlanSubjectPending;
use App\Modules\Academic\Models\Subject;
use App\Modules\Academic\Queries\PendingPlacementsQuery;
use App\Modules\Core\Models\User;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;

/*
| Lugares de retícula pendientes de la migración del SII (D18 y D19): una materia del SII sin semestre y sin
| materia con el mismo nombre no entra a la retícula, queda en `study_plan_subject_pendings` y el jefe de
| Servicios Escolares le asigna semestre. Resolver crea el lugar con PlaceSubject (con su especialidad), marca
| quién y cuándo, deja bitácora y avisa con CurriculumChanged; un pendiente resuelto no se resuelve otra vez.
*/

beforeEach(function () {
    $this->plan = StudyPlan::factory()->create(['code' => 'ISIC-2010-224', 'semesters_count' => 9]);
    $this->user = User::factory()->create();
});

describe('ResolvePendingPlacement', function () {
    it('resolver un pendiente lo pone en la retícula con su semestre', function () {
        Event::fake([CurriculumChanged::class]);
        $specialty = Specialty::factory()->for($this->plan)->create();
        $pending = StudyPlanSubjectPending::factory()->for($this->plan)->create(['specialty_id' => $specialty->id]);

        $placement = app(ResolvePendingPlacement::class)->handle($pending, 4, $this->user);

        expect($placement)->toBeInstanceOf(StudyPlanSubject::class)
            ->and($placement->fresh())->study_plan_id->toBe($this->plan->id)->subject_id->toBe($pending->subject_id)
            ->semester->toBe(4)->specialty_id->toBe($specialty->id)
            ->and($pending->fresh())->resolved_at->not->toBeNull()->resolved_by->toBe($this->user->id)
            ->and(Activity::where('subject_type', (new StudyPlanSubjectPending)->getMorphClass())->where('subject_id', $pending->id)->count())->toBe(1);
        Event::assertDispatched(CurriculumChanged::class, fn (CurriculumChanged $event): bool => $event->studyPlanId === $this->plan->id);
    });

    it('un pendiente resuelto no se vuelve a resolver', function () {
        $pending = StudyPlanSubjectPending::factory()->for($this->plan)->create();
        app(ResolvePendingPlacement::class)->handle($pending, 2, $this->user);

        expect(fn () => app(ResolvePendingPlacement::class)->handle($pending->fresh(), 3, $this->user))->toThrow(ValidationException::class)
            ->and(StudyPlanSubject::where('subject_id', $pending->subject_id)->sole()->semester)->toBe(2);
    });

    it('rechaza un semestre fuera del plan sin tocar el pendiente', function () {
        $pending = StudyPlanSubjectPending::factory()->for($this->plan)->create();

        foreach ([0, 10] as $semester) {
            expect(fn () => app(ResolvePendingPlacement::class)->handle($pending, $semester, $this->user))->toThrow(ValidationException::class);
        }

        expect($pending->fresh()->resolved_at)->toBeNull()
            ->and(StudyPlanSubject::where('subject_id', $pending->subject_id)->exists())->toBeFalse();
    });
});

describe('PendingPlacementsQuery', function () {
    it('lista los pendientes sin resolver de un plan con los datos de su materia y cuenta por plan', function () {
        $other = StudyPlan::factory()->create();
        $first = StudyPlanSubjectPending::factory()->for($this->plan)->for(Subject::factory()->create(['code' => 'BBB-1'])->refresh(), 'subject')->create();
        StudyPlanSubjectPending::factory()->for($this->plan)->for(Subject::factory()->create(['code' => 'AAA-1']), 'subject')->create();
        $done = StudyPlanSubjectPending::factory()->for($this->plan)->create();
        app(ResolvePendingPlacement::class)->handle($done, 1, $this->user);
        StudyPlanSubjectPending::factory()->for($other)->create();

        $query = new PendingPlacementsQuery;
        $listed = $query->forPlan($this->plan);

        expect($listed->pluck('subject.code')->all())->toBe(['AAA-1', 'BBB-1'])
            ->and($listed->first()->relationLoaded('subject'))->toBeTrue()
            ->and($listed->contains('id', $first->id))->toBeTrue()
            ->and($query->countsByPlan())->toBe([$this->plan->id => 2, $other->id => 1]);
    });
});
