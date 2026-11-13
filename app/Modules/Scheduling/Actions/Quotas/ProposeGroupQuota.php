<?php

namespace App\Modules\Scheduling\Actions\Quotas;

use App\Modules\Academic\Models\Career;
use App\Modules\Academic\Models\Department;
use App\Modules\AcademicLoad\Actions\Agreements\ProposeSharedGroup;
use App\Modules\AcademicLoad\Models\InterdepartmentalAgreement;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Modality;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\User;
use App\Modules\Scheduling\Models\Group;
use App\Modules\Scheduling\Models\GroupQuota;
use App\Modules\Scheduling\Rules\QuotaRules;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * El departamento que imparte un grupo propone el cupo de una carrera de otro departamento (reglas-de-negocio.md,
 * "Cupos por carrera"; HC-07). Envuelve ProposeSharedGroup con el periodo, campus y departamento del grupo: queda
 * «Propuesto» hasta que su jefe lo acepta (AcceptSharedGroup), y desde ya aparta sus lugares (confirmado el 8 de
 * noviembre de 2026). Con el grupo bloqueado exige que se pueda editar (QuotaRules::ensureEditable), que la carrera
 * esté activa y ofrecida en el campus (ensureOffered) y que los lugares quepan junto a los cupos y las demás
 * propuestas vivas (QuotaRules::ensureProposalFits, sobre `max_seats`), antes de que ProposeSharedGroup escriba nada.
 * AcademicLoad no ve grupos, así que esa suma se hace aquí: la base no
 * la cuida (las propuestas no están en `group_quotas`), y el candado del grupo hace que dos propuestas a la vez se
 * revisen una tras otra. Quien puede lo decide GroupPolicy::manageQuotas antes de llamarla.
 */
final class ProposeGroupQuota
{
    public function __construct(
        private readonly QuotaRules $rules,
        private readonly ProposeSharedGroup $propose,
    ) {}

    /**
     * @throws ValidationException
     */
    public function handle(Group $group, Career $career, int $seats, User $actor): InterdepartmentalAgreement
    {
        return $this->rules->guard(fn (): InterdepartmentalAgreement => DB::transaction(function () use ($group, $career, $seats, $actor): InterdepartmentalAgreement {
            $this->rules->lockLoad($group);
            $group = Group::query()->lockForUpdate()->findOrFail($group->getKey());
            $this->rules->ensureEditable($group);
            $this->rules->ensureOffered($career, $group);

            $taken = (int) GroupQuota::query()->where('group_id', $group->id)->sum('max_seats') + $this->rules->heldSeats($group);
            $this->rules->ensureProposalFits($taken, $seats, $group->capacity);

            return $this->propose->handle(
                Term::query()->findOrFail($group->term_id),
                Modality::withTrashed()->findOrFail($group->modality_id),
                $group->campus_id === null ? null : Campus::withTrashed()->findOrFail($group->campus_id),
                Department::withTrashed()->findOrFail($group->department_id),
                $group->id,
                $career,
                $seats,
                $actor,
            );
        }));
    }
}
