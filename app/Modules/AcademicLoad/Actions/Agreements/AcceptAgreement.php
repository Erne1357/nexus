<?php

namespace App\Modules\AcademicLoad\Actions\Agreements;

use App\Modules\AcademicLoad\Enums\AgreementStatus;
use App\Modules\AcademicLoad\Events\AgreementAccepted;
use App\Modules\AcademicLoad\Models\InterdepartmentalAgreement;
use App\Modules\AcademicLoad\Rules\AgreementRules;
use App\Modules\Core\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * El departamento al que va dirigido un acuerdo propuesto lo acepta (reglas-de-negocio.md, "Acuerdos entre departamentos"; HC-07 y HC-10).
 *
 * Relee el acuerdo con bloqueo (el estado que vale es el de la base) y exige que siga propuesto (AgreementRules::ensureProposed), sobre `status`. Lo deja aceptado
 * con quién y cuándo, deja bitácora `accepted` y, después del commit, avisa con AgreementAccepted. Quién puede lo decide
 * InterdepartmentalAgreementPolicy antes de llamarla; quien actúa se recibe como parámetro.
 */
final class AcceptAgreement
{
    public function __construct(private readonly AgreementRules $rules) {}

    /**
     * @throws ValidationException
     */
    public function handle(InterdepartmentalAgreement $agreement, User $actor): InterdepartmentalAgreement
    {

        return DB::transaction(function () use ($agreement, $actor): InterdepartmentalAgreement {
            $agreement->setRawAttributes($agreement->newQuery()->lockForUpdate()->findOrFail($agreement->getKey())->getAttributes(), sync: true);

            $this->rules->ensureProposed($agreement);

            $old = $agreement->status;
            $agreement->update(['status' => AgreementStatus::Accepted, 'decided_by' => $actor->getKey(), 'decided_at' => now()]);

            activity()
                ->performedOn($agreement)
                ->causedBy($actor)
                ->event('accepted')
                ->withChanges(['old' => ['status' => $old->value], 'attributes' => ['status' => AgreementStatus::Accepted->value]])
                ->withProperties(['kind' => $agreement->kind->value, 'group_id' => $agreement->group_id])
                ->log('Aceptó el acuerdo entre departamentos');

            AgreementAccepted::dispatch($agreement);

            return $agreement;
        });
    }
}
