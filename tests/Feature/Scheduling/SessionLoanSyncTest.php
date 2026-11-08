<?php

use App\Modules\Academic\Models\Department;
use App\Modules\Academic\Models\Subject;
use App\Modules\AcademicLoad\Enums\AgreementStatus;
use App\Modules\AcademicLoad\Models\InterdepartmentalAgreement;
use App\Modules\Core\Authorization\Actor;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\User;
use App\Modules\Core\Notifications\NotificationMessage;
use App\Modules\Facilities\Models\Building;
use App\Modules\Facilities\Models\Room;
use App\Modules\Scheduling\Actions\Sessions\LoanSyncResult;
use App\Modules\Scheduling\Actions\Sessions\SyncSessionLoans;
use App\Modules\Scheduling\Enums\SessionKind;
use App\Modules\Scheduling\Models\Group;
use App\Modules\Scheduling\Models\GroupSession;
use App\Modules\Scheduling\Notifications\AgreementReceived;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;

/*
| SyncSessionLoans (tarea 3.7, HC-10): deja los préstamos de un grupo en un salón de otro departamento, en un día, al
| tamaño de sus sesiones. Un aceptado se conserva mientras todas sus horas caigan en sesiones del grupo; si aparta horas
| sin sesión se cancela y se pide de nuevo lo que sí se usa. Lo que no cubren los aceptados se pide; un propuesto exacto
| se queda y uno que no coincide se cancela y se pide de nuevo; lo que ya no toca ninguna sesión se cancela. Si el
| préstamo vivo de otro grupo ocupa el hueco, no se pide y se dice (o, en modo estricto, se detiene con su mensaje). Un
| salón sin dueño o del mismo departamento no necesita préstamo (supuesto por confirmar).
*/

beforeEach(function () {
    $this->actor = User::factory()->create();
    $this->term = Term::factory()->create();
    $this->campus = Campus::factory()->create();
    $this->mine = Department::factory()->create(['code' => 'SC']);
    $this->owner = Department::factory()->create(['code' => 'MM']);
    $this->group = Group::factory()->create([
        'term_id' => $this->term->id,
        'campus_id' => $this->campus->id,
        'subject_id' => Subject::factory()->create(['department_id' => $this->mine->id])->id,
    ]);
    $this->room = syncRoom(['department_id' => $this->owner->id, 'code' => 'MM-L2']);
});

function syncRoom(array $attributes = []): Room
{
    return Room::factory()->create(['building_id' => Building::factory()->create(['campus_id' => test()->campus->id])->id, ...$attributes]);
}

function syncSession(int $day, string $startsAt, string $endsAt, ?Room $room = null, SessionKind $kind = SessionKind::Teoria): GroupSession
{
    return GroupSession::factory()->for(test()->group)->inRoom($room ?? test()->room)->on($day, $startsAt, $endsAt)->create(['kind' => $kind]);
}

function syncLoan(int $day, string $startsAt, string $endsAt, AgreementStatus $status = AgreementStatus::Proposed, array $attributes = []): InterdepartmentalAgreement
{
    return InterdepartmentalAgreement::factory()->inStatus($status)->create([
        'term_id' => test()->term->id,
        'campus_id' => test()->campus->id,
        'group_id' => test()->group->id,
        'room_id' => test()->room->id,
        'from_department_id' => test()->mine->id,
        'to_department_id' => test()->owner->id,
        'day' => $day,
        'starts_at' => $startsAt,
        'ends_at' => $endsAt,
        ...$attributes,
    ]);
}

function resyncLoans(?Room $room = null, int $day = 1, bool $strict = false): LoanSyncResult
{
    return app(SyncSessionLoans::class)->resync(test()->group, $room ?? test()->room, $day, test()->actor, $strict);
}

/**
 * Un préstamo vivo de otro grupo en el salón.
 */
function otherGroupLoanOfSync(int $day, string $startsAt, string $endsAt, AgreementStatus $status = AgreementStatus::Proposed): InterdepartmentalAgreement
{
    return syncLoan($day, $startsAt, $endsAt, $status, ['group_id' => Group::factory()->create(['term_id' => test()->term->id, 'campus_id' => test()->campus->id])->id]);
}

/**
 * Los préstamos vivos del grupo como «día inicio-fin estado».
 *
 * @return list<string>
 */
