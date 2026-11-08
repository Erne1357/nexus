<?php

use App\Modules\AcademicLoad\Actions\Agreements\RejectAgreement;
use App\Modules\AcademicLoad\Enums\AgreementStatus;
use App\Modules\AcademicLoad\Events\AgreementRejected;
use App\Modules\AcademicLoad\Models\InterdepartmentalAgreement;
use App\Modules\Core\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;

/*
| RejectAgreement: el departamento al que va dirigido un acuerdo propuesto lo rechaza con motivo obligatorio,
| recortado y de hasta 1000 caracteres (supuesto por confirmar).
*/

beforeEach(function () {
    $this->actor = User::factory()->create();
    $this->agreement = InterdepartmentalAgreement::factory()->create();
});

/**
 * @return array<string, list<string>>|null
 */
function rejectErrors(InterdepartmentalAgreement $agreement, string $comment = 'Ese bloque ya lo usa otro grupo.'): ?array
{
    try {
        app(RejectAgreement::class)->handle($agreement, $comment, test()->actor);
    } catch (ValidationException $e) {
        return $e->errors();
    }

    return null;
}

it('rechaza con el motivo recortado, quién y cuándo', function () {
    $rejected = app(RejectAgreement::class)->handle($this->agreement, '  Ya lo usa otro grupo.  ', $this->actor)->fresh();

    expect($rejected->status)->toBe(AgreementStatus::Rejected)
        ->and($rejected->comment)->toBe('Ya lo usa otro grupo.')
        ->and($rejected->decided_by)->toBe($this->actor->id)
        ->and($rejected->decided_at)->not->toBeNull();
});

it('deja bitácora con el motivo', function () {
    app(RejectAgreement::class)->handle($this->agreement, 'Ya lo usa otro grupo.', $this->actor);

    $activity = Activity::query()->sole();
    expect($activity->subject_id)->toBe($this->agreement->id)
        ->and($activity->causer_id)->toBe($this->actor->id)
        ->and($activity->event)->toBe('rejected')
        ->and($activity->attribute_changes['old'])->toBe(['status' => 'proposed', 'comment' => null])
        ->and($activity->attribute_changes['attributes'])->toBe(['status' => 'rejected', 'comment' => 'Ya lo usa otro grupo.']);
});

it('exige un motivo de hasta 1000 caracteres y no escribe nada', function (string $comment, string $message) {
    expect(rejectErrors($this->agreement, $comment))->toBe(['comment' => [$message]])
        ->and($this->agreement->fresh()->status)->toBe(AgreementStatus::Proposed)
        ->and(Activity::query()->count())->toBe(0);
})->with([
    'vacío' => ['', 'Escribe por qué rechazas el acuerdo.'],
    'solo espacios' => ['   ', 'Escribe por qué rechazas el acuerdo.'],
    'muy largo' => [fn () => str_repeat('a', 1001), 'El comentario lleva hasta 1000 caracteres.'],
]);

it('acepta un motivo de 1000 caracteres', function () {
    expect(app(RejectAgreement::class)->handle($this->agreement, str_repeat('ñ', 1000), $this->actor)->fresh()->comment)->toBe(str_repeat('ñ', 1000));
});

it('no decide un acuerdo ya decidido ni cancelado, sin escribir', function (AgreementStatus $status) {
    $agreement = InterdepartmentalAgreement::factory()->inStatus($status)->create();

    expect(rejectErrors($agreement))->toBe(['status' => ['Este acuerdo ya se decidió.']])
        ->and($agreement->fresh()->status)->toBe($status)
        ->and(Activity::query()->count())->toBe(0);
})->with([AgreementStatus::Accepted, AgreementStatus::Rejected, AgreementStatus::Cancelled]);

it('lee el estado con bloqueo: si otro proceso lo aceptó antes, rechaza aunque el modelo esté desactualizado', function () {
    $stale = InterdepartmentalAgreement::query()->findOrFail($this->agreement->id);
    DB::table('interdepartmental_agreements')->where('id', $this->agreement->id)
        ->update(['status' => 'accepted', 'decided_by' => $this->actor->id, 'decided_at' => now()]);

    expect(rejectErrors($stale))->toBe(['status' => ['Este acuerdo ya se decidió.']])
        ->and($this->agreement->fresh()->status)->toBe(AgreementStatus::Accepted);
});

it('avisa con AgreementRejected solo después del commit', function () {
    Event::fake([AgreementRejected::class]);

    try {
        DB::transaction(function () {
            app(RejectAgreement::class)->handle($this->agreement, 'No hay lugar.', $this->actor);

            throw new RuntimeException('revertir');
        });
    } catch (RuntimeException) {
    }
    Event::assertNotDispatched(AgreementRejected::class);
    expect($this->agreement->fresh()->status)->toBe(AgreementStatus::Proposed);

    app(RejectAgreement::class)->handle($this->agreement, 'No hay lugar.', $this->actor);
    Event::assertDispatched(AgreementRejected::class, fn (AgreementRejected $event): bool => $event->agreement->is($this->agreement));
});
