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
| de su departamento (CV1) y sus salones en el campus de la revisión (CV2) con la Action del módulo dueño,
| mientras la revisión no está validada, sin cambiar lo que ata el registro al SII o a su departamento (CV-S3);
| la primera corrección la pasa a en revisión (CV-S4). Los docentes se corrigen en Faculty.
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

    cvCorrect($review, $plan, ['code' => 'OTRA', 'career_id' => Career::factory()->create(['department_id' => $this->department->id])->id, 'name' => 'Plan 2010', 'is_active' => false]);

    expect($plan->fresh())
        ->code->toBe('ISIC-2010')
        ->career_id->toBe($career->id)
        ->name->toBe('Plan 2010')
        ->is_active->toBeFalse();
});

it('CV2: corrige un salón del campus de la revisión sin cambiar su clave, su dueño ni su edificio', function () {
    $review = cvReviewOf(Catalog::Rooms);
    $building = Building::factory()->create(['campus_id' => $review->campus_id]);
    $room = Room::factory()->for($building)->create(['code' => 'A-1', 'capacity' => 30, 'department_id' => $this->department->id]);

    cvCorrect($review, $room, [
        'code' => 'B-1',
        'department_id' => $this->elsewhere->id,
        'building_id' => Building::factory()->create(['campus_id' => $review->campus_id])->id,
        'campus_id' => Campus::factory()->create()->id,
        'capacity' => 40,
    ]);

    expect($room->fresh())
        ->code->toBe('A-1')
        ->department_id->toBe($this->department->id)
        ->building_id->toBe($building->id)
        ->campus_id->toBe($review->campus_id)
        ->capacity->toBe(40);
});

it('sin cambios no escribe ni pasa la revisión a en revisión', function () {
    $review = cvReviewOf(Catalog::Careers);
    $career = Career::factory()->create(['code' => 'ISC', 'department_id' => $this->department->id]);

    cvCorrect($review, $career, ['code' => 'OTRA', 'name' => $career->name]);

    expect($review->fresh()->status)->toBe(ReviewStatus::Pending)
        ->and(Activity::count())->toBe(0);
});

it('rechaza un registro que no es de la revisión', function (Closure $record) {
    expect(cvCorrectErrors(cvReviewOf(Catalog::Subjects), $record(), ['name' => 'Otro']))
        ->toBe(['record' => ['El registro no es de esta revisión.']])
        ->and(Activity::count())->toBe(0);
})->with([
    'materia de otro departamento' => fn () => Subject::factory()->create(['department_id' => test()->elsewhere->id]),
    'materia borrada' => fn () => tap(Subject::factory()->create(['department_id' => test()->department->id]))->delete(),
]);

it('rechaza un salón del departamento en otro campus', function () {
    $review = cvReviewOf(Catalog::Rooms);
    $room = Room::factory()->create(['department_id' => $this->department->id]);

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

it('los docentes no se corrigen aquí: se corrigen en Docentes', function () {
    $review = cvReviewOf(Catalog::Teachers);
    $teacher = Teacher::factory()->adscribedTo($this->department)->create();

    expect(cvCorrectErrors($review, $teacher, ['name' => 'Otro']))->toBe(['record' => ['Los docentes se corrigen en Docentes.']]);
});

it('si el módulo dueño rechaza la corrección, no queda nada', function () {
    $review = cvReviewOf(Catalog::Rooms);
    $room = Room::factory()->for(Building::factory()->create(['campus_id' => $review->campus_id]))->create(['department_id' => $this->department->id]);

    expect(cvCorrectErrors($review, $room, ['name' => 'Aula magna', 'capacity' => 0]))->toBe(['capacity' => ['La capacidad debe ser mayor que cero.']])
        ->and($room->fresh()->name)->toBeNull()
        ->and($review->fresh()->status)->toBe(ReviewStatus::Pending);
});
