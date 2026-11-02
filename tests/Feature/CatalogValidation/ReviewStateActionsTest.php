<?php

use App\Modules\Academic\Models\Career;
use App\Modules\CatalogValidation\Actions\ReopenReview;
use App\Modules\CatalogValidation\Actions\StartReview;
use App\Modules\CatalogValidation\Actions\ValidateReview;
use App\Modules\CatalogValidation\Enums\Catalog;
use App\Modules\CatalogValidation\Enums\ReviewStatus;
use App\Modules\CatalogValidation\Events\CatalogReviewReopened;
use App\Modules\CatalogValidation\Events\CatalogReviewReopening;
use App\Modules\CatalogValidation\Events\CatalogReviewStarted;
use App\Modules\CatalogValidation\Events\CatalogReviewValidated;
use App\Modules\CatalogValidation\Models\CatalogReview;
use App\Modules\CatalogValidation\Models\CatalogReviewFlag;
use App\Modules\CatalogValidation\Queries\CatalogReviewQuery;
use App\Modules\Core\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;

/*
| Estado de una revisión de catálogo (reglas-de-negocio.md, "Validación de catálogos"): StartReview la pasa de
| pendiente a en revisión (CV-S4), ValidateReview la valida con su foto (CV3) aunque tenga observaciones abiertas
| (CV4) y ReopenReview la regresa a en revisión si nadie lo veta (CV5). Cada una en una transacción, con la
| revisión bloqueada, bitácora con el valor anterior y el nuevo, y su evento después del commit.
*/

beforeEach(function () {
    $this->actor = User::factory()->create();
    $this->review = CatalogReview::factory()->general(Catalog::Careers)->create();
});

function cvErrorsOf(Closure $action): array
{
    try {
        $action();
    } catch (ValidationException $exception) {
        return $exception->errors();
    }

    return [];
}

function cvLocksReview(Closure $action): bool
{
    DB::enableQueryLog();
    $action();

    return collect(DB::getQueryLog())->contains(fn (array $query): bool => str_contains($query['query'], 'from "catalog_reviews"')
        && str_contains($query['query'], 'for update'));
}

describe('StartReview', function () {
    it('CV-S4: pasa la revisión de pendiente a en revisión, con bitácora y aviso', function () {
        Event::fake([CatalogReviewStarted::class]);

        app(StartReview::class)->handle($this->review, $this->actor);

        $entry = Activity::sole();
        expect($this->review->fresh()->status)->toBe(ReviewStatus::InReview)
            ->and($entry->event)->toBe('updated')
            ->and($entry->subject->is($this->review))->toBeTrue()
            ->and($entry->causer->is($this->actor))->toBeTrue()
            ->and($entry->attribute_changes->toArray())->toBe([
                'attributes' => ['status' => 'in_review'],
                'old' => ['status' => 'pending'],
            ]);
        Event::assertDispatched(CatalogReviewStarted::class, fn (CatalogReviewStarted $event): bool => $event->review->is($this->review));
    });

    it('no hace nada en una revisión en revisión o validada', function (string $state) {
        Event::fake([CatalogReviewStarted::class]);
        $review = CatalogReview::factory()->{$state}()->create();

        app(StartReview::class)->handle($review, $this->actor);

        expect($review->fresh()->status)->not->toBe(ReviewStatus::Pending)
            ->and(Activity::count())->toBe(0);
        Event::assertNotDispatched(CatalogReviewStarted::class);
    })->with(['inReview', 'validated']);
});

describe('ValidateReview', function () {
    it('CV3: valida desde pendiente o en revisión y guarda la foto de los activos, quién y cuándo', function (ReviewStatus $status) {
        $review = CatalogReview::factory()->general(Catalog::Careers)->create(['status' => $status]);
        $active = Career::factory()->create(['code' => 'ISC', 'name' => 'Sistemas', 'department_id' => $review->department_id]);
        Career::factory()->inactive()->create(['department_id' => $review->department_id]);

        app(ValidateReview::class)->handle($review, $this->actor);

        $review->refresh();
        expect($review->status)->toBe(ReviewStatus::Validated)
            ->and($review->validated_by)->toBe($this->actor->id)
            ->and($review->validated_at)->not->toBeNull()
            ->and($review->snapshot)->toBe([['id' => $active->id, 'code' => 'ISC', 'name' => 'Sistemas']]);
    })->with([ReviewStatus::Pending, ReviewStatus::InReview]);

    it('deja en la bitácora el estado anterior y el nuevo, quién validó y cuántos registros tiene la foto', function () {
        Career::factory()->count(2)->create(['department_id' => $this->review->department_id]);

        app(ValidateReview::class)->handle($this->review, $this->actor);

        $entry = Activity::sole();
        expect($entry->event)->toBe('validated')
            ->and($entry->causer->is($this->actor))->toBeTrue()
            ->and($entry->properties['records'])->toBe(2)
            ->and($entry->attribute_changes['old'])->toBe(['status' => 'pending', 'validated_by' => null, 'validated_at' => null])
            ->and($entry->attribute_changes['attributes']['status'])->toBe('validated')
            ->and($entry->attribute_changes['attributes']['validated_by'])->toBe($this->actor->id);
    });

    it('CV4: las observaciones abiertas no impiden validar', function () {
        CatalogReviewFlag::factory()->for($this->review, 'review')->create();

        app(ValidateReview::class)->handle($this->review, $this->actor);

        expect($this->review->fresh()->status)->toBe(ReviewStatus::Validated);
    });

    it('rechaza validar una revisión ya validada y no escribe', function () {
        $review = CatalogReview::factory()->validated()->create();

        expect(cvErrorsOf(fn () => app(ValidateReview::class)->handle($review, $this->actor)))
            ->toBe(['status' => ['La revisión ya está validada.']])
            ->and(Activity::count())->toBe(0);
    });

    it('lee la revisión con bloqueo', function () {
        expect(cvLocksReview(fn () => app(ValidateReview::class)->handle($this->review, $this->actor)))->toBeTrue();
    });

    it('avisa con CatalogReviewValidated solo después del commit', function () {
        $heard = [];
        Event::listen(CatalogReviewValidated::class, function (CatalogReviewValidated $event) use (&$heard): void {
            $heard[] = $event;
        });

        try {
            DB::transaction(function (): void {
                app(ValidateReview::class)->handle($this->review, $this->actor);

                throw new RuntimeException('Falla algo después, en la misma transacción.');
            });
        } catch (RuntimeException) {
            //
        }

        expect($heard)->toBe([])
            ->and($this->review->fresh()->status)->toBe(ReviewStatus::Pending);

        app(ValidateReview::class)->handle($this->review->fresh(), $this->actor);

        expect($heard)->toHaveCount(1);
    });
});

