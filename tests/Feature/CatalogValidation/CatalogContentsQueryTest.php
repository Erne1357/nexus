<?php

use App\Modules\Academic\Models\Career;
use App\Modules\Academic\Models\Department;
use App\Modules\Academic\Models\StudyPlan;
use App\Modules\Academic\Models\Subject;
use App\Modules\CatalogValidation\Enums\Catalog;
use App\Modules\CatalogValidation\Enums\CatalogOwner;
use App\Modules\CatalogValidation\Models\CatalogReview;
use App\Modules\CatalogValidation\Models\CatalogReviewMark;
use App\Modules\CatalogValidation\Queries\CatalogContentsQuery;
use App\Modules\Core\Models\Campus;
use App\Modules\Facilities\Models\Building;
use App\Modules\Facilities\Models\Room;
use App\Modules\Faculty\Models\Teacher;
use App\Modules\Faculty\Models\TeacherDepartment;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/*
| CatalogContentsQuery: los registros que revisa el jefe en cada catálogo de su departamento (docs/dominio/
| modelo-de-datos.md, "CatalogValidation"), sin los borrados: docentes con una asignación sin borrar al
| departamento y salones del departamento en todos los campus (decisión 6, confirmada el 12 de noviembre de 2026); en
| las de Servicios Escolares, todas las materias, carreras y planes (de carreras sin borrar) del Tec (CV15). La foto de
| una validación son los activos.
*/

beforeEach(function () {
    $this->department = Department::factory()->create();
    $this->other = Department::factory()->create();
});

/**
 * La revisión del catálogo: la del departamento en docentes y salones, la de Servicios Escolares, sin departamento, en
 * materias, planes y carreras (CV15).
 */
function contentsReview(Catalog $catalog, Department $department): CatalogReview
{
    $factory = $catalog === Catalog::Rooms ? CatalogReview::factory()->rooms() : CatalogReview::factory()->general($catalog);

    return $factory->create($catalog->owner() === CatalogOwner::Department ? ['department_id' => $department->id] : []);
}

function contentsCodes(CatalogReview $review): array
{
    return array_column(app(CatalogContentsQuery::class)->records($review), 'code');
}

function roomIn(Campus $campus, array $attributes = []): Room
{
    return Room::factory()->for(Building::factory()->create(['campus_id' => $campus->id]))->create($attributes);
}

it('CV15: lista las materias de todo el Tec por clave, con su estado, sin las borradas', function () {
    Subject::factory()->create(['code' => 'B-2', 'name' => 'Química', 'department_id' => $this->department->id]);
    $inactive = Subject::factory()->inactive()->create(['code' => 'A-1', 'name' => 'Cálculo', 'department_id' => $this->department->id]);
    Subject::factory()->create(['department_id' => $this->department->id])->delete();
    $foreign = Subject::factory()->create(['code' => 'C-3', 'name' => 'Circuitos', 'department_id' => $this->other->id]);

    expect(app(CatalogContentsQuery::class)->records(contentsReview(Catalog::Subjects, $this->department)))->toBe([
        ['id' => $inactive->id, 'code' => 'A-1', 'name' => 'Cálculo', 'is_active' => false],
        ['id' => Subject::where('code', 'B-2')->sole()->id, 'code' => 'B-2', 'name' => 'Química', 'is_active' => true],
        ['id' => $foreign->id, 'code' => 'C-3', 'name' => 'Circuitos', 'is_active' => true],
    ]);
});

it('CV15: lista las carreras de todo el Tec', function () {
    Career::factory()->create(['code' => 'ISC', 'department_id' => $this->department->id]);
    Career::factory()->inactive()->create(['code' => 'IIA', 'department_id' => $this->department->id]);
    Career::factory()->create(['code' => 'IND', 'department_id' => $this->other->id]);

    expect(contentsCodes(contentsReview(Catalog::Careers, $this->department)))->toBe(['IIA', 'IND', 'ISC']);
});

