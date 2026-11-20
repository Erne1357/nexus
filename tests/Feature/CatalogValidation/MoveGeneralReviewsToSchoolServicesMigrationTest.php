<?php

use App\Modules\Academic\Models\Department;
use App\Modules\Academic\Models\Subject;
use App\Modules\CatalogValidation\Enums\Catalog;
use App\Modules\CatalogValidation\Enums\ReviewStatus;
use App\Modules\CatalogValidation\Models\CatalogReview;
use App\Modules\CatalogValidation\Models\CatalogReviewFlag;
use App\Modules\CatalogValidation\Models\CatalogReviewMark;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Activity;

/*
| CV15 (confirmada el 20 de noviembre de 2026): materias, planes y carreras los valida Servicios Escolares, una sola
| revisión por catálogo y periodo para todo el Tec, sin departamento. La migración funde las que ya había, una por
| departamento, con el patrón de CV-S11 (supuesto por confirmar): gana el estado menos avanzado, las observaciones y las
| marcas pasan a la que queda, la foto es la unión, las demás se borran y la bitácora de la que queda dice qué se fundió.
| Después la base exige que esas revisiones no tengan departamento y que las de docentes y salones sí. Se prueba sobre
| la forma de antes: `down()`, filas con `DB::table()` y `up()`.
*/

beforeEach(function () {
    $this->migration = require base_path('app/Modules/CatalogValidation/Database/Migrations/2026_11_20_100001_move_general_catalog_reviews_to_school_services.php');
    $this->migration->down();

    $this->term = Term::factory()->create();
    $this->sc = Department::factory()->create(['code' => 'SC']);
    $this->ii = Department::factory()->create(['code' => 'II']);
    $this->jefe = User::factory()->create();
});

/**
 * Una revisión de un catálogo de un departamento, como quedaban antes de CV15, con `DB::table()`.
 *
 * @param  array<string, mixed>  $extra
 */
function schoolServicesMergeReview(Department $department, Catalog $catalog, ReviewStatus $status, array $extra = []): string
{
    $id = (string) Str::uuid7();

    DB::table('catalog_reviews')->insert([
        'id' => $id,
        'term_id' => test()->term->id,
        'campus_id' => null,
        'department_id' => $department->id,
        'catalog' => $catalog->value,
        'status' => $status->value,
        'validated_by' => $status === ReviewStatus::Validated ? test()->jefe->id : null,
        'validated_at' => $status === ReviewStatus::Validated ? now() : null,
        'created_at' => now(),
        'updated_at' => now(),
        ...$extra,
    ]);

    return $id;
}

it('CV15: funde las de cada departamento en la menos avanzada, sin departamento, con las observaciones y marcas de todas y las fotos juntas', function () {
    $validated = schoolServicesMergeReview($this->sc, Catalog::Subjects, ReviewStatus::Validated, ['snapshot' => json_encode([['id' => fakeKey(1), 'code' => 'SCD-1001', 'name' => 'Cálculo']]), 'notes' => 'Revisar SC']);
    $inReview = schoolServicesMergeReview($this->ii, Catalog::Subjects, ReviewStatus::InReview, ['snapshot' => json_encode([['id' => fakeKey(2), 'code' => 'AEF-1002', 'name' => null]]), 'notes' => 'Revisar II']);
    $flag = CatalogReviewFlag::factory()->create(['review_id' => $validated]);
    $mark = CatalogReviewMark::factory()->create(['catalog_review_id' => $validated, 'record_id' => fakeKey(1)]);

    $this->migration->up();

    $review = CatalogReview::query()->where('catalog', Catalog::Subjects)->sole();
    expect($review->id)->toBe($inReview)
        ->and($review->department_id)->toBeNull()
        ->and($review->status)->toBe(ReviewStatus::InReview)
        ->and($review->validated_by)->toBeNull()
        ->and($review->validated_at)->toBeNull()
        ->and($review->snapshot)->toEqualCanonicalizing([['id' => fakeKey(1), 'code' => 'SCD-1001', 'name' => 'Cálculo'], ['id' => fakeKey(2), 'code' => 'AEF-1002', 'name' => null]])
        ->and($review->notes)->toBe("Revisar II\nRevisar SC")
        ->and($flag->fresh()->review_id)->toBe($inReview)
        ->and($mark->fresh()->catalog_review_id)->toBe($inReview);
});

it('CV15: todas validadas quedan validadas con quién y cuándo de la que queda; cada catálogo y periodo por separado', function () {
    $first = schoolServicesMergeReview($this->sc, Catalog::Careers, ReviewStatus::Validated);
    schoolServicesMergeReview($this->ii, Catalog::Careers, ReviewStatus::Validated);
    schoolServicesMergeReview($this->sc, Catalog::StudyPlans, ReviewStatus::Pending);
    schoolServicesMergeReview($this->sc, Catalog::StudyPlans, ReviewStatus::Pending, ['term_id' => Term::factory()->create()->id]);

    $this->migration->up();

    $careers = CatalogReview::query()->where('catalog', Catalog::Careers)->sole();
    expect($careers->id)->toBe($first)
        ->and($careers->status)->toBe(ReviewStatus::Validated)
        ->and($careers->validated_by)->toBe($this->jefe->id)
        ->and(CatalogReview::query()->where('catalog', Catalog::StudyPlans)->whereNull('department_id')->count())->toBe(2);
});

