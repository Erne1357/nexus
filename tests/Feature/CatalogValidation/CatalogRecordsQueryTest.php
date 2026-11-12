<?php

use App\Modules\Academic\Models\Department;
use App\Modules\Academic\Models\Subject;
use App\Modules\CatalogValidation\Enums\Catalog;
use App\Modules\CatalogValidation\Enums\ReviewFlag;
use App\Modules\CatalogValidation\Models\CatalogReview;
use App\Modules\CatalogValidation\Models\CatalogReviewFlag;
use App\Modules\CatalogValidation\Models\CatalogReviewMark;
use App\Modules\CatalogValidation\Queries\CatalogRecordsQuery;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\User;
use App\Modules\Facilities\Models\Building;
use App\Modules\Facilities\Models\Room;
use App\Modules\Faculty\Models\Teacher;

/*
| CatalogRecordsQuery para la revisión por registro (opción A, 12 de noviembre de 2026): el filtro segmentado y sus
| cifras (CV7: «Por revisar» son los activos sin marca), los pendientes para elegirlos juntos, quién marcó, los otros
| departamentos de cada docente (CV8), los docentes que «Agregar docente» ofrece (CV-S9), los filtros de campus y
| edificio de salones (decisión 6) y los docentes sin correo institucional (AC-06).
*/

beforeEach(function () {
    $this->department = Department::factory()->create(['code' => 'SIS', 'name' => 'Sistemas y Computación']);
    $this->other = Department::factory()->create(['code' => 'ELE', 'name' => 'Eléctrica-Electrónica']);
    $this->records = app(CatalogRecordsQuery::class);
});

function recordsReview(Catalog $catalog, Department $department): CatalogReview
{
    return ($catalog === Catalog::Rooms ? CatalogReview::factory()->rooms() : CatalogReview::factory()->general($catalog))->create(['department_id' => $department->id]);
}

/**
 * @return list<string>
 */
function pageCodes(CatalogReview $review, string $reviewed = '', string $flag = '', string $campusId = '', string $buildingId = ''): array
{
    return app(CatalogRecordsQuery::class)->page($review, flag: $flag, reviewed: $reviewed, campusId: $campusId, buildingId: $buildingId)->getCollection()->pluck('code')->all();
}

it('CV7: «Por revisar» son los activos sin marca; las cifras del filtro segmentado son de todo el catálogo', function () {
    $review = recordsReview(Catalog::Subjects, $this->department);
    $marked = Subject::factory()->create(['code' => 'A-1', 'department_id' => $this->department->id]);
    $flagged = Subject::factory()->create(['code' => 'B-2', 'department_id' => $this->department->id]);
    Subject::factory()->create(['code' => 'C-3', 'department_id' => $this->department->id]);
    $inactiveMarked = Subject::factory()->inactive()->create(['code' => 'D-4', 'department_id' => $this->department->id]);
    Subject::factory()->inactive()->create(['code' => 'E-5', 'department_id' => $this->department->id]);
    Subject::factory()->create(['code' => 'Z-9', 'department_id' => $this->other->id]);
    CatalogReviewMark::factory()->for($review, 'review')->on($marked)->create();
    CatalogReviewMark::factory()->for($review, 'review')->on($inactiveMarked)->create();
    CatalogReviewFlag::factory()->on($flagged)->create(['review_id' => $review->id, 'flag' => ReviewFlag::MissingData]);
    CatalogReviewFlag::factory()->on($marked)->resolved()->create(['review_id' => $review->id]);

    expect(pageCodes($review, 'pending'))->toBe(['B-2', 'C-3'])
        ->and(pageCodes($review, 'reviewed'))->toBe(['A-1', 'D-4'])
        ->and(pageCodes($review, flag: 'with'))->toBe(['B-2'])
        ->and($this->records->segmentCounts($review))->toBe(['pending' => 2, 'reviewed' => 2, 'flagged' => 1, 'all' => 5])
        ->and($this->records->pendingIds($review))->toBe([$flagged->id, Subject::where('code', 'C-3')->sole()->id])
        ->and($this->records->pendingIds($review, 'C-3'))->toBe([Subject::where('code', 'C-3')->sole()->id])
        ->and($this->records->pendingIds($review, onlyIds: [$flagged->id, $marked->id]))->toBe([$flagged->id]);
});

it('CV7: dice quién marcó cada registro y cuándo', function () {
    $review = recordsReview(Catalog::Subjects, $this->department);
    $subject = Subject::factory()->create(['department_id' => $this->department->id]);
    $other = Subject::factory()->create(['department_id' => $this->department->id]);
    $laura = User::factory()->create(['name' => 'Laura Ramos']);
    CatalogReviewMark::factory()->for($review, 'review')->on($subject)->create(['reviewed_by' => $laura->id, 'reviewed_at' => '2026-11-10 12:40:00']);

    $marks = $this->records->marksOf($review, [$subject->id, $other->id]);

    expect(array_keys($marks))->toBe([$subject->id])
        ->and($marks[$subject->id]['by'])->toBe('Laura Ramos')
        ->and($marks[$subject->id]['at']->format('Y-m-d H:i'))->toBe('2026-11-10 12:40');
});

