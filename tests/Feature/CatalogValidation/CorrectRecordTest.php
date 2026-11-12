<?php

use App\Modules\Academic\Models\Career;
use App\Modules\Academic\Models\Department;
use App\Modules\Academic\Models\StudyPlan;
use App\Modules\Academic\Models\Subject;
use App\Modules\CatalogValidation\Actions\CorrectRecord;
use App\Modules\CatalogValidation\Enums\Catalog;
use App\Modules\CatalogValidation\Enums\ReviewStatus;
use App\Modules\CatalogValidation\Models\CatalogReview;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\User;
use App\Modules\Facilities\Models\Building;
use App\Modules\Facilities\Models\Room;
use App\Modules\Faculty\Models\Teacher;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;

/*
| CorrectRecord (reglas-de-negocio.md, "Validación de catálogos"): el jefe corrige materias, carreras y planes
| de su departamento y el correo institucional de sus docentes (CV1) y sus salones en cualquier campus (CV2) con la
| Action del módulo dueño, mientras la revisión no está validada, sin cambiar lo que ata el registro al SII o a su
| departamento (CV-S3); la primera corrección la pasa a en revisión (CV-S4).
*/

beforeEach(function () {
    $this->actor = User::factory()->create();
    $this->actingAs($this->actor);
    $this->department = Department::factory()->create();
    $this->elsewhere = Department::factory()->create();
});

function cvReviewOf(Catalog $catalog, array $attributes = []): CatalogReview
{
    $factory = $catalog === Catalog::Rooms ? CatalogReview::factory()->rooms() : CatalogReview::factory()->general($catalog);

    return $factory->create(['department_id' => test()->department->id, ...$attributes]);
}

function cvCorrect(CatalogReview $review, mixed $record, array $data): mixed
{
    return app(CorrectRecord::class)->handle($review, $record, $data, test()->actor);
}

function cvCorrectErrors(CatalogReview $review, mixed $record, array $data): array
{
    try {
        cvCorrect($review, $record, $data);
    } catch (ValidationException $exception) {
        return $exception->errors();
    }

    return [];
}

it('CV1: corrige una materia con UpdateSubject, sin cambiar su clave ni su departamento (CV-S3), y pasa la revisión a en revisión', function () {
    $review = cvReviewOf(Catalog::Subjects);
    $subject = Subject::factory()->create(['code' => 'AEB-1011', 'department_id' => $this->department->id, 'credits' => 5]);

    cvCorrect($review, $subject, ['code' => 'OTRA', 'department_id' => $this->elsewhere->id, 'name' => 'Cálculo diferencial', 'credits' => 6, 'is_active' => false]);

    expect($subject->fresh())
        ->code->toBe('AEB-1011')
        ->department_id->toBe($this->department->id)
        ->name->toBe('Cálculo diferencial')
        ->credits->toBe(6)
        ->is_active->toBeFalse()
        ->and($review->fresh()->status)->toBe(ReviewStatus::InReview)
        ->and(Activity::query()->where('subject_id', $subject->id)->sole()->causer->is($this->actor))->toBeTrue();
});

it('CV1: corrige una carrera sin cambiar su clave ni su departamento', function () {
    $review = cvReviewOf(Catalog::Careers);
    $career = Career::factory()->create(['code' => 'ISC', 'department_id' => $this->department->id]);

    cvCorrect($review, $career, ['code' => 'OTRA', 'department_id' => $this->elsewhere->id, 'name' => 'Ingeniería en Sistemas']);

    expect($career->fresh())
        ->code->toBe('ISC')
        ->department_id->toBe($this->department->id)
        ->name->toBe('Ingeniería en Sistemas');
});

it('CV1: corrige un plan sin cambiar su clave ni su carrera', function () {
    $review = cvReviewOf(Catalog::StudyPlans);
    $career = Career::factory()->create(['department_id' => $this->department->id]);
    $plan = StudyPlan::factory()->for($career)->create(['code' => 'ISIC-2010']);

    cvCorrect($review, $plan, ['code' => 'OTRA', 'career_id' => Career::factory()->create(['department_id' => $this->department->id])->id, 'name' => 'Plan 2010']);

    expect($plan->fresh())
        ->code->toBe('ISIC-2010')
        ->career_id->toBe($career->id)
        ->name->toBe('Plan 2010');
});

