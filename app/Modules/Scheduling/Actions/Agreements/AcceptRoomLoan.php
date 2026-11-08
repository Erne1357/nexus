<?php

namespace App\Modules\Scheduling\Actions\Agreements;

use App\Modules\AcademicLoad\Actions\Agreements\AcceptAgreement;
use App\Modules\AcademicLoad\Enums\AgreementKind;
use App\Modules\AcademicLoad\Models\InterdepartmentalAgreement;
use App\Modules\Core\Models\User;
use App\Modules\Facilities\Models\RoomBlock;
use App\Modules\Scheduling\Events\GroupScheduleChanged;
use App\Modules\Scheduling\Models\Group;
use App\Modules\Scheduling\Models\GroupSession;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * El dueño del salón acepta un préstamo (reglas-de-negocio.md, "Acuerdos entre departamentos"; HC-10). Con las sesiones
 * del salón leídas con bloqueo exige que el bloque pedido no se empalme con una sesión de otro grupo ni con un
 * bloqueo vigente del salón (HC-02, HC-11; supuesto por confirmar), y lo rechaza con ValidationException sobre `room_id`.
 * AcademicLoad no ve grupos: esta Action orquesta AcceptAgreement y avisa con GroupScheduleChanged, después del commit,
 * para que el validador recalcule HC-10 del grupo. Quién puede lo decide InterdepartmentalAgreementPolicy antes de llamarla.
 */
final class AcceptRoomLoan
{
    public function __construct(private readonly AcceptAgreement $accept) {}

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
                GroupScheduleChanged::dispatch($group);
            }

            return $agreement;
        });
    }

    /**
     * @throws ValidationException
     */
    private function ensureFree(InterdepartmentalAgreement $agreement): void
    {
        $session = GroupSession::query()
            ->where('room_id', $agreement->room_id)
            ->where('term_id', $agreement->term_id)
            ->where('is_active', true)
            ->where('group_id', '!=', $agreement->group_id)
            ->where('day', $agreement->day)
            ->where('starts_at', '<', $agreement->ends_at)
            ->where('ends_at', '>', $agreement->starts_at)
            ->lockForUpdate()
            ->first();

        $block = RoomBlock::query()
            ->where('room_id', $agreement->room_id)
            ->where(fn (Builder $query) => $query->whereNull('term_id')->orWhere('term_id', $agreement->term_id))
            ->where('day', $agreement->day)
            ->where('starts_at', '<', $agreement->ends_at)
            ->where('ends_at', '>', $agreement->starts_at)
            ->sharedLock()
            ->first();

        if ($session !== null) {
            throw ValidationException::withMessages(['room_id' => 'HC-02: el salón ya está ocupado a esa hora por otro grupo; no se puede aceptar el préstamo.']);
        }

        if ($block !== null) {
            throw ValidationException::withMessages(['room_id' => 'HC-11: el salón está bloqueado a esa hora; no se puede aceptar el préstamo.']);
        }
    }
}