it('CV15: docentes y salones siguen por departamento', function () {
    schoolServicesMergeReview($this->sc, Catalog::Teachers, ReviewStatus::Pending);
    schoolServicesMergeReview($this->ii, Catalog::Teachers, ReviewStatus::Validated);
    schoolServicesMergeReview($this->sc, Catalog::Rooms, ReviewStatus::InReview);

    $this->migration->up();

    expect(CatalogReview::query()->whereNull('department_id')->exists())->toBeFalse()
        ->and(CatalogReview::query()->count())->toBe(3);
});

it('CV15: deja en la bitácora de la que queda cada revisión fundida con su departamento, estado y quién la validó', function () {
    $pending = schoolServicesMergeReview($this->sc, Catalog::Subjects, ReviewStatus::Pending);
    $validated = schoolServicesMergeReview($this->ii, Catalog::Subjects, ReviewStatus::Validated);

    $this->migration->up();

    $entry = Activity::query()->where('event', 'merged')->sole();
    expect($entry->subject_id)->toBe($pending)
        ->and($entry->description)->toContain('CV15')
        ->and($entry->properties['kept'])->toMatchArray(['id' => $pending, 'department' => 'SC', 'status' => 'pending'])
        ->and($entry->properties['absorbed'])->toHaveCount(1)
        ->and($entry->properties['absorbed'][0])->toMatchArray(['id' => $validated, 'department' => 'II', 'status' => 'validated', 'validated_by' => $this->jefe->id])
        ->and($entry->properties['absorbed'][0]['validated_at'])->not->toBeNull();
});

it('CV15: una sola de un departamento solo pierde su departamento y no deja bitácora de fusión', function () {
    $alone = schoolServicesMergeReview($this->sc, Catalog::Subjects, ReviewStatus::Validated);

    $this->migration->up();

    $review = CatalogReview::query()->sole();
    expect($review->id)->toBe($alone)
        ->and($review->department_id)->toBeNull()
        ->and($review->validated_by)->toBe($this->jefe->id)
        ->and(Activity::query()->where('event', 'merged')->exists())->toBeFalse();
});

it('CV15: una marca del mismo registro en dos departamentos no se duplica', function () {
    $kept = schoolServicesMergeReview($this->sc, Catalog::Subjects, ReviewStatus::Pending);
    $merged = schoolServicesMergeReview($this->ii, Catalog::Subjects, ReviewStatus::Validated);
    $subject = Subject::factory()->create();
    CatalogReviewMark::factory()->create(['catalog_review_id' => $kept, 'record_id' => $subject->id]);
    CatalogReviewMark::factory()->create(['catalog_review_id' => $merged, 'record_id' => $subject->id]);

    $this->migration->up();

    expect(CatalogReviewMark::query()->where('record_id', $subject->id)->pluck('catalog_review_id')->all())->toBe([$kept]);
});

it('CV15: volver a fundir no cambia nada (idempotente)', function () {
    schoolServicesMergeReview($this->sc, Catalog::Subjects, ReviewStatus::Pending);
    schoolServicesMergeReview($this->ii, Catalog::Subjects, ReviewStatus::InReview);
    $this->migration->up();
    $before = CatalogReview::query()->orderBy('id')->get()->toArray();

    $this->migration->mergeIntoSchoolServices();

    expect(CatalogReview::query()->orderBy('id')->get()->toArray())->toBe($before)
        ->and(Activity::query()->where('event', 'merged')->count())->toBe(1);
});

it('CV15: después la base rechaza una revisión de materias con departamento y una de docentes sin él', function (Catalog $catalog, bool $withDepartment) {
    $this->migration->up();

    expect(fn () => DB::table('catalog_reviews')->insert([
        'id' => (string) Str::uuid7(), 'term_id' => $this->term->id, 'department_id' => $withDepartment ? $this->sc->id : null,
        'catalog' => $catalog->value, 'status' => 'pending', 'created_at' => now(), 'updated_at' => now(),
    ]))->toThrow(QueryException::class, 'catalog_reviews_owner_check');
})->with([
    'materias con departamento' => [Catalog::Subjects, true],
    'planes con departamento' => [Catalog::StudyPlans, true],
    'carreras con departamento' => [Catalog::Careers, true],
    'docentes sin departamento' => [Catalog::Teachers, false],
    'salones sin departamento' => [Catalog::Rooms, false],
]);

it('CV15: una sola revisión de Servicios Escolares por catálogo y periodo', function () {
    $this->migration->up();
    $row = fn (): array => ['id' => (string) Str::uuid7(), 'term_id' => $this->term->id, 'department_id' => null, 'catalog' => 'subjects', 'status' => 'pending', 'created_at' => now(), 'updated_at' => now()];
    DB::table('catalog_reviews')->insert($row());

    expect(fn () => DB::table('catalog_reviews')->insert($row()))->toThrow(QueryException::class, 'catalog_reviews_term_id_campus_id_department_id_catalog_unique');
});

it('down() no regresa si quedaron revisiones de Servicios Escolares', function () {
    schoolServicesMergeReview($this->sc, Catalog::Subjects, ReviewStatus::Pending);
    $this->migration->up();

    expect(fn () => $this->migration->down())->toThrow(RuntimeException::class, 'no se puede regresar');
});
