<?php

namespace App\Modules\AcademicLoad\Actions\Agreements;

use App\Modules\AcademicLoad\Enums\AgreementKind;
use App\Modules\AcademicLoad\Enums\AgreementStatus;
use App\Modules\AcademicLoad\Events\AgreementSeatsChanged;
use App\Modules\AcademicLoad\Models\InterdepartmentalAgreement;
use App\Modules\AcademicLoad\Rules\AgreementRules;
use App\Modules\Core\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Deja los lugares de un grupo compartido aceptado en los de su cupo (reglas-de-negocio.md, «Cupos por carrera»; supuesto
 * por confirmar, decidido por la coordinación de los rieles el 18 de noviembre de 2026): la cifra vigente es la de
 * `group_quotas`, así que cuando el departamento que imparte cambia los lugares de esa carrera directo (sin nuevo acuerdo,
 * confirmado el 8 de noviembre de 2026; SetGroupQuotas, ArmParallelGroups), el acuerdo toma la cifra nueva. Quién y cuándo
 * lo decidió no cambian. Lo llama Scheduling dentro de su transacción, con el grupo bloqueado.
 *
 * Relee el acuerdo con bloqueo y exige un grupo compartido aceptado y lugares de 1 a 999. Sin cambio no escribe; si cambia,
 * deja bitácora `seats_changed` (de viejo a nuevo) y, después del commit, avisa con AgreementSeatsChanged.
 */
final class ChangeAgreementSeats
{
    public function __construct(private readonly AgreementRules $rules) {}

    /**
     * @throws ValidationException
     */
    public function handle(InterdepartmentalAgreement $agreement, int $seats, User $actor): InterdepartmentalAgreement
    {
        return DB::transaction(function () use ($agreement, $seats, $actor): InterdepartmentalAgreement {
            $agreement->setRawAttributes($agreement->newQuery()->lockForUpdate()->findOrFail($agreement->getKey())->getAttributes(), sync: true);

            if ($agreement->kind !== AgreementKind::SharedGroup || $agreement->status !== AgreementStatus::Accepted) {
                throw ValidationException::withMessages(['max_seats' => 'Solo cambian los lugares de un grupo compartido aceptado.']);
            }

            $this->rules->ensureSeats($seats);
            $from = (int) $agreement->max_seats;

            if ($from === $seats) {
                return $agreement;
            }

            $agreement->update(['max_seats' => $seats]);

            activity()
                ->performedOn($agreement)
                ->causedBy($actor)
                ->event('seats_changed')
                ->withChanges(['old' => ['max_seats' => $from], 'attributes' => ['max_seats' => $seats]])
                ->withProperties(['kind' => $agreement->kind->value, 'group_id' => $agreement->group_id, 'career_id' => $agreement->career_id])
                ->log('Cambió los lugares del grupo compartido');

            AgreementSeatsChanged::dispatch($agreement, $from);

            return $agreement;
        });
    }
}