it('CV15: lista los planes de las carreras sin borrar de todo el Tec', function () {
    $career = Career::factory()->create(['department_id' => $this->department->id]);
    StudyPlan::factory()->for($career)->create(['code' => 'ISIC-2010']);
    StudyPlan::factory()->for($career)->inactive()->create(['code' => 'ISIC-2004']);
    StudyPlan::factory()->for($career)->create()->delete();
    StudyPlan::factory()->for(Career::factory()->create(['department_id' => $this->other->id]))->create(['code' => 'INDU-2010']);
    StudyPlan::factory()->for(tap(Career::factory()->create())->delete())->create(['code' => 'OLD-2000']);

    expect(contentsCodes(contentsReview(Catalog::StudyPlans, $this->department)))->toBe(['INDU-2010', 'ISIC-2004', 'ISIC-2010']);
});

it('lista los docentes con una asignación sin borrar al departamento, aunque no sea la principal, por RFC', function () {
    Teacher::factory()->adscribedTo($this->department)->create(['rfc' => '200', 'name' => 'Ana']);
    Teacher::factory()->adscribedTo($this->other)->adscribedTo($this->department, primary: false)->create(['rfc' => '100']);
    Teacher::factory()->adscribedTo($this->department)->create()->delete();
    $removed = Teacher::factory()->adscribedTo($this->department)->create(['rfc' => '300']);
    TeacherDepartment::where('teacher_id', $removed->id)->sole()->delete();
    Teacher::factory()->adscribedTo($this->other)->create(['rfc' => '400']);

    $records = app(CatalogContentsQuery::class)->records(contentsReview(Catalog::Teachers, $this->department));

    expect(array_column($records, 'code'))->toBe(['100', '200'])
        ->and($records[1]['name'])->toBe('Ana');
});

it('lista los salones del departamento en todos los campus, con el campus en la clave (decisión 6)', function () {
    $campus = Campus::factory()->create(['code' => 'C2']);
    roomIn($campus, ['code' => 'A-2', 'department_id' => $this->department->id]);
    roomIn($campus, ['code' => 'A-1', 'department_id' => $this->department->id, 'is_active' => false]);
    roomIn($campus, ['code' => 'A-3', 'department_id' => $this->other->id]);
    roomIn($campus, ['code' => 'A-4']);
    roomIn(Campus::factory()->create(['code' => 'C1']), ['code' => 'A-1', 'department_id' => $this->department->id]);

    expect(contentsCodes(contentsReview(Catalog::Rooms, $this->department)))->toEqualCanonicalizing(['C2 · A-1', 'C1 · A-1', 'C2 · A-2']);
});

it('la foto lleva solo los activos, con id, clave y nombre', function () {
    $active = Career::factory()->create(['code' => 'ISC', 'name' => 'Sistemas', 'department_id' => $this->department->id]);
    Career::factory()->inactive()->create(['department_id' => $this->department->id]);

    expect(app(CatalogContentsQuery::class)->snapshot(contentsReview(Catalog::Careers, $this->department)))
        ->toBe([['id' => $active->id, 'code' => 'ISC', 'name' => 'Sistemas']]);
});

it('dice si un registro es de la revisión', function () {
    $review = contentsReview(Catalog::Subjects, $this->department);
    $own = Subject::factory()->inactive()->create(['department_id' => $this->department->id]);
    $deleted = tap(Subject::factory()->create(['department_id' => $this->department->id]))->delete();
    $foreign = Subject::factory()->create(['department_id' => $this->other->id]);
    $career = Career::factory()->create(['department_id' => $this->department->id]);
    $query = app(CatalogContentsQuery::class);

    expect($query->contains($review, $own))->toBeTrue()
        ->and($query->contains($review, $deleted))->toBeFalse()
        ->and($query->contains($review, $foreign))->toBeTrue()
        ->and($query->contains($review, $career))->toBeFalse();
});

