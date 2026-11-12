<?php

use App\Modules\Academic\Models\Career;
use App\Modules\Academic\Models\Subject;
use App\Modules\CatalogValidation\Actions\CorrectRecord;
use App\Modules\CatalogValidation\Actions\MarkRecordsReviewed;
use App\Modules\CatalogValidation\Actions\ReopenReview;
use App\Modules\CatalogValidation\Actions\UnmarkRecordReviewed;
use App\Modules\CatalogValidation\Actions\ValidateReview;
use App\Modules\CatalogValidation\Enums\Catalog;
use App\Modules\CatalogValidation\Enums\ReviewStatus;
use App\Modules\CatalogValidation\Events\CatalogReviewStarted;
use App\Modules\CatalogValidation\Models\CatalogReview;
use App\Modules\CatalogValidation\Models\CatalogReviewMark;
use App\Modules\CatalogValidation\Queries\CatalogContentsQuery;
use App\Modules\Core\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;

/*
| Marca de revisado por registro (CV7, confirmado el 12 de noviembre de 2026): el jefe marca cada registro de la
| revisión como revisado, varios a la vez, y la revisión solo se valida con todos los activos revisados; los inactivos
| no hace falta marcarlos. Corregir un registro lo marca. La marca es de la revisión (periodo, departamento y catálogo): un registro que entra después nace sin
| revisar, reabrir conserva las marcas y una corrección hecha fuera de la revisión no la quita (CV-S7, supuesto por
| confirmar).
*/

beforeEach(function () {
    $this->actor = User::factory()->create();
    $this->review = CatalogReview::factory()->general(Catalog::Subjects)->create();
    $this->first = Subject::factory()->create(['code' => 'A-1', 'department_id' => $this->review->department_id]);
    $this->second = Subject::factory()->inactive()->create(['code' => 'B-2', 'department_id' => $this->review->department_id]);
    $this->foreign = Subject::factory()->create(['code' => 'Z-9']);
});

function markErrors(Closure $action): array
{
    try {
        $action();
    } catch (ValidationException $exception) {
        return $exception->errors();
    }

    return [];
}

function marked(CatalogReview $review): array
{
    return CatalogReviewMark::query()->where('catalog_review_id', $review->id)->orderBy('record_id')->pluck('record_id')->all();
}

describe('tabla catalog_review_marks', function () {
    it('conoce su revisión y quién marcó, con cuándo', function () {
        $mark = CatalogReviewMark::factory()->for($this->review, 'review')->create(['record_id' => $this->first->id])->fresh();

        expect($mark->review->is($this->review))->toBeTrue()
            ->and($mark->reviewer)->toBeInstanceOf(User::class)
            ->and($mark->reviewed_at)->not->toBeNull()
            ->and($this->review->marks()->sole()->is($mark))->toBeTrue();
    });

    it('una sola marca por revisión y registro', function () {
        CatalogReviewMark::factory()->for($this->review, 'review')->create(['record_id' => $this->first->id]);

        expect(fn () => CatalogReviewMark::factory()->for($this->review, 'review')->create(['record_id' => $this->first->id]))
            ->toThrow(QueryException::class, 'catalog_review_marks_catalog_review_id_record_id_unique');
    });
});

