<?php

use App\Modules\Academic\Models\Career;
use App\Modules\Academic\Models\Subject;
use App\Modules\CatalogValidation\Actions\FlagRecord;
use App\Modules\CatalogValidation\Actions\ResolveFlag;
use App\Modules\CatalogValidation\Enums\Catalog;
use App\Modules\CatalogValidation\Enums\ReviewFlag;
use App\Modules\CatalogValidation\Enums\ReviewStatus;
use App\Modules\CatalogValidation\Events\CatalogReviewFlagged;
use App\Modules\CatalogValidation\Events\CatalogReviewFlagResolved;
use App\Modules\CatalogValidation\Models\CatalogReview;
use App\Modules\CatalogValidation\Models\CatalogReviewFlag;
use App\Modules\Core\Models\User;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;

/*
| Observaciones del jefe sobre registros de una revisión (reglas-de-negocio.md, "Validación de catálogos"):
| FlagRecord solo en un registro de la revisión y mientras no esté validada (CV-S2), y la pasa a en revisión
| (CV-S4); ResolveFlag la marca resuelta con quién y cuándo, una sola vez. No bloquean validar (CV4).
*/

beforeEach(function () {
    $this->actor = User::factory()->create();
    $this->review = CatalogReview::factory()->general(Catalog::Subjects)->create();
    $this->subject = Subject::factory()->create(['department_id' => $this->review->department_id]);
});

function cvFlagErrors(Closure $action): array
{
    try {
        $action();
    } catch (ValidationException $exception) {
        return $exception->errors();
    }

    return [];
}

describe('FlagRecord', function () {
    it('pone la observación sobre un registro de la revisión, con quién, y la pasa a en revisión (CV-S4)', function () {
        $flag = app(FlagRecord::class)->handle($this->review, $this->subject, ReviewFlag::Duplicate, '  Repetida con otra clave  ', $this->actor);

        expect($flag->fresh())
            ->flag->toBe(ReviewFlag::Duplicate)
            ->comment->toBe('Repetida con otra clave')
            ->flagged_by->toBe($this->actor->id)
            ->resolved_at->toBeNull()
            ->and($flag->flaggable->is($this->subject))->toBeTrue()
            ->and($this->review->fresh()->status)->toBe(ReviewStatus::InReview);

        $entry = Activity::query()->where('subject_type', $flag->getMorphClass())->sole();
        expect($entry->event)->toBe('created')
            ->and($entry->causer->is($this->actor))->toBeTrue()
            ->and($entry->attribute_changes['attributes'])->toMatchArray(['flag' => 'duplicate', 'flaggable_id' => $this->subject->id, 'comment' => 'Repetida con otra clave']);
    });

    it('un comentario en blanco queda vacío', function () {
        expect(app(FlagRecord::class)->handle($this->review, $this->subject, ReviewFlag::MissingData, '   ', $this->actor)->comment)->toBeNull();
    });

    it('rechaza un registro que no es de la revisión y no escribe', function (Closure $record) {
        expect(cvFlagErrors(fn () => app(FlagRecord::class)->handle($this->review, $record(), ReviewFlag::Inactive, null, $this->actor)))
            ->toBe(['record' => ['El registro no es de esta revisión.']])
            ->and(CatalogReviewFlag::count())->toBe(0)
            ->and($this->review->fresh()->status)->toBe(ReviewStatus::Pending);
    })->with([
        'materia de otro departamento' => fn () => Subject::factory()->create(),
        'carrera del departamento' => fn () => Career::factory()->create(['department_id' => test()->review->department_id]),
    ]);

    it('CV-S2: rechaza observar una revisión validada', function () {
        $this->review->update(['status' => ReviewStatus::Validated, 'validated_by' => $this->actor->id, 'validated_at' => now()]);

        expect(cvFlagErrors(fn () => app(FlagRecord::class)->handle($this->review, $this->subject, ReviewFlag::Inactive, null, $this->actor)))
            ->toBe(['status' => ['La revisión ya está validada: reábrela para corregir u observar.']])
            ->and(CatalogReviewFlag::count())->toBe(0);
    });

    it('CV-S2: una observación «otra» exige comentario', function () {
        expect(cvFlagErrors(fn () => app(FlagRecord::class)->handle($this->review, $this->subject, ReviewFlag::Other, ' ', $this->actor)))
            ->toBe(['comment' => ['Escribe la observación.']]);
    });

    it('CV-S2: la capacidad incorrecta solo se observa en salones', function () {
        expect(cvFlagErrors(fn () => app(FlagRecord::class)->handle($this->review, $this->subject, ReviewFlag::WrongCapacity, null, $this->actor)))
            ->toBe(['flag' => ['«Capacidad incorrecta» no aplica a Materias.']]);
    });

    it('avisa con CatalogReviewFlagged', function () {
        Event::fake([CatalogReviewFlagged::class]);

        $flag = app(FlagRecord::class)->handle($this->review, $this->subject, ReviewFlag::Inactive, null, $this->actor);

        Event::assertDispatched(CatalogReviewFlagged::class, fn (CatalogReviewFlagged $event): bool => $event->flag->is($flag));
    });
});

describe('ResolveFlag', function () {
    beforeEach(function () {
        $this->flag = CatalogReviewFlag::factory()->for($this->review, 'review')->on($this->subject)->create();
    });

    it('la marca resuelta con quién y cuándo, con bitácora y aviso', function () {
        Event::fake([CatalogReviewFlagResolved::class]);

        app(ResolveFlag::class)->handle($this->review, $this->flag, $this->actor);

        $entry = Activity::sole();
        expect($this->flag->fresh())
            ->resolved_by->toBe($this->actor->id)
            ->resolved_at->not->toBeNull()
            ->and($entry->event)->toBe('resolved')
            ->and($entry->subject->is($this->flag))->toBeTrue()
            ->and($entry->causer->is($this->actor))->toBeTrue()
            ->and($entry->attribute_changes['old'])->toBe(['resolved_at' => null, 'resolved_by' => null])
            ->and($entry->attribute_changes['attributes']['resolved_by'])->toBe($this->actor->id);
        Event::assertDispatched(CatalogReviewFlagResolved::class, fn (CatalogReviewFlagResolved $event): bool => $event->flag->is($this->flag));
    });

    it('es idempotente: una ya resuelta no cambia ni deja otra entrada', function () {
        $first = User::factory()->create();
        app(ResolveFlag::class)->handle($this->review, $this->flag, $first);
        Event::fake([CatalogReviewFlagResolved::class]);

        app(ResolveFlag::class)->handle($this->review, $this->flag->fresh(), $this->actor);

        expect($this->flag->fresh()->resolved_by)->toBe($first->id)
            ->and(Activity::count())->toBe(1);
        Event::assertNotDispatched(CatalogReviewFlagResolved::class);
    });

    it('rechaza una observación que no es de la revisión que se pasa', function () {
        $other = CatalogReview::factory()->general(Catalog::Careers)->create(['department_id' => $this->review->department_id]);

        expect(fn () => app(ResolveFlag::class)->handle($other, $this->flag, $this->actor))->toThrow(ValidationException::class)
            ->and($this->flag->fresh()->resolved_at)->toBeNull();
    });

    it('se resuelve aunque la revisión ya esté validada', function () {
        $this->review->update(['status' => ReviewStatus::Validated, 'validated_by' => $this->actor->id, 'validated_at' => now()]);

        app(ResolveFlag::class)->handle($this->review, $this->flag, $this->actor);

        expect($this->flag->fresh()->resolved_at)->not->toBeNull();
    });
});
