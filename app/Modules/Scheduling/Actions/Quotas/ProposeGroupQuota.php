<?php

namespace App\Modules\Scheduling\Actions\Quotas;

use App\Modules\Academic\Models\Career;
use App\Modules\Academic\Models\Department;
use App\Modules\AcademicLoad\Actions\Agreements\ProposeSharedGroup;
use App\Modules\AcademicLoad\Models\InterdepartmentalAgreement;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\User;
use App\Modules\Scheduling\Models\Group;
use App\Modules\Scheduling\Rules\QuotaRules;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * El departamento que imparte un grupo propone el cupo de una carrera de otro departamento (reglas-de-negocio.md,
 * "Cupos por carrera"; HC-07). Envuelve ProposeSharedGroup con el periodo, campus y departamento del grupo: queda
 * «Propuesto» y no cuenta hasta que su jefe lo acepta (AcceptSharedGroup). Con el grupo bloqueado exige que se pueda
 * editar (QuotaRules::ensureEditable) y que la carrera esté activa y ofrecida en el campus (ensureOffered). Quien
 * puede lo decide GroupPolicy::manageQuotas antes de llamarla.
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
        return DB::transaction(function () use ($group, $career, $seats, $actor): InterdepartmentalAgreement {
            $group = Group::query()->lockForUpdate()->findOrFail($group->getKey());
            $this->rules->ensureEditable($group);
            $this->rules->ensureOffered($career, $group);

            return $this->propose->handle(
                Term::query()->findOrFail($group->term_id),
                Campus::query()->findOrFail($group->campus_id),
                Department::withTrashed()->findOrFail($group->department_id),
                $group->id,
                $career,
                $seats,
                $actor,
            );
        });
    }
}
