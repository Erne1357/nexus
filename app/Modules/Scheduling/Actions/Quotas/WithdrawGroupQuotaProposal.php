<?php

namespace App\Modules\Scheduling\Actions\Quotas;

use App\Modules\AcademicLoad\Actions\Agreements\CancelAgreement;
use App\Modules\AcademicLoad\Enums\AgreementKind;
use App\Modules\AcademicLoad\Enums\AgreementStatus;
use App\Modules\AcademicLoad\Models\InterdepartmentalAgreement;
use App\Modules\Core\Models\User;
use App\Modules\Scheduling\Models\Group;
use App\Modules\Scheduling\Rules\QuotaRules;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * El departamento que imparte retira el cupo que propuso a otra carrera (envuelve CancelAgreement). Con el grupo
 * bloqueado exige que el acuerdo sea de un grupo compartido y que se pueda editar (QuotaRules::ensureEditable). Quien
 * puede lo decide GroupPolicy::manageQuotas antes de llamarla.
 */
final class WithdrawGroupQuotaProposal
{
    public function __construct(
        private readonly QuotaRules $rules,
        private readonly CancelAgreement $cancel,
    ) {}

    /**
     * @throws ValidationException
     */
    public function handle(InterdepartmentalAgreement $agreement, User $actor): InterdepartmentalAgreement
    {
        if ($agreement->kind !== AgreementKind::SharedGroup) {
            throw ValidationException::withMessages(['kind' => 'Este acuerdo no es de un cupo en un grupo.']);
        }

        return $this->rules->guard(fn (): InterdepartmentalAgreement => DB::transaction(function () use ($agreement, $actor): InterdepartmentalAgreement {
            $group = Group::query()->findOrFail($agreement->group_id);
            $this->rules->lockLoad($group);
            $group = Group::query()->lockForUpdate()->findOrFail($group->id);
            $this->rules->ensureEditable($group);

            $current = $agreement->newQuery()->lockForUpdate()->findOrFail($agreement->getKey());

            if ($current->status !== AgreementStatus::Proposed) {
                throw ValidationException::withMessages(['status' => 'Este acuerdo ya se decidió: ya no se puede retirar la propuesta.']);
            }

            return $this->cancel->handle($agreement, $actor);
        }));
    }
}
