<?php

namespace App\Modules\AcademicLoad\Actions\Agreements;

use App\Modules\AcademicLoad\Enums\AgreementStatus;
use App\Modules\AcademicLoad\Events\AgreementCancelled;
use App\Modules\AcademicLoad\Models\InterdepartmentalAgreement;
use App\Modules\AcademicLoad\Rules\AgreementRules;
use App\Modules\Core\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Quien pidió un acuerdo vivo lo cancela porque ya no lo necesita (reglas-de-negocio.md, "Acuerdos entre departamentos"; supuesto por confirmar).
 *
 * Relee el acuerdo con bloqueo (el estado que vale es el de la base) y exige que siga vivo, propuesto o aceptado (AgreementRules::ensureLive), sobre `status`. Lo deja cancelado
 * con quién y cuándo (`cancelled_by/at`; la decisión, si hubo, queda como estaba), deja bitácora `cancelled` y, después del commit, avisa con AgreementCancelled. Quién puede lo decide
 * InterdepartmentalAgreementPolicy antes de llamarla; quien actúa se recibe como parámetro.
 *
 * `replaced` va en el evento: quien cancela pide en ese mismo momento otro que lo reemplaza (SyncSessionLoans), así que
 * el aviso del nuevo basta.
 */
final class CancelAgreement
{
    public function __construct(private readonly AgreementRules $rules) {}

    /**
     * @throws ValidationException
     */
    public function handle(InterdepartmentalAgreement $agreement, User $actor, bool $replaced = false): InterdepartmentalAgreement
    {
        return DB::transaction(function () use ($agreement, $actor, $replaced): InterdepartmentalAgreement {
            $agreement->setRawAttributes($agreement->newQuery()->lockForUpdate()->findOrFail($agreement->getKey())->getAttributes(), sync: true);

            $this->rules->ensureLive($agreement);

            $old = $agreement->status;
            $agreement->update(['status' => AgreementStatus::Cancelled, 'cancelled_by' => $actor->getKey(), 'cancelled_at' => now()]);

            activity()
                ->performedOn($agreement)
                ->causedBy($actor)
                ->event('cancelled')
                ->withChanges(['old' => ['status' => $old->value], 'attributes' => ['status' => AgreementStatus::Cancelled->value]])
                ->withProperties(['kind' => $agreement->kind->value, 'group_id' => $agreement->group_id])
                ->log('Canceló el acuerdo entre departamentos');

            AgreementCancelled::dispatch($agreement, $replaced);

            return $agreement;
        });
    }
}