describe('MarkRecordsReviewed', function () {
    it('CV7: marca varios registros a la vez, con quién y cuándo, y deja una bitácora', function () {
        $count = app(MarkRecordsReviewed::class)->handle($this->review, [$this->first->id, $this->second->id], $this->actor);

        $entry = Activity::query()->where('event', 'reviewed')->sole();
        expect($count)->toBe(2)
            ->and(marked($this->review))->toEqualCanonicalizing([$this->first->id, $this->second->id])
            ->and(CatalogReviewMark::query()->pluck('reviewed_by')->unique()->all())->toBe([$this->actor->id])
            ->and($entry->subject->is($this->review))->toBeTrue()
            ->and($entry->causer->is($this->actor))->toBeTrue()
            ->and($entry->properties['records'])->toEqualCanonicalizing([$this->first->id, $this->second->id]);
    });

    it('CV-S4: la primera marca pasa la revisión de pendiente a en revisión', function () {
        Event::fake([CatalogReviewStarted::class]);

        app(MarkRecordsReviewed::class)->handle($this->review, [$this->first->id], $this->actor);

        expect($this->review->fresh()->status)->toBe(ReviewStatus::InReview);
        Event::assertDispatched(CatalogReviewStarted::class);
    });

    it('marcar lo ya marcado no escribe ni deja otra bitácora, y conserva a quien lo marcó primero', function () {
        $first = User::factory()->create();
        app(MarkRecordsReviewed::class)->handle($this->review, [$this->first->id], $first);

        $count = app(MarkRecordsReviewed::class)->handle($this->review, [$this->first->id, $this->first->id], $this->actor);

        expect($count)->toBe(0)
            ->and(CatalogReviewMark::query()->sole()->reviewed_by)->toBe($first->id)
            ->and(Activity::query()->where('event', 'reviewed')->count())->toBe(1);
    });

    it('rechaza todo si un registro no es de la revisión, y no marca ninguno', function () {
        expect(markErrors(fn () => app(MarkRecordsReviewed::class)->handle($this->review, [$this->first->id, $this->foreign->id], $this->actor)))
            ->toBe(['record' => ['El registro no es de esta revisión.']])
            ->and(marked($this->review))->toBe([]);
    });

    it('un id que no es uuid cuenta como un registro que no es de la revisión', function () {
        expect(markErrors(fn () => app(MarkRecordsReviewed::class)->handle($this->review, ['no-es-uuid'], $this->actor)))
            ->toBe(['record' => ['El registro no es de esta revisión.']]);
    });

    it('un registro borrado ya no es de la revisión', function () {
        $this->first->delete();

        expect(markErrors(fn () => app(MarkRecordsReviewed::class)->handle($this->review, [$this->first->id], $this->actor)))
            ->toBe(['record' => ['El registro no es de esta revisión.']]);
    });

    it('pide al menos un registro', function () {
        expect(markErrors(fn () => app(MarkRecordsReviewed::class)->handle($this->review, [], $this->actor)))
            ->toBe(['records' => ['Elige al menos un registro.']]);
    });

    it('no marca en una revisión validada', function () {
        $this->review->update(['status' => ReviewStatus::Validated]);

        expect(markErrors(fn () => app(MarkRecordsReviewed::class)->handle($this->review, [$this->first->id], $this->actor)))
            ->toBe(['status' => ['La revisión ya está validada: reábrela para corregir u observar.']])
            ->and(marked($this->review))->toBe([]);
    });

    it('lee la revisión con bloqueo', function () {
        DB::enableQueryLog();

        app(MarkRecordsReviewed::class)->handle($this->review, [$this->first->id], $this->actor);

        expect(collect(DB::getQueryLog())->contains(fn (array $query): bool => str_contains($query['query'], 'from "catalog_reviews"') && str_contains($query['query'], 'for update')))->toBeTrue();
    });
});

describe('UnmarkRecordReviewed', function () {
    it('quita la marca de un registro y deja bitácora', function () {
        app(MarkRecordsReviewed::class)->handle($this->review, [$this->first->id, $this->second->id], $this->actor);

        app(UnmarkRecordReviewed::class)->handle($this->review, $this->first, $this->actor);

        $entry = Activity::query()->where('event', 'unreviewed')->sole();
        expect(marked($this->review))->toBe([$this->second->id])
            ->and($entry->subject->is($this->review))->toBeTrue()
            ->and($entry->properties['record'])->toBe($this->first->id);
    });

    it('sin marca no hace nada', function () {
        app(UnmarkRecordReviewed::class)->handle($this->review, $this->first, $this->actor);

        expect(Activity::query()->where('event', 'unreviewed')->exists())->toBeFalse();
    });

    it('no la quita en una revisión validada ni de un registro ajeno', function () {
        app(MarkRecordsReviewed::class)->handle($this->review, [$this->first->id], $this->actor);
        $this->review->update(['status' => ReviewStatus::Validated]);

        expect(markErrors(fn () => app(UnmarkRecordReviewed::class)->handle($this->review, $this->first, $this->actor)))->toHaveKey('status');

        $this->review->update(['status' => ReviewStatus::InReview]);

        expect(markErrors(fn () => app(UnmarkRecordReviewed::class)->handle($this->review, $this->foreign, $this->actor)))->toBe(['record' => ['El registro no es de esta revisión.']])
            ->and(marked($this->review))->toBe([$this->first->id]);
    });
});

