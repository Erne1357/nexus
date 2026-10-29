<?php

use App\Modules\Academic\Actions\UpdateStudyPlan;
use App\Modules\Academic\Actions\UpdateSubject;
use App\Modules\Academic\Events\CurriculumChanged;
use App\Modules\Academic\Events\PlanCareerChanging;
use App\Modules\Academic\Events\SubjectDeactivating;
use App\Modules\Academic\Models\Career;
use App\Modules\Academic\Models\StudyPlan;
use App\Modules\Academic\Models\Subject;
use App\Modules\Students\Enums\StudentStatus;
use App\Modules\Students\Jobs\RecomputeStudentProgress;
use App\Modules\Students\Listeners\BlockPlanCareerChangeWithStudents;
use App\Modules\Students\Listeners\BlockSubjectDeactivationWhileNeeded;
use App\Modules\Students\Listeners\RecomputeProgressOnCurriculumChange;
use App\Modules\Students\Models\KardexRecord;
use App\Modules\Students\Models\Student;
use App\Modules\Students\Models\StudentProgress;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;

/*
| Lo que Students escucha de Academic (Academic no depende de Students): veta desactivar una materia
| que todavía necesitan alumnos (SubjectDeactivating, reglas-de-negocio.md, "Elegibilidad y
| demanda") y recalcula el avance cuando cambia la retícula o una materia (CurriculumChanged).
*/

beforeEach(function () {
    $this->isc = StudyPlan::factory()->create(['code' => 'ISIC-2010-224']);
    $this->calculo = Subject::factory()->create(['code' => 'ACF-0901']);
    placeInPlan($this->isc, $this->calculo);
});

function deactivationErrors(Subject $subject): array
{
    try {
        app(UpdateSubject::class)->handle($subject, ['is_active' => false]);
    } catch (ValidationException $exception) {
        return $exception->errors();
    }

    test()->fail('Debió rechazar la desactivación.');
}

it('registra sus listeners en StudentsServiceProvider', function () {
    Event::fake();

    Event::assertListening(SubjectDeactivating::class, BlockSubjectDeactivationWhileNeeded::class);
    Event::assertListening(PlanCareerChanging::class, BlockPlanCareerChangeWithStudents::class);
    Event::assertListening(CurriculumChanged::class, RecomputeProgressOnCurriculumChange::class);
});

describe('veto al desactivar una materia', function () {
    it('con un alumno que la necesita, rechaza con cuántos y de qué plan en is_active, y no la desactiva', function () {
        Student::factory()->for($this->isc)->create(['career_id' => $this->isc->career_id]);

        expect(deactivationErrors($this->calculo))->toBe([
            'is_active' => ['La necesita 1 alumno (activo o en baja temporal) del plan ISIC-2010-224.'],
        ])->and($this->calculo->fresh()->is_active)->toBeTrue();
    });

    it('el número de alumnos y el de planes van en plural cada uno por su lado', function () {
        Student::factory()->for($this->isc)->count(3)->create(['career_id' => $this->isc->career_id]);

        expect(deactivationErrors($this->calculo)['is_active'][0])
            ->toBe('La necesitan 3 alumnos (activos o en baja temporal) del plan ISIC-2010-224.');
    });

    it('junta a los alumnos de todos los planes que la tienen', function () {
        $iia = StudyPlan::factory()->create(['code' => 'IIND-2010-227']);
        placeInPlan($iia, $this->calculo);
        Student::factory()->for($this->isc)->create(['career_id' => $this->isc->career_id, 'status' => StudentStatus::BajaTemporal]);
        Student::factory()->for($iia)->count(2)->create(['career_id' => $iia->career_id]);

        expect(deactivationErrors($this->calculo)['is_active'][0])
            ->toBe('La necesitan 3 alumnos (activos o en baja temporal) de los planes IIND-2010-227, ISIC-2010-224.');
    });

    it('sin alumnos que la necesiten, la desactiva', function () {
        Student::factory()->for($this->isc)->create(['career_id' => $this->isc->career_id, 'status' => StudentStatus::Egresado]);

        app(UpdateSubject::class)->handle($this->calculo, ['is_active' => false]);

        expect($this->calculo->fresh()->is_active)->toBeFalse();
    });

    it('justo después de importar el kárdex usa el actual, sin esperar el recálculo del avance', function () {
        $student = Student::factory()->for($this->isc)->create(['career_id' => $this->isc->career_id]);
        StudentProgress::factory()->for($student)->create(['subjects_pending' => [$this->calculo->id]]);
        KardexRecord::factory()->for($student)->for($this->calculo)->create();

        app(UpdateSubject::class)->handle($this->calculo, ['is_active' => false]);

        expect($this->calculo->fresh()->is_active)->toBeFalse();
    });
});

describe('recálculo del avance', function () {
    it('CurriculumChanged encola RecomputeStudentProgress, por plan o por materia', function (array $arguments) {
        Queue::fake();

        event(new CurriculumChanged(...$arguments));

        Queue::assertPushed(RecomputeStudentProgress::class, 1);
    })->with([
        'materia' => [fn () => ['subjectId' => fakeKey(1)]],
        'plan' => [fn () => ['studyPlanId' => fakeKey(2)]],
    ]);

    it('cambiar los créditos de una materia encola el recálculo después del commit', function () {
        Queue::fake();

        app(UpdateSubject::class)->handle($this->calculo, ['credits' => 6]);

        Queue::assertPushed(RecomputeStudentProgress::class, 1);
    });

    it('cambiar solo el nombre no encola nada', function () {
        Queue::fake();

        app(UpdateSubject::class)->handle($this->calculo, ['name' => 'Cálculo']);

        Queue::assertNothingPushed();
    });
});

describe('veto al cambiar la carrera de un plan', function () {
    it('con alumnos en el plan rechaza con cuántos en career_id y no cambia la carrera', function () {
        $otra = Career::factory()->create();
        Student::factory()->for($this->isc)->count(3)->create(['career_id' => $this->isc->career_id]);

        try {
            app(UpdateStudyPlan::class)->handle($this->isc, ['career_id' => $otra->id]);
            test()->fail('Debió rechazar el cambio de carrera.');
        } catch (ValidationException $exception) {
            expect($exception->errors())->toBe(['career_id' => ['Tiene 3 alumnos inscritos en este plan.']]);
        }

        expect($this->isc->fresh()->career_id)->not->toBe($otra->id);
    });

    it('sin alumnos en el plan no bloquea', function () {
        $otra = Career::factory()->create();

        app(UpdateStudyPlan::class)->handle($this->isc, ['career_id' => $otra->id]);

        expect($this->isc->fresh()->career_id)->toBe($otra->id);
    });

    it('cambiar los créditos totales del plan encola el recálculo después del commit', function () {
        Queue::fake();

        app(UpdateStudyPlan::class)->handle($this->isc, ['total_credits' => 999]);

        Queue::assertPushed(RecomputeStudentProgress::class, 1);
    });
});
