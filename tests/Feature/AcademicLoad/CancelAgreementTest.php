<?php

use App\Modules\AcademicLoad\Actions\Agreements\CancelAgreement;
use App\Modules\AcademicLoad\Enums\AgreementStatus;
use App\Modules\AcademicLoad\Events\AgreementCancelled;
use App\Modules\AcademicLoad\Models\InterdepartmentalAgreement;
use App\Modules\Core\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;

/*
| CancelAgreement: quien pidió un acuerdo vivo (propuesto o aceptado) lo cancela cuando ya no lo necesita
| (supuesto por confirmar). Cancelarlo libera el salón o el cupo.
*/

beforeEach(function () {
    $this->actor = User::factory()->create();
});

it('cancela un acuerdo propuesto o aceptado con quién y cuándo', function (AgreementStatus $status) {
    $agreement = InterdepartmentalAgreement::factory()->inStatus($status)->create();

    $cancelled = app(CancelAgreement::class)->handle($agreement, $this->actor)->fresh();

    expect($cancelled->status)->toBe(AgreementStatus::Cancelled)
        ->and($cancelled->decided_by)->toBe($this->actor->id)
        ->and($cancelled->decided_at)->not->toBeNull();
})->with([AgreementStatus::Proposed, AgreementStatus::Accepted]);

it('deja bitácora con el estado anterior', function () {
    $agreement = InterdepartmentalAgreement::factory()->accepted()->create();

    app(CancelAgreement::class)->handle($agreement, $this->actor);

    $activity = Activity::query()->sole();
    expect($activity->subject_id)->toBe($agreement->id)
        ->and($activity->causer_id)->toBe($this->actor->id)
        ->and($activity->event)->toBe('cancelled')
        ->and($activity->attribute_changes['old'])->toBe(['status' => 'accepted'])
        ->and($activity->attribute_changes['attributes'])->toBe(['status' => 'cancelled']);
});

it('al cancelar el préstamo libera el salón para otro', function () {
    $agreement = InterdepartmentalAgreement::factory()->create();

    app(CancelAgreement::class)->handle($agreement, $this->actor);

    expect(fn () => InterdepartmentalAgreement::factory()->create(['term_id' => $agreement->term_id, 'campus_id' => $agreement->campus_id, 'room_id' => $agreement->room_id]))
        ->not->toThrow(Throwable::class);
});

it('no cancela un acuerdo que ya no está vivo, sin escribir', function (AgreementStatus $status) {
    $agreement = InterdepartmentalAgreement::factory()->inStatus($status)->create();

    try {
        app(CancelAgreement::class)->handle($agreement, $this->actor);
        $errors = null;
    } catch (ValidationException $e) {
        $errors = $e->errors();
    }

    expect($errors)->toBe(['status' => ['Este acuerdo ya no está vivo.']])
        ->and($agreement->fresh()->status)->toBe($status)
        ->and(Activity::query()->count())->toBe(0);
})->with([AgreementStatus::Rejected, AgreementStatus::Cancelled]);

it('lee el estado con bloqueo: si otro proceso lo rechazó antes, no lo cancela', function () {
    $agreement = InterdepartmentalAgreement::factory()->create();
    $stale = InterdepartmentalAgreement::query()->findOrFail($agreement->id);
    DB::table('interdepartmental_agreements')->where('id', $agreement->id)
        ->update(['status' => 'rejected', 'comment' => 'No.', 'decided_by' => $this->actor->id, 'decided_at' => now()]);

    expect(fn () => app(CancelAgreement::class)->handle($stale, $this->actor))->toThrow(ValidationException::class)
        ->and($agreement->fresh()->status)->toBe(AgreementStatus::Rejected);
});

it('avisa con AgreementCancelled solo después del commit', function () {
    Event::fake([AgreementCancelled::class]);
    $agreement = InterdepartmentalAgreement::factory()->create();

    try {
        DB::transaction(function () use ($agreement) {
            app(CancelAgreement::class)->handle($agreement, $this->actor);

            throw new RuntimeException('revertir');
        });
    } catch (RuntimeException) {
    }
    Event::assertNotDispatched(AgreementCancelled::class);
    expect($agreement->fresh()->status)->toBe(AgreementStatus::Proposed);

    app(CancelAgreement::class)->handle($agreement, $this->actor);
    Event::assertDispatched(AgreementCancelled::class, fn (AgreementCancelled $event): bool => $event->agreement->is($agreement));
});