describe('validar con todo revisado', function () {
    it('CV7: no valida mientras quede un registro activo sin revisar, y dice cuántos faltan', function () {
        app(MarkRecordsReviewed::class)->handle($this->review, [$this->second->id], $this->actor);

        expect(markErrors(fn () => app(ValidateReview::class)->handle($this->review, $this->actor)))
            ->toBe(['status' => ['Falta 1 registro por revisar.']])
            ->and($this->review->fresh()->status)->not->toBe(ReviewStatus::Validated);
    });

    it('CV7: con todos los activos revisados valida; los inactivos no hace falta marcarlos', function () {
        app(MarkRecordsReviewed::class)->handle($this->review, [$this->first->id], $this->actor);

        app(ValidateReview::class)->handle($this->review, $this->actor);

        expect($this->review->fresh()->status)->toBe(ReviewStatus::Validated);
    });

    it('CV7: un inactivo también se puede marcar', function () {
        expect(app(MarkRecordsReviewed::class)->handle($this->review, [$this->second->id], $this->actor))->toBe(1);
    });

    it('CV7: una revisión sin registros activos se valida', function () {
        $empty = CatalogReview::factory()->general(Catalog::Careers)->create();
        Career::factory()->inactive()->create(['department_id' => $empty->department_id]);

        app(ValidateReview::class)->handle($empty, $this->actor);

        expect($empty->fresh()->status)->toBe(ReviewStatus::Validated);
    });

    it('CV-S7: un registro que entra a la revisión después nace sin revisar', function () {
        app(MarkRecordsReviewed::class)->handle($this->review, [$this->first->id, $this->second->id], $this->actor);
        Subject::factory()->create(['department_id' => $this->review->department_id]);

        expect(markErrors(fn () => app(ValidateReview::class)->handle($this->review, $this->actor)))
            ->toBe(['status' => ['Falta 1 registro por revisar.']]);
    });

    it('CV-S7: la marca de un registro que salió de la revisión no cuenta', function () {
        $gone = Subject::factory()->create(['department_id' => $this->review->department_id]);
        app(MarkRecordsReviewed::class)->handle($this->review, [$gone->id], $this->actor);
        $gone->delete();

        expect(app(CatalogContentsQuery::class)->progress($this->review))->toBe(['reviewed' => 0, 'total' => 1]);
    });

    it('CV-S7: reabrir conserva las marcas', function () {
        app(MarkRecordsReviewed::class)->handle($this->review, [$this->first->id, $this->second->id], $this->actor);
        app(ValidateReview::class)->handle($this->review, $this->actor);

        app(ReopenReview::class)->handle($this->review->fresh(), $this->actor);

        expect(marked($this->review))->toEqualCanonicalizing([$this->first->id, $this->second->id]);
    });

    it('CV-S7: una corrección hecha fuera de la revisión conserva la marca', function () {
        app(MarkRecordsReviewed::class)->handle($this->review, [$this->first->id], $this->actor);

        $this->first->update(['name' => 'Otro nombre']);

        expect(marked($this->review))->toBe([$this->first->id]);
    });
});

describe('corregir marca', function () {
    it('CV7: corregir un registro lo marca como revisado', function () {
        $this->actingAs($this->actor);

        app(CorrectRecord::class)->handle($this->review, $this->first, ['name' => 'Cálculo integral'], $this->actor);

        expect(marked($this->review))->toBe([$this->first->id])
            ->and($this->review->fresh()->status)->toBe(ReviewStatus::InReview);
    });

    it('CV-S7: guardar sin cambios no es una corrección: no lo marca', function () {
        $this->actingAs($this->actor);

        app(CorrectRecord::class)->handle($this->review, $this->first, ['name' => $this->first->name], $this->actor);

        expect(marked($this->review))->toBe([])
            ->and($this->review->fresh()->status)->toBe(ReviewStatus::Pending);
    });

    it('si la corrección falla no queda la marca', function () {
        $this->actingAs($this->actor);

        expect(markErrors(fn () => app(CorrectRecord::class)->handle($this->review, $this->first, ['credits' => -1], $this->actor)))->not->toBe([])
            ->and(marked($this->review))->toBe([]);
    });
});
