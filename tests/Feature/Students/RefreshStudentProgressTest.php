<?php

use App\Modules\Academic\Models\StudyPlan;
use App\Modules\Academic\Models\Subject;
use App\Modules\Students\Actions\RefreshStudentProgress;
use App\Modules\Students\Jobs\RecomputeStudentProgress;
use App\Modules\Students\Models\KardexRecord;
use App\Modules\Students\Models\Student;
use App\Modules\Students\Models\StudentProgress;
use Illuminate\Support\Facades\Queue;

/*
| RefreshStudentProgress recalcula `student_progress` de todos los alumnos desde su kardex (tarea 1.4), y el
| job RecomputeStudentProgress lo corre en la cola al terminar una importación de alumnos o de kardex. Solo
| `aprobada` aprueba: cursando, reprobada y baja no (reglas-de-negocio.md, "Elegibilidad y demanda").
*/

beforeEach(function () {
    // Plan con Cálculo (A) y Física (F) de primero, y Cálculo II (B) de segundo, que pide A.
    $this->plan = StudyPlan::factory()->create();
    $this->a = Subject::factory()->create(['code' => 'A-1', 'credits' => 5]);
    $this->f = Subject::factory()->create(['code' => 'F-1', 'credits' => 4]);
    $this->b = Subject::factory()->create(['code' => 'B-2', 'credits' => 5]);
    placeInPlan($this->plan, $this->a);
    placeInPlan($this->plan, $this->f);
    placeInPlan($this->plan, $this->b, 2);
    seriate($this->plan, $this->b, $this->a);
    $this->student = Student::factory()->for($this->plan)->create(['career_id' => $this->plan->career_id]);
});

function kardexOf(Student $student, Subject $subject, string $term = '20253'): KardexRecord
{
    return KardexRecord::factory()->for($student)->for($subject)->create(['term_code' => $term]);
}

it('calcula el avance de cada alumno desde su kardex: lo que está cursando todavía no está aprobado', function () {
    kardexOf($this->student, $this->a);
    KardexRecord::factory()->for($this->student)->for($this->f)->inProgress()->create(['term_code' => '20261']);

    app(RefreshStudentProgress::class)->handle();

    expect(StudentProgress::where('student_id', $this->student->id)->sole())
        ->study_plan_id->toBe($this->plan->id)
        ->credits_approved->toBe(5)
        ->subjects_approved->toBe(1)
        ->subjects_pending->toBe([$this->f->id, $this->b->id])
        ->subjects_available->toBe([$this->f->id, $this->b->id])
        ->computed_at->not->toBeNull();
});

it('una materia reprobada y después aprobada cuenta como aprobada; reprobada o dada de baja, no', function () {
    KardexRecord::factory()->for($this->student)->for($this->a)->failed()->create(['term_code' => '20251']);
    KardexRecord::factory()->for($this->student)->for($this->a)->create(['term_code' => '20253', 'attempt' => 2]);
    KardexRecord::factory()->for($this->student)->for($this->f)->failed()->create();

    app(RefreshStudentProgress::class)->handle();

    expect(StudentProgress::where('student_id', $this->student->id)->sole())
        ->subjects_approved->toBe(1)
        ->subjects_available->toBe([$this->f->id, $this->b->id]);
});

it('un alumno sin kardex tiene todo pendiente, y no cuenta las filas de kardex borradas', function () {
    kardexOf($this->student, $this->a)->delete();

    app(RefreshStudentProgress::class)->handle();

    expect(StudentProgress::where('student_id', $this->student->id)->sole())
        ->credits_approved->toBe(0)
        ->subjects_pending->toBe([$this->a->id, $this->f->id, $this->b->id])
        ->subjects_available->toBe([$this->a->id, $this->f->id]);
});

it('volver a calcular reescribe el avance sin duplicarlo, también si el alumno cambió de plan', function () {
    app(RefreshStudentProgress::class)->handle();
    kardexOf($this->student, $this->a);
    $other = StudyPlan::factory()->for($this->plan->career)->create();
    placeInPlan($other, $this->a);
    $this->student->update(['study_plan_id' => $other->id]);

    app(RefreshStudentProgress::class)->handle();

    expect(StudentProgress::where('student_id', $this->student->id)->sole())
        ->study_plan_id->toBe($other->id)
        ->credits_approved->toBe(5)
        ->subjects_pending->toBe([]);
});

it('no calcula el avance de un alumno borrado', function () {
    $this->student->delete();

    app(RefreshStudentProgress::class)->handle();

    expect(StudentProgress::count())->toBe(0);
});

it('el job lo recalcula en la cola, con un solo intento y su tiempo máximo', function () {
    kardexOf($this->student, $this->a);

    RecomputeStudentProgress::dispatch();

    expect(StudentProgress::where('student_id', $this->student->id)->sole()->credits_approved)->toBe(5)
        ->and(new RecomputeStudentProgress)
        ->tries->toBe(1)
        ->timeout->toBe(1800);
});

it('espera un solo recálculo en la cola aunque lleguen varias importaciones', function () {
    Queue::fake();

    RecomputeStudentProgress::dispatch();
    RecomputeStudentProgress::dispatch();

    Queue::assertPushed(RecomputeStudentProgress::class, 1);
});
