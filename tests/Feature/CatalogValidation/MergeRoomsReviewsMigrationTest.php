<?php

use App\Modules\Academic\Models\Department;
use App\Modules\Academic\Models\Subject;
use App\Modules\CatalogValidation\Enums\Catalog;
use App\Modules\CatalogValidation\Enums\ReviewStatus;
use App\Modules\CatalogValidation\Models\CatalogReview;
use App\Modules\CatalogValidation\Models\CatalogReviewFlag;
use App\Modules\CatalogValidation\Models\CatalogReviewMark;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
| La migración que deja una sola revisión de salones por departamento y periodo para todos los campus (decisión 6,
| confirmada el 12 de noviembre de 2026; CV-S11, supuesto por confirmar): las de cada campus se funden en la de estado
| más avanzado, sin campus; sus observaciones y marcas pasan a la que queda, las fotos se juntan y las demás se borran.
| Después la base exige que ninguna revisión tenga campus. Se prueba sobre la forma de antes: `down()`, filas con
| `DB::table()` y `up()`.
*/

beforeEach(function () {
    $this->migration = require base_path('app/Modules/CatalogValidation/Database/Migrations/2026_11_12_000002_merge_rooms_reviews_across_campuses.php');
    $this->migration->down();

    $this->term = Term::factory()->create();
    $this->c1 = Campus::factory()->create(['code' => 'C1']);
    $this->c2 = Campus::factory()->create(['code' => 'C2']);
    $this->department = Department::factory()->create(['code' => 'SC']);
    $this->jefe = User::factory()->create();
});

/**
 * Una revisión de salones de un campus, como quedaban antes, con `DB::table()`.
 *
 * @param  array<string, mixed>  $extra
 */
function mergeRoomsReview(Campus $campus, ReviewStatus $status, array $extra = []): string
{
    $id = (string) Str::uuid7();

    DB::table('catalog_reviews')->insert([
        'id' => $id,
        'term_id' => test()->term->id,
        'campus_id' => $campus->id,
        'department_id' => test()->department->id,
        'catalog' => Catalog::Rooms->value,
        'status' => $status->value,
        'validated_by' => $status === ReviewStatus::Validated ? test()->jefe->id : null,
        'validated_at' => $status === ReviewStatus::Validated ? now() : null,
        'created_at' => now(),
        'updated_at' => now(),
        ...$extra,
    ]);

    return $id;
}

it('CV-S11: funde las de cada campus en la más avanzada, sin campus, con las observaciones y marcas de todas y las fotos juntas', function () {
    $pending = mergeRoomsReview($this->c1, ReviewStatus::Pending, ['notes' => 'Revisar C1']);
    $validated = mergeRoomsReview($this->c2, ReviewStatus::Validated, ['snapshot' => json_encode([['id' => fakeKey(2), 'code' => 'B-2', 'name' => null]]), 'notes' => 'Revisar C2']);
    $flag = CatalogReviewFlag::factory()->create(['review_id' => $pending]);
    $mark = CatalogReviewMark::factory()->create(['catalog_review_id' => $pending, 'record_id' => fakeKey(1)]);

    $this->migration->up();

    $review = CatalogReview::query()->where('catalog', Catalog::Rooms)->sole();
    expect($review->id)->toBe($validated)
        ->and($review->campus_id)->toBeNull()
        ->and($review->status)->toBe(ReviewStatus::Validated)
        ->and($review->validated_by)->toBe($this->jefe->id)
        ->and($review->snapshot)->toBe([['id' => fakeKey(2), 'code' => 'B-2', 'name' => null]])
        ->and($review->notes)->toBe("Revisar C2\nRevisar C1")
        ->and($flag->fresh()->review_id)->toBe($validated)
        ->and($mark->fresh()->catalog_review_id)->toBe($validated);
});

it('CV-S11: entre dos validadas junta sus fotos sin repetir registros', function () {
    $first = mergeRoomsReview($this->c1, ReviewStatus::Validated, ['snapshot' => json_encode([['id' => fakeKey(1), 'code' => 'A-1', 'name' => null]])]);
    mergeRoomsReview($this->c2, ReviewStatus::Validated, ['snapshot' => json_encode([['id' => fakeKey(2), 'code' => 'B-2', 'name' => 'Aula'], ['id' => fakeKey(1), 'code' => 'A-1', 'name' => null]])]);

    $this->migration->up();

    expect(CatalogReview::query()->where('catalog', Catalog::Rooms)->sole()->snapshot)->toEqualCanonicalizing([
        ['id' => fakeKey(1), 'code' => 'A-1', 'name' => null],
        ['id' => fakeKey(2), 'code' => 'B-2', 'name' => 'Aula'],
    ])->and(CatalogReview::query()->where('catalog', Catalog::Rooms)->sole()->id)->toBe($first);
});

it('una sola de un campus solo pierde su campus; las de otro periodo o departamento no se juntan', function () {
    $alone = mergeRoomsReview($this->c2, ReviewStatus::InReview);
    $other = Department::factory()->create();
    mergeRoomsReview($this->c1, ReviewStatus::Pending, ['department_id' => $other->id]);
    mergeRoomsReview($this->c1, ReviewStatus::Pending, ['term_id' => Term::factory()->create()->id]);

    $this->migration->up();

    expect(CatalogReview::query()->where('catalog', Catalog::Rooms)->count())->toBe(3)
        ->and(CatalogReview::query()->whereNotNull('campus_id')->exists())->toBeFalse()
        ->and(CatalogReview::find($alone)->status)->toBe(ReviewStatus::InReview);
});

it('después la base rechaza una revisión con campus', function () {
    $this->migration->up();

    expect(fn () => CatalogReview::factory()->general(Catalog::Subjects)->create(['campus_id' => $this->c1->id]))
        ->toThrow(QueryException::class, 'catalog_reviews_campus_check');
});

it('una marca que ya estaba en la que queda no se duplica', function () {
    $kept = mergeRoomsReview($this->c2, ReviewStatus::Validated);
    $merged = mergeRoomsReview($this->c1, ReviewStatus::Pending);
    $subject = Subject::factory()->create();
    CatalogReviewMark::factory()->create(['catalog_review_id' => $kept, 'record_id' => $subject->id]);
    CatalogReviewMark::factory()->create(['catalog_review_id' => $merged, 'record_id' => $subject->id]);

    $this->migration->up();

    expect(CatalogReviewMark::query()->where('record_id', $subject->id)->pluck('catalog_review_id')->all())->toBe([$kept]);
});