it('CV8: da los otros departamentos de cada docente, la principal primero, y si la del departamento es su principal', function () {
    $review = recordsReview(Catalog::Teachers, $this->department);
    $third = Department::factory()->create(['code' => 'CB', 'name' => 'Ciencias Básicas']);
    $alone = Teacher::factory()->adscribedTo($this->department)->create();
    $here = Teacher::factory()->adscribedTo($this->department)->adscribedTo($this->other, primary: false)->create();
    $there = Teacher::factory()->adscribedTo($third, primary: false)->adscribedTo($this->other)->adscribedTo($this->department, primary: false)->create();

    $departments = $this->records->teacherDepartments($review, [$alone->id, $here->id, $there->id]);

    $tied = Teacher::factory()->adscribedTo($this->department)->create();
    $sameTime = now()->subDay();
    $first = $tied->adscriptions()->create(['department_id' => $this->other->id, 'is_primary' => false]);
    $second = $tied->adscriptions()->create(['department_id' => $third->id, 'is_primary' => false]);
    $first->forceFill(['created_at' => $sameTime])->save();
    $second->forceFill(['created_at' => $sameTime])->save();
    $byId = strcmp($first->id, $second->id) < 0 ? ['ELE', 'CB'] : ['CB', 'ELE'];

    expect(array_column($this->records->teacherDepartments($review, [$tied->id])[$tied->id]['others'], 'code'))->toBe($byId)
        ->and($departments[$alone->id])->toBe(['here_primary' => true, 'others' => []])
        ->and($departments[$here->id])->toBe(['here_primary' => true, 'others' => [['code' => 'ELE', 'name' => 'Eléctrica-Electrónica', 'is_primary' => false]]])
        ->and($departments[$there->id]['here_primary'])->toBeFalse()
        ->and(array_column($departments[$there->id]['others'], 'code'))->toBe(['ELE', 'CB']);
});

it('CV-S9: ofrece a los docentes activos y dados de baja por RFC o nombre, desde dos letras y hasta el límite', function () {
    Teacher::factory()->create(['rfc' => 'PEMR680914XX4', 'name' => 'Ricardo Peña Montoya', 'is_active' => false]);
    Teacher::factory()->adscribedTo($this->other)->create(['rfc' => 'PEGS750203XX7', 'name' => 'Susana Peña Gil']);
    Teacher::factory()->create(['rfc' => 'BORR800101XX1', 'name' => 'Borrado Peña'])->delete();
    Teacher::factory()->count(3)->sequence(['name' => 'Ana Peña'], ['name' => 'Beto Peña'], ['name' => 'Ciro Peña'])->create();

    expect($this->records->teacherCandidates('p')['teachers'])->toBeEmpty()
        ->and($this->records->teacherCandidates(' pegs750203 ')['teachers']->pluck('name')->all())->toBe(['Susana Peña Gil'])
        ->and($this->records->teacherCandidates('peña', 4))->sequence(
            fn ($teachers) => $teachers->pluck('name')->all()->toBe(['Ana Peña', 'Beto Peña', 'Ciro Peña', 'Ricardo Peña Montoya']),
            fn ($more) => $more->toBeTrue(),
        )
        ->and($this->records->teacherCandidates('susana')['teachers']->first()->adscriptions->first()->department->code)->toBe('ELE');
});

it('decisión 6: filtra los salones por campus y por edificio, con las opciones de los salones de la revisión', function () {
    $review = recordsReview(Catalog::Rooms, $this->department);
    $c1 = Campus::factory()->create(['code' => 'C1', 'name' => 'Campus I']);
    $c2 = Campus::factory()->create(['code' => 'C2', 'name' => 'Campus II']);
    $lc = Building::factory()->create(['campus_id' => $c1->id, 'code' => 'LC', 'name' => 'Laboratorio de cómputo']);
    $e2 = Building::factory()->create(['campus_id' => $c2->id, 'code' => 'E2', 'name' => 'Edificio 2']);
    Room::factory()->for($lc)->create(['code' => 'LC-01', 'department_id' => $this->department->id]);
    Room::factory()->for($e2)->create(['code' => 'E2-101', 'department_id' => $this->department->id]);
    Room::factory()->for(Building::factory()->create(['campus_id' => $c2->id, 'code' => 'X']))->create(['department_id' => $this->other->id]);

    expect(pageCodes($review, campusId: $c1->id))->toBe(['LC-01'])
        ->and(pageCodes($review, buildingId: $e2->id))->toBe(['E2-101'])
        ->and(pageCodes($review, campusId: 'no-es-uuid'))->toBe([])
        ->and($this->records->roomFilterOptions($review))->toBe([
            'campuses' => [['id' => $c1->id, 'name' => 'C1 · Campus I'], ['id' => $c2->id, 'name' => 'C2 · Campus II']],
            'buildings' => [['id' => $lc->id, 'name' => 'C1 · LC · Laboratorio de cómputo'], ['id' => $e2->id, 'name' => 'C2 · E2 · Edificio 2']],
        ]);
});

it('AC-06: da los docentes activos de la revisión sin correo institucional', function () {
    $review = recordsReview(Catalog::Teachers, $this->department);
    $without = Teacher::factory()->adscribedTo($this->department)->create(['rfc' => 'A1']);
    Teacher::factory()->adscribedTo($this->department)->create(['rfc' => 'B2', 'institutional_email' => 'b@cdjuarez.tecnm.mx']);
    Teacher::factory()->adscribedTo($this->department)->inactive()->create(['rfc' => 'C3']);
    Teacher::factory()->adscribedTo($this->other)->create(['rfc' => 'D4']);

    expect($this->records->teachersWithoutEmail($review))->toBe([$without->id])
        ->and($this->records->teachersWithoutEmail(recordsReview(Catalog::Subjects, $this->department)))->toBe([]);
});
