<?php

namespace App\Modules\Scheduling\Actions\Agreements;

use App\Modules\AcademicLoad\Actions\Agreements\RejectAgreement;
use App\Modules\AcademicLoad\Enums\AgreementKind;
use App\Modules\AcademicLoad\Models\InterdepartmentalAgreement;
use App\Modules\Core\Models\User;
use App\Modules\Scheduling\Events\GroupScheduleChanged;
use App\Modules\Scheduling\Models\Group;
use App\Modules\Scheduling\Models\GroupSession;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * El dueño del salón rechaza un préstamo con motivo (reglas-de-negocio.md, "Acuerdos entre departamentos"; HC-10): el
 * salón se quita de las sesiones del grupo que lo usan en ese salón y se cruzan con el día y las horas del préstamo,
 * y quien lo pidió queda con la sesión sin salón. Orquesta RejectAgreement y el cambio en la misma transacción, con
 * bitácora `room_removed_by_rejected_loan` en el grupo, y avisa con GroupScheduleChanged después del commit. Quién
 * puede lo decide InterdepartmentalAgreementPolicy antes de llamarla.
 */
final class RejectRoomLoan
{
    public function __construct(private readonly RejectAgreement $reject) {}

    /**
     * @throws ValidationException
     */
    public function handle(InterdepartmentalAgreement $agreement, string $comment, User $actor): InterdepartmentalAgreement
    {
        if ($agreement->kind !== AgreementKind::RoomLoan) {
            throw ValidationException::withMessages(['kind' => 'Este acuerdo no es un préstamo de salón.']);
        }

        return DB::transaction(function () use ($agreement, $comment, $actor): InterdepartmentalAgreement {
            $this->reject->handle($agreement, $comment, $actor);

            $group = Group::query()->lockForUpdate()->find($agreement->group_id);

            if ($group === null) {
                return $agreement;
            }

            $sessions = GroupSession::query()
                ->where('group_id', $group->getKey())
                ->where('room_id', $agreement->room_id)
                ->where('day', $agreement->day)
                ->where('starts_at', '<', $agreement->ends_at)
                ->where('ends_at', '>', $agreement->starts_at)
                ->lockForUpdate()
                ->get();

            foreach ($sessions as $session) {
                $session->update(['room_id' => null]);
            }

            if ($sessions->isNotEmpty()) {
                activity()
                    ->causedBy($actor)
                    ->performedOn($group)
                    ->event('room_removed_by_rejected_loan')
                    ->withProperties(['agreement_id' => $agreement->getKey(), 'room_id' => $agreement->room_id, 'sessions' => $sessions->pluck('id')->all()])
                    ->log("Quitó el salón de {$sessions->count()} sesión(es) de {$group->code} porque se rechazó el préstamo");
            }

            GroupScheduleChanged::dispatch($group);

            return $agreement;
        });
    }
}