function liveLoansOfSync(): array
{
    return InterdepartmentalAgreement::query()->where('group_id', test()->group->id)->live()
        ->orderBy('day')->orderBy('starts_at')->get()
        ->map(fn (InterdepartmentalAgreement $loan): string => "{$loan->day} ".substr($loan->starts_at, 0, 5).'-'.substr($loan->ends_at, 0, 5)." {$loan->status->value}")
        ->all();
}

it('HC-10: pide el préstamo de una sesión en un salón de otro departamento, de quien imparte al dueño', function () {
    syncSession(1, '09:00', '11:00');

    $result = resyncLoans();

    $loan = InterdepartmentalAgreement::query()->sole();

    expect($result)->requested->toBeTrue()->blocked->toBeFalse()
        ->and(liveLoansOfSync())->toBe(['1 09:00-11:00 proposed'])
        ->and($loan->from_department_id)->toBe($this->mine->id)
        ->and($loan->to_department_id)->toBe($this->owner->id)
        ->and($loan->requested_by)->toBe($this->actor->id);
});

it('HC-10: pide solo el hueco que no cubren los aceptados, que se conservan aunque sean varios contiguos', function () {
    syncSession(1, '09:00', '12:00');
    syncLoan(1, '09:00', '10:00', AgreementStatus::Accepted);
    syncLoan(1, '10:00', '11:00', AgreementStatus::Accepted);

    resyncLoans();

    expect(liveLoansOfSync())->toBe(['1 09:00-10:00 accepted', '1 10:00-11:00 accepted', '1 11:00-12:00 proposed']);
});

it('HC-10: aceptados contiguos que cubren toda la sesión no piden nada', function () {
    syncSession(1, '09:00', '11:00');
    syncLoan(1, '09:00', '10:00', AgreementStatus::Accepted);
    syncLoan(1, '10:00', '11:00', AgreementStatus::Accepted);

    resyncLoans();

    expect(liveLoansOfSync())->toBe(['1 09:00-10:00 accepted', '1 10:00-11:00 accepted'])
        ->and(Activity::query()->count())->toBe(0);
});

it('HC-10: un propuesto exacto para el hueco se queda sin pedir otro', function () {
    syncSession(1, '09:00', '11:00');
    $loan = syncLoan(1, '09:00', '11:00');

    resyncLoans();

    expect(InterdepartmentalAgreement::query()->sole()->is($loan))->toBeTrue()
        ->and($loan->fresh()->status)->toBe(AgreementStatus::Proposed)
        ->and(Activity::query()->count())->toBe(0);
});

it('HC-10: un propuesto que se cruza con el hueco y no coincide se cancela y se pide uno por el hueco', function () {
    syncSession(1, '09:00', '12:00');
    $stale = syncLoan(1, '09:00', '11:00');

    resyncLoans();

    expect($stale->fresh())
        ->status->toBe(AgreementStatus::Cancelled)
        ->cancelled_by->toBe($this->actor->id)
        ->and(liveLoansOfSync())->toBe(['1 09:00-12:00 proposed']);
});

it('HC-10: cancela los préstamos vivos del grupo en el salón y día que ya no tocan ninguna sesión, aceptados o propuestos', function () {
    syncSession(1, '07:00', '09:00');
    $kept = syncLoan(1, '07:00', '09:00');
    $accepted = syncLoan(1, '09:00', '10:00', AgreementStatus::Accepted);
    $proposed = syncLoan(1, '16:00', '17:00');
    $otherDay = syncLoan(2, '16:00', '17:00');
    $otherGroup = otherGroupLoanOfSync(1, '12:00', '13:00');

    resyncLoans();

    expect($accepted->fresh()->status)->toBe(AgreementStatus::Cancelled)
        ->and($proposed->fresh()->status)->toBe(AgreementStatus::Cancelled)
        ->and($kept->fresh()->status)->toBe(AgreementStatus::Proposed)
        ->and($otherDay->fresh()->status)->toBe(AgreementStatus::Proposed)
        ->and($otherGroup->fresh()->status)->toBe(AgreementStatus::Proposed);
});

it('HC-10: dos sesiones del grupo en el mismo salón y día piden un préstamo cada una', function () {
    syncSession(1, '07:00', '09:00');
    syncSession(1, '09:00', '10:00', kind: SessionKind::Practica);
    syncSession(1, '12:00', '13:00', syncRoom(['department_id' => $this->owner->id]));

    resyncLoans();

    expect(liveLoansOfSync())->toBe(['1 07:00-09:00 proposed', '1 09:00-10:00 proposed']);
});

