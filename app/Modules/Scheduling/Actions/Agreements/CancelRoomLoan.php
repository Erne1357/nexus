<?php

namespace App\Modules\Scheduling\Actions\Agreements;

use App\Modules\AcademicLoad\Actions\Agreements\CancelAgreement;
use App\Modules\AcademicLoad\Enums\AgreementKind;
use App\Modules\AcademicLoad\Models\InterdepartmentalAgreement;
use App\Modules\Core\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Quien pidió un préstamo lo cancela (reglas-de-negocio.md, "Acuerdos entre departamentos"; supuesto por confirmar): como
 * un préstamo cancelado no aparta el salón, se quita de las sesiones del grupo que cubría (ClearLoanRoom), con bitácora
 * `room_removed_by_cancelled_loan` en el grupo y GroupScheduleChanged después del commit. Da cuántas sesiones quedaron
 * sin salón. Para un grupo compartido se usa CancelAgreement.
 */
final class CancelRoomLoan
{
    public const string EVENT = 'room_removed_by_cancelled_loan';

    public function __construct(private readonly CancelAgreement $cancel, private readonly ClearLoanRoom $clear) {}

    /**
     * @throws ValidationException
     */
    public function handle(InterdepartmentalAgreement $agreement, User $actor): int
    {
        if ($agreement->kind !== AgreementKind::RoomLoan) {
            throw ValidationException::withMessages(['kind' => 'Este acuerdo no es un préstamo de salón.']);
        }

        return DB::transaction(function () use ($agreement, $actor): int {
            $this->cancel->handle($agreement, $actor);

            return $this->clear->handle($agreement, $actor, self::EVENT, 'porque se canceló el préstamo');
        });
    }
}
