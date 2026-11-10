<?php

namespace App\Modules\AcademicLoad\Actions\Agreements;

use App\Modules\Academic\Models\Department;
use App\Modules\AcademicLoad\Enums\AgreementKind;
use App\Modules\AcademicLoad\Enums\AgreementStatus;
use App\Modules\AcademicLoad\Events\AgreementRequested;
use App\Modules\AcademicLoad\Models\InterdepartmentalAgreement;
use App\Modules\AcademicLoad\Queries\RoomLoansQuery;
use App\Modules\AcademicLoad\Rules\AgreementRules;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\User;
use App\Modules\Core\Support\WeekTime;
use App\Modules\Facilities\Models\Room;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Un departamento pide el préstamo de un salón de otro departamento para un día y unas horas de un grupo
 * (reglas-de-negocio.md, "Acuerdos entre departamentos"; HC-10). El salón debe ser del campus y tener dueño
 * distinto de quien pide (AgreementRules), sobre `room_id`; el día y las horas, sobre `day` y `starts_at`.
 *
 * Idempotente: si el departamento ya tiene un préstamo vivo del mismo grupo, salón, día y horas, o uno aceptado del grupo que
 * cubre la sesión, solo o junto a otros contiguos (RoomLoansQuery::covers), lo regresa sin escribir. Si no, crea uno propuesto dirigido al dueño del
 * salón. Si la exclusión `interdepartmental_agreements_room_no_overlap` lo rechaza (otro préstamo vivo a esa hora),
 * lanza una ValidationException sobre `room_id`; el INSERT corre en un savepoint para no abortar la transacción de
 * quien llama. Deja bitácora `requested` y, después del commit, avisa con AgreementRequested. El grupo y su departamento (`from`) los da quien llama (Scheduling, que sí ve el grupo; AcademicLoad no). Al mover
 * una sesión, quien llama cancela antes los préstamos de su horario anterior: si no, la exclusión rechaza su propia petición.
 * Quién puede lo decide
 * InterdepartmentalAgreementPolicy::request antes de llamarla.
 */
final class RequestRoomLoan
{
    private const string OVERLAP_CONSTRAINT = 'interdepartmental_agreements_room_no_overlap';

    public function __construct(
        private readonly AgreementRules $rules,
        private readonly RoomLoansQuery $loans,
    ) {}

    /**
     * @throws ValidationException
     */
    public function handle(Term $term, Campus $campus, Department $from, string $groupId, Room $room, int $day, string $startsAt, string $endsAt, User $actor): InterdepartmentalAgreement
    {
        $this->rules->ensureRoomNeedsLoan($room, $campus, $from);
        $this->rules->ensureSlot($day, $startsAt, $endsAt);

        return DB::transaction(function () use ($term, $campus, $from, $groupId, $room, $day, $startsAt, $endsAt, $actor): InterdepartmentalAgreement {
            $existing = InterdepartmentalAgreement::query()
                ->where('kind', AgreementKind::RoomLoan->value)
                ->where('term_id', $term->getKey())
                ->where('from_department_id', $from->getKey())
                ->where('group_id', $groupId)
                ->where('room_id', $room->getKey())
                ->where('day', $day)
                ->where('starts_at', $startsAt)
                ->where('ends_at', $endsAt)
                ->live()
                ->first();

            if ($existing !== null) {
                return $existing;
            }

            if ($this->loans->covers($term->getKey(), $room->getKey(), $groupId, $day, $startsAt, $endsAt)) {
                return $this->loans->loanFor($term->getKey(), $room->getKey(), $groupId, $day, $startsAt, $endsAt);
            }

            try {
                $agreement = DB::transaction(fn (): InterdepartmentalAgreement => InterdepartmentalAgreement::query()->create([
                    'term_id' => $term->getKey(),
                    'campus_id' => $campus->getKey(),
                    'kind' => AgreementKind::RoomLoan,
                    'from_department_id' => $from->getKey(),
                    'to_department_id' => $room->department_id,
                    'group_id' => $groupId,
                    'room_id' => $room->getKey(),
                    'day' => $day,
                    'starts_at' => $startsAt,
                    'ends_at' => $endsAt,
                    'status' => AgreementStatus::Proposed,
                    'requested_by' => $actor->getKey(),
                ]));
            } catch (QueryException $e) {
                if (str_contains($e->getMessage(), self::OVERLAP_CONSTRAINT)) {
                    throw ValidationException::withMessages(['room_id' => 'El salón ya está prestado o pedido a esa hora.']);
                }

                throw $e;
            }

            $to = Department::withTrashed()->findOrFail($room->department_id);

            activity()
                ->performedOn($agreement)
                ->causedBy($actor)
                ->event('requested')
                ->withChanges(['attributes' => [
                    'kind' => AgreementKind::RoomLoan->value,
                    'status' => AgreementStatus::Proposed->value,
                    'room_id' => $room->getKey(),
                    'day' => $day,
                    'starts_at' => $startsAt,
                    'ends_at' => $endsAt,
                ]])
                ->withProperties(['group_id' => $groupId, 'from_department_id' => $from->getKey(), 'to_department_id' => $to->getKey()])
                ->log("{$from->code} pidió a {$to->code} el salón {$room->code} el ".WeekTime::day($day).' de '.WeekTime::clock($startsAt).' a '.WeekTime::clock($endsAt)." en {$campus->code} para el periodo {$term->code}");

            AgreementRequested::dispatch($agreement);

            return $agreement;
        });
    }
}