it('un salón sin dueño o del mismo departamento no pide préstamo y cancela lo que hubiera vivo de ese grupo, salón y día', function (?string $owner) {
    syncSession(1, '09:00', '11:00');
    $stale = syncLoan(1, '09:00', '11:00', AgreementStatus::Accepted);
    $this->room->update(['department_id' => $owner === 'mine' ? $this->mine->id : null]);

    resyncLoans();

    expect($stale->fresh()->status)->toBe(AgreementStatus::Cancelled)
        ->and(InterdepartmentalAgreement::query()->live()->count())->toBe(0);
})->with(['sin dueño' => [null], 'del mismo departamento' => ['mine']]);

it('deja en la bitácora cada préstamo cancelado y pedido, causados por quien actúa', function () {
    syncSession(1, '09:00', '12:00');
    syncLoan(1, '09:00', '11:00');

    resyncLoans();

    expect(Activity::query()->orderBy('id')->pluck('event')->all())->toBe(['cancelled', 'requested'])
        ->and(Activity::query()->pluck('causer_id')->unique()->all())->toBe([$this->actor->id]);
});

it('HC-10: un aceptado que aparta horas sin sesión se cancela y se pide de nuevo lo que sí se usa (supuesto por confirmar)', function () {
    syncSession(1, '09:00', '10:00');
    $wide = syncLoan(1, '09:00', '11:00', AgreementStatus::Accepted);

    resyncLoans();

    expect($wide->fresh()->status)->toBe(AgreementStatus::Cancelled)
        ->and(liveLoansOfSync())->toBe(['1 09:00-10:00 proposed']);
});

it('HC-10: un aceptado que cae dentro de dos sesiones pegadas del grupo se conserva', function () {
    syncSession(1, '09:00', '10:00');
    syncSession(1, '10:00', '11:00', kind: SessionKind::Practica);
    $loan = syncLoan(1, '09:00', '11:00', AgreementStatus::Accepted);

    resyncLoans();

    expect(liveLoansOfSync())->toBe(['1 09:00-11:00 accepted'])
        ->and($loan->fresh()->status)->toBe(AgreementStatus::Accepted);
});

it('HC-10: si el préstamo vivo de otro grupo ocupa el hueco, no lo pide, cancela lo que ya no sirve y lo dice', function () {
    syncSession(1, '09:00', '12:00');
    $stale = syncLoan(1, '09:00', '10:00');
    otherGroupLoanOfSync(1, '11:00', '13:00', AgreementStatus::Accepted);

    $result = resyncLoans();

    expect($result)->requested->toBeFalse()->blocked->toBeTrue()
        ->and($stale->fresh()->status)->toBe(AgreementStatus::Cancelled)
        ->and(liveLoansOfSync())->toBe([]);
});

it('HC-10: en modo estricto, el préstamo vivo de otro grupo detiene con su mensaje y no cancela nada', function () {
    syncSession(1, '09:00', '12:00');
    $stale = syncLoan(1, '09:00', '10:00');
    otherGroupLoanOfSync(1, '11:00', '13:00');

    expect(fn () => resyncLoans(strict: true))->toThrow(ValidationException::class, 'El salón ya está prestado o pedido a esa hora.')
        ->and($stale->fresh()->status)->toBe(AgreementStatus::Proposed);
});

it('avisa al dueño una vez por préstamo pedido: un propuesto que ya coincide no vuelve a avisar y el aviso del cancelado ya no se abre', function () {
    $jefe = User::factory()->withRole(Role::JefeDepartamento, ['campus_id' => $this->campus->id, 'department_id' => $this->owner->id])->create();
    $session = syncSession(1, '09:00', '11:00');

    resyncLoans();
    resyncLoans();

    expect($jefe->notifications()->count())->toBe(1);
    $first = NotificationMessage::fromArray($jefe->notifications()->sole()->data);

    $session->update(['ends_at' => '12:00']);
    resyncLoans();

    $context = app(Actor::class)->forUser($jefe);
    $second = NotificationMessage::fromArray($jefe->notifications()->get()->first(fn ($notification) => $notification->data['parameters']['acuerdo'] !== $first->parameters['acuerdo'])->data);

    expect($jefe->notifications()->count())->toBe(2)
        ->and(AgreementReceived::unavailableReason($context, $first))->toBe('Este acuerdo se canceló.')
        ->and(AgreementReceived::unavailableReason($context, $second))->toBeNull();
});
