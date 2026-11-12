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
use Spatie\Activitylog\Models\Activity;

/*
| La migración que deja una sola revisión de salones por departamento y periodo para todos los campus (decisión 6,
| confirmada el 12 de noviembre de 2026; CV-S11, supuesto por confirmar): las de cada campus se funden en la de estado
| menos avanzado, sin campus; sus observaciones y marcas pasan a la que queda, las fotos se juntan, las demás se borran y
| la bitácora de la que queda dice qué se fundió.
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

it('CV-S11: funde las de cada campus en la menos avanzada, sin campus, con las observaciones y marcas de todas y las fotos juntas', function () {
    $pending = mergeRoomsReview($this->c1, ReviewStatus::Pending, ['notes' => 'Revisar C1']);
    $validated = mergeRoomsReview($this->c2, ReviewStatus::Validated, ['snapshot' => json_encode([['id' => fakeKey(2), 'code' => 'B-2', 'name' => null]]), 'notes' => 'Revisar C2']);
    $flag = CatalogReviewFlag::factory()->create(['review_id' => $validated]);
    $mark = CatalogReviewMark::factory()->create(['catalog_review_id' => $validated, 'record_id' => fakeKey(1)]);

    $this->migration->up();

    $review = CatalogReview::query()->where('catalog', Catalog::Rooms)->sole();
    expect($review->id)->toBe($pending)
        ->and($review->campus_id)->toBeNull()
        ->and($review->status)->toBe(ReviewStatus::Pending)
        ->and($review->validated_by)->toBeNull()
        ->and($review->validated_at)->toBeNull()
        ->and($review->snapshot)->toBe([['id' => fakeKey(2), 'code' => 'B-2', 'name' => null]])
        ->and($review->notes)->toBe("Revisar C1\nRevisar C2")
        ->and($flag->fresh()->review_id)->toBe($pending)
        ->and($mark->fresh()->catalog_review_id)->toBe($pending);
});

it('CV-S11: deja en la bitácora de la que queda cada revisión fundida con su campus, estado y quién la validó', function () {
    $pending = mergeRoomsReview($this->c1, ReviewStatus::InReview);
    $validated = mergeRoomsReview($this->c2, ReviewStatus::Validated);

    $this->migration->up();

    $entry = Activity::query()->where('event', 'merged')->sole();
    expect($entry->subject_id)->toBe($pending)
        ->and($entry->properties['kept'])->toMatchArray(['id' => $pending, 'campus' => 'C1', 'status' => 'in_review'])
        ->and($entry->properties['absorbed'])->toHaveCount(1)
        ->and($entry->properties['absorbed'][0])->toMatchArray(['id' => $validated, 'campus' => 'C2', 'status' => 'validated', 'validated_by' => $this->jefe->id])
        ->and($entry->properties['absorbed'][0]['validated_at'])->not->toBeNull();
});

it('una sola de un campus no deja bitácora de fusión', function () {
    mergeRoomsReview($this->c2, ReviewStatus::Validated);

    $this->migration->up();

    expect(Activity::query()->where('event', 'merged')->exists())->toBeFalse()
        ->and(CatalogReview::query()->sole()->validated_by)->toBe($this->jefe->id);
});

it('down() no regresa si quedaron revisiones de salones sin campus', function () {
    mergeRoomsReview($this->c2, ReviewStatus::Pending);
    $this->migration->up();

    expect(fn () => $this->migration->down())->toThrow(RuntimeException::class, 'no se puede regresar');
});

it('CV-S11: entre dos validadas junta sus fotos sin repetir registros', function () {
    $first = mergeRoomsReview($this->c1, ReviewStatus::Validated, ['snapshot' => json_encode([['id' => fakeKey(1), 'code' => 'A-1', 'name' => null]])]);
    mergeRoomsReview($this->c2, ReviewStatus::Validated, ['snapshot' => json_encode([['id' => fakeKey(2), 'code' => 'B-2', 'name' => 'Aula'], ['id' => fakeKey(1), 'code' => 'A-1', 'name' => null]])]);

    $this->migration->up();

    expect(CatalogReview::query()->where('catalog', Catalog::Rooms)->sole()->snapshot)->toEqualCanonicalizing([
        ['id' => fakeKey(1), 'code' => 'A-1', 'name' => null],
        ['id' => fakeKey(2), 'code' => 'B-2', 'name' => 'Aula'],
    ])->and(CatalogReview::query()->where('catalog', Catalog::Rooms)->sole()->id)->toBe($first)
        ->and(CatalogReview::query()->where('catalog', Catalog::Rooms)->sole()->validated_by)->toBe($this->jefe->id);
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
    $kept = mergeRoomsReview($this->c2, ReviewStatus::Pending);
    $merged = mergeRoomsReview($this->c1, ReviewStatus::Validated);
    $subject = Subject::factory()->create();
    CatalogReviewMark::factory()->create(['catalog_review_id' => $kept, 'record_id' => $subject->id]);
    CatalogReviewMark::factory()->create(['catalog_review_id' => $merged, 'record_id' => $subject->id]);

    $this->migration->up();

    expect(CatalogReviewMark::query()->where('record_id', $subject->id)->pluck('catalog_review_id')->all())->toBe([$kept]);
});