describe('ReopenReview', function () {
    beforeEach(function () {
        $this->validated = CatalogReview::factory()->general(Catalog::Careers)->validated()->create(['snapshot' => [['id' => fakeKey(1), 'code' => 'ISC', 'name' => 'Sistemas']]]);
    });

    it('CV5: la regresa a en revisión sin quién ni cuándo validó, y conserva la foto hasta la siguiente validación', function () {
        app(ReopenReview::class)->handle($this->validated, $this->actor);

        $review = $this->validated->fresh();
        expect($review->status)->toBe(ReviewStatus::InReview)
            ->and($review->validated_by)->toBeNull()
            ->and($review->validated_at)->toBeNull()
            ->and($review->snapshot)->toBe([['id' => fakeKey(1), 'code' => 'ISC', 'name' => 'Sistemas']]);
    });

    it('deja en la bitácora quién la había validado y cuándo', function () {
        $validator = $this->validated->validated_by;

        app(ReopenReview::class)->handle($this->validated, $this->actor);

        $entry = Activity::sole();
        expect($entry->event)->toBe('reopened')
            ->and($entry->causer->is($this->actor))->toBeTrue()
            ->and($entry->attribute_changes['attributes'])->toBe(['status' => 'in_review', 'validated_by' => null, 'validated_at' => null])
            ->and($entry->attribute_changes['old']['status'])->toBe('validated')
            ->and($entry->attribute_changes['old']['validated_by'])->toBe($validator)
            ->and($entry->attribute_changes['old']['validated_at'])->not->toBeNull();
    });

    it('una reabierta deja de contar como la validación anterior del periodo siguiente', function () {
        $this->validated->term->update(['starts_on' => '2020-01-20', 'ends_on' => '2020-06-10']);
        $next = CatalogReview::factory()->general(Catalog::Careers)->create(['department_id' => $this->validated->department_id]);

        expect(app(CatalogReviewQuery::class)->previousValidation($next)?->is($this->validated))->toBeTrue();

        app(ReopenReview::class)->handle($this->validated, $this->actor);

        expect(app(CatalogReviewQuery::class)->previousValidation($next))->toBeNull();
    });

    it('solo reabre una revisión validada', function (ReviewStatus $status) {
        $review = CatalogReview::factory()->create(['status' => $status]);

        expect(cvErrorsOf(fn () => app(ReopenReview::class)->handle($review, $this->actor)))
            ->toBe(['status' => ['Solo se reabre una revisión validada.']])
            ->and(Activity::count())->toBe(0);
    })->with([ReviewStatus::Pending, ReviewStatus::InReview]);

    it('con un veto de CatalogReviewReopening sigue validada y rechaza con los motivos', function () {
        Event::listen(CatalogReviewReopening::class, fn (CatalogReviewReopening $event) => $event->block('Motivo de otro módulo.'));

        expect(cvErrorsOf(fn () => app(ReopenReview::class)->handle($this->validated, $this->actor)))
            ->toBe(['status' => ['Motivo de otro módulo.']])
            ->and($this->validated->fresh()->status)->toBe(ReviewStatus::Validated)
            ->and(Activity::count())->toBe(0);
    });

    it('pregunta el veto con la revisión ya bloqueada', function () {
        $locked = false;
        DB::enableQueryLog();
        Event::listen(CatalogReviewReopening::class, function () use (&$locked): void {
            $locked = collect(DB::getQueryLog())->contains(fn (array $query): bool => str_contains($query['query'], 'from "catalog_reviews"')
                && str_contains($query['query'], 'for update'));
        });

        app(ReopenReview::class)->handle($this->validated, $this->actor);

        expect($locked)->toBeTrue();
    });

    it('avisa con CatalogReviewReopened después del commit', function () {
        Event::fake([CatalogReviewReopened::class]);

        app(ReopenReview::class)->handle($this->validated, $this->actor);

        Event::assertDispatched(CatalogReviewReopened::class, fn (CatalogReviewReopened $event): bool => $event->review->is($this->validated));
    });
});
