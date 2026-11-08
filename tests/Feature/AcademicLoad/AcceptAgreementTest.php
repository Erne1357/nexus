<?php

use App\Modules\Academic\Models\Department;
use App\Modules\AcademicLoad\Actions\Agreements\AcceptAgreement;
use App\Modules\AcademicLoad\Enums\AgreementStatus;
use App\Modules\AcademicLoad\Events\AgreementAccepted;
use App\Modules\AcademicLoad\Models\InterdepartmentalAgreement;
use App\Modules\Core\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;

/*
| AcceptAgreement: el departamento al que va dirigido un acuerdo propuesto lo acepta (reglas-de-negocio.md,
| "Acuerdos entre departamentos"). Lo ya decidido no vuelve a decidirse.
*/

beforeEach(function () {
    $this->actor = User::factory()->create();
    $this->agreement = InterdepartmentalAgreement::factory()->create();
});

it('acepta el acuerdo con quién y cuándo', function (string $kind) {
    $agreement = $kind === 'grupo' ? InterdepartmentalAgreement::factory()->sharedGroup()->create() : $this->agreement;

    $accepted = app(AcceptAgreement::class)->handle($agreement, $this->actor)->fresh();

    expect($accepted->status)->toBe(AgreementStatus::Accepted)
        ->and($accepted->decided_by)->toBe($this->actor->id)
        ->and($accepted->decided_at)->not->toBeNull()
        ->and($accepted->comment)->toBeNull();
})->with(['préstamo', 'grupo']);

it('deja bitácora con el cambio de estado', function () {
    app(AcceptAgreement::class)->handle($this->agreement, $this->actor);

    $activity = Activity::query()->sole();
    expect($activity->subject_id)->toBe($this->agreement->id)
        ->and($activity->causer_id)->toBe($this->actor->id)
        ->and($activity->event)->toBe('accepted')
        ->and($activity->attribute_changes['old'])->toBe(['status' => 'proposed'])
        ->and($activity->attribute_changes['attributes'])->toBe(['status' => 'accepted']);
});

it('no decide un acuerdo ya decidido ni cancelado, sin escribir', function (AgreementStatus $status) {
    $agreement = InterdepartmentalAgreement::factory()->inStatus($status)->create();

    try {
        app(AcceptAgreement::class)->handle($agreement, $this->actor);
        $errors = null;
    } catch (ValidationException $e) {
        $errors = $e->errors();
    }

    expect($errors)->toBe(['status' => ['Este acuerdo ya se decidió.']])
        ->and($agreement->fresh()->status)->toBe($status)
        ->and(Activity::query()->count())->toBe(0);
})->with([AgreementStatus::Accepted, AgreementStatus::Rejected, AgreementStatus::Cancelled]);

it('un préstamo cuyo salón ya no es del departamento no se acepta, sin escribir', function () {
    $this->agreement->room->update(['department_id' => Department::factory()->create()->id]);

    expect(fn () => app(AcceptAgreement::class)->handle($this->agreement, $this->actor))
        ->toThrow(ValidationException::class, 'El salón ya no es de tu departamento.')
        ->and($this->agreement->fresh()->status)->toBe(AgreementStatus::Proposed)
        ->and(Activity::query()->count())->toBe(0);
});

it('un préstamo cuyo salón perdió su dueño no se acepta', function () {
    $this->agreement->room->update(['department_id' => null]);

    expect(fn () => app(AcceptAgreement::class)->handle($this->agreement, $this->actor))
        ->toThrow(ValidationException::class, 'El salón ya no es de tu departamento.');
});

it('un grupo compartido cuya carrera ya no es del departamento no se acepta, sin escribir', function () {
    $shared = InterdepartmentalAgreement::factory()->sharedGroup()->create();
    $shared->career->update(['department_id' => Department::factory()->create()->id]);

    expect(fn () => app(AcceptAgreement::class)->handle($shared, $this->actor))
        ->toThrow(ValidationException::class, 'La carrera ya no es de tu departamento.')
        ->and($shared->fresh()->status)->toBe(AgreementStatus::Proposed)
        ->and(Activity::query()->count())->toBe(0);
});

it('lee el estado con bloqueo: si otro proceso lo decidió antes, rechaza aunque el modelo esté desactualizado', function () {
    $stale = InterdepartmentalAgreement::query()->findOrFail($this->agreement->id);
    DB::table('interdepartmental_agreements')->where('id', $this->agreement->id)
        ->update(['status' => 'cancelled', 'cancelled_by' => $this->actor->id, 'cancelled_at' => now()]);

    expect(fn () => app(AcceptAgreement::class)->handle($stale, $this->actor))->toThrow(ValidationException::class)
        ->and($this->agreement->fresh()->status)->toBe(AgreementStatus::Cancelled);
});

it('avisa con AgreementAccepted solo después del commit', function () {
    Event::fake([AgreementAccepted::class]);

    try {
        DB::transaction(function () {
            app(AcceptAgreement::class)->handle($this->agreement, $this->actor);

            throw new RuntimeException('revertir');
        });
    } catch (RuntimeException) {
    }
    Event::assertNotDispatched(AgreementAccepted::class);
    expect($this->agreement->fresh()->status)->toBe(AgreementStatus::Proposed);

    app(AcceptAgreement::class)->handle($this->agreement, $this->actor);
    Event::assertDispatched(AgreementAccepted::class, fn (AgreementAccepted $event): bool => $event->agreement->is($this->agreement));
});
