<?php

namespace App\Modules\Scheduling\Actions\Quotas;

use App\Modules\AcademicLoad\Actions\Agreements\AcceptAgreement;
use App\Modules\AcademicLoad\Enums\AgreementKind;
use App\Modules\AcademicLoad\Models\InterdepartmentalAgreement;
use App\Modules\Core\Models\User;
use App\Modules\Scheduling\Events\GroupQuotasChanged;
use App\Modules\Scheduling\Models\Group;
use App\Modules\Scheduling\Models\GroupQuota;
use App\Modules\Scheduling\Rules\QuotaRules;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * El jefe de la carrera de otro departamento acepta el cupo que le propusieron en un grupo (reglas-de-negocio.md,
 * "Cupos por carrera" y "Acuerdos entre departamentos"; HC-07). AcademicLoad no ve grupos: esta Action orquesta
 * AcceptAgreement y el cupo en una misma transacción, con el grupo bloqueado. Acepta el acuerdo (que exige que siga
 * propuesto), revisa HC-07 con los lugares del acuerdo (sobre `max_seats`, también cuando lo dice la base) y crea el
 * cupo de la carrera con los lugares acordados. La propuesta ya apartaba sus lugares (ProposeGroupQuota y
 * SetGroupQuotas los cuentan; confirmado el 8 de noviembre de 2026), así que al aceptar siempre debería caber: la
 * revisión queda como red de seguridad, para datos que no pasaron por esas Actions, y cuenta los cupos, este acuerdo y
 * lo que apartan las demás propuestas vivas del grupo (ya aceptado, este no está entre ellas). Si algo falla, el acuerdo
 * queda propuesto. Deja bitácora `quotas_updated` en el grupo y avisa con GroupQuotasChanged después
 * del commit. Un deadlock se reintenta solo, hasta tres veces; si sigue, queda el mensaje de siempre («Otra persona está
 * cambiando esta carga; intenta de nuevo.»). Quién puede lo decide InterdepartmentalAgreementPolicy antes de llamarla.
 */
final class AcceptSharedGroup
{
    public function __construct(
        private readonly QuotaRules $rules,
        private readonly AcceptAgreement $accept,
    ) {}

    /**
     * @throws ValidationException
     */
    public function handle(InterdepartmentalAgreement $agreement, User $actor): GroupQuota
    {
        if ($agreement->kind !== AgreementKind::SharedGroup) {
            throw ValidationException::withMessages(['kind' => 'Este acuerdo no es de un cupo en un grupo.']);
        }

        try {
            return $this->rules->guard(fn (): GroupQuota => DB::transaction(function () use ($agreement, $actor): GroupQuota {
                $group = Group::query()->findOrFail($agreement->group_id);
                $this->rules->lockLoad($group);
                $group = Group::query()->lockForUpdate()->findOrFail($group->id);

                $this->rules->ensureEditable($group);

                if (GroupQuota::query()->where('group_id', $group->id)->where('career_id', $agreement->career_id)->exists()) {
                    throw ValidationException::withMessages(['career_id' => 'La carrera ya tiene cupo en este grupo.']);
                }

                $this->accept->handle($agreement, $actor);

                $quotas = GroupQuota::query()->where('group_id', $group->id)->pluck('max_seats', 'career_id')->all();
                $this->rules->ensureAgreementFits(array_sum($quotas), $agreement->max_seats, $group->capacity, $this->rules->heldSeats($group));

                $quota = GroupQuota::query()->create([
                    'group_id' => $group->id,
                    'career_id' => $agreement->career_id,
                    'max_seats' => $agreement->max_seats,
                    'is_owner' => false,
                ]);

                activity()
                    ->performedOn($group)
                    ->causedBy($actor)
                    ->event('quotas_updated')
                    ->withChanges(['old' => ['quotas' => $quotas], 'attributes' => ['quotas' => $quotas + [$quota->career_id => $quota->max_seats]]])
                    ->withProperties(['agreement_id' => $agreement->id])
                    ->log('Aceptó el cupo de una carrera de otro departamento en el grupo');

                GroupQuotasChanged::dispatch($group);

                return $quota;
            }, 3));
        } catch (QueryException $e) {
            throw $this->rules->fromDatabase($e, 'max_seats') ?? $this->rules->fromUnique($e) ?? $e;
        }
    }
}
