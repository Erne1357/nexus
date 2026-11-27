<?php

namespace App\Modules\Scheduling\Actions\Agreements;

use App\Modules\AcademicLoad\Actions\Agreements\CancelAgreement;
use App\Modules\AcademicLoad\Enums\AgreementKind;
use App\Modules\AcademicLoad\Enums\AgreementStatus;
use App\Modules\AcademicLoad\Models\InterdepartmentalAgreement;
use App\Modules\Core\Models\User;
use App\Modules\Scheduling\Models\Group;
use App\Modules\Scheduling\Rules\LoadEditability;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Quien pidió un préstamo propuesto lo cancela (relee con bloqueo y exige que siga propuesto: uno aceptado ya no se cancela aquí) (reglas-de-negocio.md, "Acuerdos entre departamentos"; supuesto por confirmar): como
 * un préstamo cancelado no aparta el salón, se quita de las sesiones del grupo que cubría (ClearLoanRoom), con bitácora
 * `room_removed_by_cancelled_loan` en el grupo y GroupScheduleChanged después del commit; el dueño del salón recibe el
 * aviso (NotifyOwnerOnLoanCancelled, por el AgreementCancelled de CancelAgreement; confirmado el 8 de noviembre de
 * 2026). Da cuántas sesiones quedaron sin salón. Para un grupo compartido se usa CancelAgreement. Toma los candados como las acciones del editor: la carga
 * y el grupo antes que el acuerdo (lockGroup); un deadlock se traduce a un error claro.
 */
final class CancelRoomLoan
{
    public const string EVENT = 'room_removed_by_cancelled_loan';

    public function __construct(
        private readonly CancelAgreement $cancel,
        private readonly ClearLoanRoom $clear,
        private readonly LoadEditability $editability,
    ) {}

    /**
     * @throws ValidationException
     */
    public function handle(InterdepartmentalAgreement $agreement, User $actor): int
    {
        if ($agreement->kind !== AgreementKind::RoomLoan) {
            throw ValidationException::withMessages(['kind' => 'Este acuerdo no es un préstamo de salón.']);
        }

        return $this->editability->guard(fn (): int => DB::transaction(function () use ($agreement, $actor): int {
            $this->lockGroup($agreement);
            $current = $agreement->newQuery()->lockForUpdate()->findOrFail($agreement->getKey());

            if ($current->status !== AgreementStatus::Proposed) {
                throw ValidationException::withMessages(['status' => 'Este acuerdo ya se decidió: ya no se puede cancelar desde aquí.']);
            }

            $this->cancel->handle($agreement, $actor);

            return $this->clear->handle($agreement, $actor, self::EVENT, 'porque se canceló el préstamo');
        }), 'status');
    }

    /**
     * La carga en compartido y el grupo con bloqueo, antes que el acuerdo: el orden de LoadEditability y de las acciones
     * del editor (AssignSessionRoom, UpdateGroupSession), que bloquean el grupo y luego sus préstamos.
     */
    private function lockGroup(InterdepartmentalAgreement $agreement): void
    {
        $group = Group::query()->whereKey($agreement->group_id)->first();

        if ($group !== null) {
            $this->editability->lockLoad($group);
            Group::query()->whereKey($group->id)->lockForUpdate()->first();
        }
    }
}
