<?php

namespace App\Modules\Scheduling\Actions\Agreements;

use App\Modules\AcademicLoad\Actions\Agreements\AcceptAgreement;
use App\Modules\AcademicLoad\Enums\AgreementKind;
use App\Modules\AcademicLoad\Models\InterdepartmentalAgreement;
use App\Modules\Core\Models\User;
use App\Modules\Scheduling\Events\GroupScheduleChanged;
use App\Modules\Scheduling\Models\Group;
use App\Modules\Scheduling\Rules\RoomLoanFree;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * El dueño del salón acepta un préstamo (reglas-de-negocio.md, "Acuerdos entre departamentos"; HC-10). Con las sesiones
 * del salón leídas con bloqueo exige que el bloque pedido no se empalme con una sesión de otro grupo ni con un
 * bloqueo vigente del salón (HC-02, HC-11; supuesto por confirmar; la revisión es RoomLoanFree, la misma que muestra la
 * pantalla Acuerdos), y lo rechaza con ValidationException sobre `room_id`.
 * AcademicLoad no ve grupos: esta Action orquesta AcceptAgreement y avisa con GroupScheduleChanged, después del commit,
 * para que el validador recalcule HC-10 del grupo. Un deadlock se reintenta solo, hasta tres veces. Quién puede lo decide
 * InterdepartmentalAgreementPolicy antes de llamarla.
 */
final class AcceptRoomLoan
{
    public function __construct(
        private readonly AcceptAgreement $accept,
        private readonly RoomLoanFree $free,
    ) {}

    /**
     * @throws ValidationException
     */
    public function handle(InterdepartmentalAgreement $agreement, User $actor): InterdepartmentalAgreement
    {
        if ($agreement->kind !== AgreementKind::RoomLoan) {
            throw ValidationException::withMessages(['kind' => 'Este acuerdo no es un préstamo de salón.']);
        }

        return DB::transaction(function () use ($agreement, $actor): InterdepartmentalAgreement {
            $this->ensureFree($agreement);
            $this->accept->handle($agreement, $actor);

            $group = Group::query()->find($agreement->group_id);

            if ($group !== null) {
                GroupScheduleChanged::dispatch($group, timesChanged: false);
            }

            return $agreement;
        }, 3);
    }

    /**
     * HC-02 y HC-11 con RoomLoanFree, leyendo las sesiones del salón ese día con bloqueo.
     *
     * @throws ValidationException
     */
    private function ensureFree(InterdepartmentalAgreement $agreement): void
    {
        $clash = $this->free->clashFor($agreement, lock: true);

        if ($clash === null) {
            return;
        }

        throw ValidationException::withMessages(['room_id' => $clash['rule'] === 'HC-11'
            ? 'HC-11: el salón está bloqueado a esa hora; no se puede aceptar el préstamo.'
            : 'HC-02: el salón ya está ocupado a esa hora por otro grupo; no se puede aceptar el préstamo.']);
    }
}
