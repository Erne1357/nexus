<?php

namespace App\Modules\Scheduling\Actions\Agreements;

use App\Modules\AcademicLoad\Actions\Agreements\RejectAgreement;
use App\Modules\AcademicLoad\Enums\AgreementKind;
use App\Modules\AcademicLoad\Models\InterdepartmentalAgreement;
use App\Modules\AcademicLoad\Rules\AgreementRules;
use App\Modules\Core\Models\User;
use App\Modules\Scheduling\Models\Group;
use App\Modules\Scheduling\Rules\LoadEditability;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * El dueño del salón rechaza un préstamo con motivo (reglas-de-negocio.md, "Acuerdos entre departamentos"; HC-10): el
 * salón se quita de las sesiones del grupo que cubre el préstamo (ClearLoanRoom) y quien lo pidió queda con la sesión
 * sin salón. Antes revisa que `to` siga siendo el dueño del salón (AgreementRules::ensureRecipientStillOwns, como al aceptar). Orquesta RejectAgreement y el cambio en la misma transacción, con bitácora `room_removed_by_rejected_loan`
 * en el grupo, y avisa con GroupScheduleChanged después del commit. Da cuántas sesiones quedaron sin salón. Quién
 * puede lo decide InterdepartmentalAgreementPolicy antes de llamarla. Toma los candados como las acciones del editor:
 * la carga y el grupo antes que el acuerdo (lockGroup); un deadlock se traduce a un error claro.
 */
final class RejectRoomLoan
{
    public const string EVENT = 'room_removed_by_rejected_loan';

    public function __construct(
        private readonly RejectAgreement $reject,
        private readonly ClearLoanRoom $clear,
        private readonly AgreementRules $rules,
        private readonly LoadEditability $editability,
    ) {}

    /**
     * @throws ValidationException
     */
    public function handle(InterdepartmentalAgreement $agreement, string $comment, User $actor): int
    {
        if ($agreement->kind !== AgreementKind::RoomLoan) {
            throw ValidationException::withMessages(['kind' => 'Este acuerdo no es un préstamo de salón.']);
        }

        return $this->editability->guard(fn (): int => DB::transaction(function () use ($agreement, $comment, $actor): int {
            $this->lockGroup($agreement);
            $this->rules->ensureRecipientStillOwns($agreement);
            $this->reject->handle($agreement, $comment, $actor);

            return $this->clear->handle($agreement, $actor, self::EVENT, 'porque se rechazó el préstamo');
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