it('CV2: corrige capacidad, tipo y edificio de un salón, también a uno de otro campus (el campus sigue al edificio), sin cambiar su clave ni su dueño (CV-S3)', function () {
    $review = cvReviewOf(Catalog::Rooms);
    $c2 = Campus::factory()->create(['code' => 'C2']);
    $c1 = Campus::factory()->create(['code' => 'C1']);
    $room = Room::factory()->for(Building::factory()->create(['campus_id' => $c2->id]))->create(['code' => 'A-1', 'capacity' => 30, 'department_id' => $this->department->id]);
    $elsewhere = Building::factory()->create(['campus_id' => $c1->id]);

    cvCorrect($review, $room, [
        'code' => 'B-1',
        'department_id' => $this->elsewhere->id,
        'building_id' => $elsewhere->id,
        'campus_id' => Campus::factory()->create()->id,
        'capacity' => 40,
        'type' => 'laboratorio',
    ]);

    expect($room->fresh())
        ->code->toBe('A-1')
        ->department_id->toBe($this->department->id)
        ->building_id->toBe($elsewhere->id)
        ->campus_id->toBe($c1->id)
        ->capacity->toBe(40)
        ->type->value->toBe('laboratorio');
});

it('sin cambios no escribe ni pasa la revisión a en revisión', function () {
    $review = cvReviewOf(Catalog::Careers);
    $career = Career::factory()->create(['code' => 'ISC', 'department_id' => $this->department->id]);

    cvCorrect($review, $career, ['code' => 'OTRA', 'name' => $career->name]);

    expect($review->fresh()->status)->toBe(ReviewStatus::Pending)
        ->and(Activity::count())->toBe(0);
});

it('CV-S3: el jefe no activa ni desactiva carreras ni planes; lo resuelve el admin', function () {
    $career = Career::factory()->create(['department_id' => $this->department->id]);
    $plan = StudyPlan::factory()->for($career)->create();

    cvCorrect(cvReviewOf(Catalog::Careers), $career, ['name' => 'Otra', 'is_active' => false]);
    cvCorrect(cvReviewOf(Catalog::StudyPlans), $plan, ['name' => 'Otro plan', 'is_active' => false]);

    expect($career->fresh())->name->toBe('Otra')->is_active->toBeTrue()
        ->and($plan->fresh())->name->toBe('Otro plan')->is_active->toBeTrue();
});

it('un modelo que ya se guardó antes en la petición y una corrección sin cambios no pasan la revisión a en revisión', function () {
    $review = cvReviewOf(Catalog::Careers);
    $career = Career::factory()->create(['department_id' => $this->department->id]);
    $career->update(['name' => 'Cambiada antes']);

    cvCorrect($review, $career, ['name' => 'Cambiada antes']);

    expect($review->fresh()->status)->toBe(ReviewStatus::Pending);
});

it('rechaza un registro que no es de la revisión', function (Closure $record) {
    expect(cvCorrectErrors(cvReviewOf(Catalog::Subjects), $record(), ['name' => 'Otro']))
        ->toBe(['record' => ['El registro no es de esta revisión.']])
        ->and(Activity::count())->toBe(0);
})->with([
    'materia de otro departamento' => fn () => Subject::factory()->create(['department_id' => test()->elsewhere->id]),
    'materia borrada' => fn () => tap(Subject::factory()->create(['department_id' => test()->department->id]))->delete(),
]);

it('rechaza un salón de otro departamento', function () {
    $review = cvReviewOf(Catalog::Rooms);
    $room = Room::factory()->create(['department_id' => $this->elsewhere->id]);

    expect(cvCorrectErrors($review, $room, ['capacity' => 40]))->toBe(['record' => ['El registro no es de esta revisión.']])
        ->and($room->fresh()->capacity)->toBe(30);
});

it('CV-S2: rechaza corregir una revisión validada', function () {
    $review = cvReviewOf(Catalog::Careers, ['status' => ReviewStatus::Validated, 'validated_by' => $this->actor->id, 'validated_at' => now()]);
    $career = Career::factory()->create(['department_id' => $this->department->id]);

    expect(cvCorrectErrors($review, $career, ['name' => 'Otro']))
        ->toBe(['status' => ['La revisión ya está validada: reábrela para corregir u observar.']])
        ->and($career->fresh()->name)->not->toBe('Otro');
});

it('CV1: de un docente solo corrige el correo institucional', function () {
    $review = cvReviewOf(Catalog::Teachers);
    $teacher = Teacher::factory()->adscribedTo($this->department)->create(['name' => 'Ana']);

    cvCorrect($review, $teacher, ['name' => 'Otro', 'institutional_email' => 'ana@cdjuarez.tecnm.mx', 'rfc' => 'XXXX000000XX0']);

    expect($teacher->fresh())->name->toBe('Ana')->institutional_email->toBe('ana@cdjuarez.tecnm.mx');
});

it('si el módulo dueño rechaza la corrección, no queda nada', function () {
    $review = cvReviewOf(Catalog::Rooms);
    $room = Room::factory()->create(['department_id' => $this->department->id]);

    expect(cvCorrectErrors($review, $room, ['name' => 'Aula magna', 'capacity' => 0]))->toBe(['capacity' => ['La capacidad debe ser mayor que cero.']])
        ->and($room->fresh()->name)->toBeNull()
        ->and($review->fresh()->status)->toBe(ReviewStatus::Pending);
});
