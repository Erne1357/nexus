<?php

namespace App\Modules\AcademicLoad\Actions\Agreements;

use App\Modules\AcademicLoad\Enums\AgreementStatus;
use App\Modules\AcademicLoad\Events\AgreementRejected;
use App\Modules\AcademicLoad\Models\InterdepartmentalAgreement;
use App\Modules\AcademicLoad\Rules\AgreementRules;
use App\Modules\Core\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * El departamento al que va dirigido un acuerdo propuesto lo rechaza con motivo (reglas-de-negocio.md, "Acuerdos entre departamentos"). Recorta el motivo fuera de la transacción: obligatorio y de hasta 1000 caracteres, sobre `comment`.
 *
 * Relee el acuerdo con bloqueo (el estado que vale es el de la base) y exige que siga propuesto (AgreementRules::ensureProposed), sobre `status`. Lo deja rechazado
 * con quién y cuándo, deja bitácora `rejected` y, después del commit, avisa con AgreementRejected. Quién puede lo decide
 * InterdepartmentalAgreementPolicy antes de llamarla; quien actúa se recibe como parámetro.
 */
final class RejectAgreement
{
    public function __construct(private readonly AgreementRules $rules) {}

    /**
     * @throws ValidationException
     */
    public function handle(InterdepartmentalAgreement $agreement, string $comment, User $actor): InterdepartmentalAgreement
    {
        $comment = $this->rules->normalizeComment($comment);

        return DB::transaction(function () use ($agreement, $comment, $actor): InterdepartmentalAgreement {
            $agreement->setRawAttributes($agreement->newQuery()->lockForUpdate()->findOrFail($agreement->getKey())->getAttributes(), sync: true);

            $this->rules->ensureProposed($agreement);

            $old = $agreement->status;
            $agreement->update(['status' => AgreementStatus::Rejected, 'comment' => $comment, 'decided_by' => $actor->getKey(), 'decided_at' => now()]);

            activity()
                ->performedOn($agreement)
                ->causedBy($actor)
                ->event('rejected')
                ->withChanges(['old' => ['status' => $old->value, 'comment' => null], 'attributes' => ['status' => AgreementStatus::Rejected->value, 'comment' => $comment]])
                ->withProperties(['kind' => $agreement->kind->value, 'group_id' => $agreement->group_id])
                ->log('Rechazó el acuerdo entre departamentos');

            AgreementRejected::dispatch($agreement);

            return $agreement;
        });
    }
}