it('busca un registro de la revisión por su id; uno ajeno o que no es uuid no existe', function () {
    $review = contentsReview(Catalog::Rooms, $this->department);
    $campus = Campus::factory()->create();
    $own = roomIn($campus, ['department_id' => $this->department->id]);
    $foreign = roomIn($campus, ['department_id' => $this->other->id]);
    $query = app(CatalogContentsQuery::class);

    expect($query->findOrFail($review, $own->id)->is($own))->toBeTrue()
        ->and(fn () => $query->findOrFail($review, $foreign->id))->toThrow(ModelNotFoundException::class)
        ->and(fn () => $query->findOrFail($review, 'no-es-uuid'))->toThrow(ModelNotFoundException::class);
});

describe('cuántos van revisados (CV7)', function () {
    it('cuenta los activos de la revisión y los que tienen marca, sin los inactivos ni las marcas de los que ya no están', function () {
        $review = contentsReview(Catalog::Subjects, $this->department);
        $marked = Subject::factory()->create(['department_id' => $this->department->id]);
        Subject::factory()->create(['department_id' => $this->department->id]);
        $inactive = Subject::factory()->inactive()->create(['department_id' => $this->department->id]);
        CatalogReviewMark::factory()->for($review, 'review')->on($inactive)->create();
        $gone = Subject::factory()->create(['department_id' => $this->department->id]);
        CatalogReviewMark::factory()->for($review, 'review')->on($marked)->create();
        CatalogReviewMark::factory()->for($review, 'review')->on($gone)->create();
        $gone->delete();

        $query = app(CatalogContentsQuery::class);

        expect($query->progress($review))->toBe(['reviewed' => 1, 'total' => 2])
            ->and($query->unreviewedCount($review))->toBe(1);
    });

    it('cuenta varias revisiones de cada catálogo a la vez, cada una con su departamento', function () {
        $campus = Campus::factory()->create();
        $teachers = contentsReview(Catalog::Teachers, $this->department);
        $otherTeachers = contentsReview(Catalog::Teachers, $this->other);
        $plans = contentsReview(Catalog::StudyPlans, $this->department);
        $rooms = contentsReview(Catalog::Rooms, $this->department);
        $teacher = Teacher::factory()->adscribedTo($this->department)->adscribedTo($this->other, primary: false)->create();
        Teacher::factory()->adscribedTo($this->department)->create();
        $plan = StudyPlan::factory()->for(Career::factory()->create(['department_id' => $this->department->id]))->create();
        $room = roomIn($campus, ['department_id' => $this->department->id]);
        roomIn(Campus::factory()->create(), ['department_id' => $this->department->id]);
        CatalogReviewMark::factory()->for($teachers, 'review')->on($teacher)->create();
        CatalogReviewMark::factory()->for($plans, 'review')->on($plan)->create();
        CatalogReviewMark::factory()->for($rooms, 'review')->on($room)->create();

        expect(app(CatalogContentsQuery::class)->progressOf(collect([$teachers, $otherTeachers, $plans, $rooms])))->toBe([
            $teachers->id => ['reviewed' => 1, 'total' => 2],
            $otherTeachers->id => ['reviewed' => 0, 'total' => 1],
            $plans->id => ['reviewed' => 1, 'total' => 1],
            $rooms->id => ['reviewed' => 1, 'total' => 2],
        ]);
    });

    it('dice si todos los ids son de la revisión', function () {
        $review = contentsReview(Catalog::Teachers, $this->department);
        $own = Teacher::factory()->adscribedTo($this->department)->create();
        $foreign = Teacher::factory()->adscribedTo($this->other)->create();
        $query = app(CatalogContentsQuery::class);

        expect($query->containsAll($review, [$own->id]))->toBeTrue()
            ->and($query->containsAll($review, [$own->id, $foreign->id]))->toBeFalse()
            ->and($query->containsAll($review, ['no-es-uuid']))->toBeFalse();
    });
});
