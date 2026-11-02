<?php

use App\Modules\Academic\Models\Subject;
use App\Modules\CatalogValidation\Enums\Catalog;
use App\Modules\CatalogValidation\Enums\ReviewFlag;
use App\Modules\CatalogValidation\Models\CatalogReview;
use App\Modules\CatalogValidation\Models\CatalogReviewFlag;
use App\Modules\Core\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/*
| Integridad de `catalog_review_flags` y de la foto de `catalog_reviews` (docs/dominio/modelo-de-datos.md,
| "CatalogValidation"): una observación es de un registro concreto, de un tipo conocido, con comentario si es
| «otra» (supuesto por confirmar), y resuelta lleva quién y cuándo.
*/

it('conoce su revisión, el registro observado, quién la puso y quién la resolvió', function () {
    $review = CatalogReview::factory()->general(Catalog::Subjects)->create();
    $subject = Subject::factory()->create();

    $flag = CatalogReviewFlag::factory()->for($review, 'review')->on($subject)->resolved()->create()->fresh();

    expect($flag->review->is($review))->toBeTrue()
        ->and($flag->flaggable->is($subject))->toBeTrue()
        ->and($flag->flag)->toBe(ReviewFlag::Inactive)
        ->and($flag->flagger)->toBeInstanceOf(User::class)
        ->and($flag->resolver)->toBeInstanceOf(User::class)
        ->and($flag->resolved_at)->not->toBeNull()
        ->and($review->flags()->sole()->is($flag))->toBeTrue();
});

it('rechaza un tipo de observación que no existe', function () {
    $flag = CatalogReviewFlag::factory()->create();

    expect(fn () => DB::table('catalog_review_flags')->where('id', $flag->id)->update(['flag' => 'typo']))
        ->toThrow(QueryException::class, 'catalog_review_flags_flag_check');
});

it('exige el comentario de una observación «otra» (supuesto por confirmar)', function (?string $comment) {
    expect(fn () => CatalogReviewFlag::factory()->create(['flag' => ReviewFlag::Other, 'comment' => $comment]))
        ->toThrow(QueryException::class, 'catalog_review_flags_comment_check');
})->with([null, '   ']);

it('una observación de otro tipo puede ir sin comentario', function () {
    expect(CatalogReviewFlag::factory()->create(['flag' => ReviewFlag::Duplicate, 'comment' => null])->fresh()->comment)->toBeNull();
});

it('resuelta lleva quién y cuándo, o ninguno de los dos', function (array $values) {
    expect(fn () => CatalogReviewFlag::factory()->create($values))
        ->toThrow(QueryException::class, 'catalog_review_flags_resolved_check');
})->with([
    'fecha sin quién' => [['resolved_at' => now()]],
    'quién sin fecha' => [fn () => ['resolved_by' => User::factory()->create()->id]],
]);

it('la revisión guarda su foto como una lista de registros', function () {
    $snapshot = [['id' => fakeKey(1), 'code' => 'AEB1011', 'name' => 'Cálculo']];

    $review = CatalogReview::factory()->validated()->create(['snapshot' => $snapshot])->fresh();

    expect($review->snapshot)->toBe($snapshot)
        ->and(CatalogReview::factory()->create()->fresh()->snapshot)->toBeNull();
});
