<?php

namespace App\Modules\Scheduling\Actions\Agreements;

use App\Modules\AcademicLoad\Actions\Agreements\AcceptAgreement;
use App\Modules\AcademicLoad\Enums\AgreementKind;
use App\Modules\AcademicLoad\Models\InterdepartmentalAgreement;
use App\Modules\Core\Models\User;
use App\Modules\Scheduling\Events\GroupScheduleChanged;
use App\Modules\Scheduling\Models\Group;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * El dueño del salón acepta un préstamo (reglas-de-negocio.md, "Acuerdos entre departamentos"; HC-10). AcademicLoad no
 * ve grupos: esta Action orquesta AcceptAgreement y avisa con GroupScheduleChanged, después del commit, para que el
 * validador recalcule HC-10 del grupo (el préstamo aceptado ya cubre el salón). Quién puede lo decide
 * InterdepartmentalAgreementPolicy antes de llamarla.
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
            $this->accept->handle($agreement, $actor);

            $group = Group::query()->find($agreement->group_id);

            if ($group !== null) {
                GroupScheduleChanged::dispatch($group);
            }

            return $agreement;
        });
    }
}
