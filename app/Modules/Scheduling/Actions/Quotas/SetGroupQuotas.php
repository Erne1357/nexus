<?php

namespace App\Modules\Scheduling\Actions\Quotas;

use App\Modules\Academic\Models\Career;
use App\Modules\Academic\Models\Department;
use App\Modules\AcademicLoad\Actions\Agreements\CancelAgreement;
use App\Modules\AcademicLoad\Enums\AgreementKind;
use App\Modules\AcademicLoad\Models\InterdepartmentalAgreement;
use App\Modules\Core\Models\User;
use App\Modules\Scheduling\Events\GroupQuotasChanged;
use App\Modules\Scheduling\Models\Group;
use App\Modules\Scheduling\Models\GroupQuota;
use App\Modules\Scheduling\Rules\QuotaRules;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * El departamento que imparte un grupo reparte sus lugares entre carreras (reglas-de-negocio.md, "Cupos por
 * carrera"; HC-07). Recibe `[careerId => lugares]` con todas las carreras que deben quedar: la dueña siempre (no se
 * quita) y cada cupo de 1 a 999 lugares. Quien puede lo decide GroupPolicy::manageQuotas antes de llamarla.
 *
 * Solo maneja cupos que ya existen y carreras del mismo departamento que imparte (que además deben estar activas y
 * ofrecerse en el campus del grupo): se crean, cambian o quitan directo. Una carrera de otro departamento sin cupo no
 * se agrega aquí, sino que se propone con ProposeGroupQuota (error `quotas.<id>`: «Propón primero el cupo a
 * <depto>.»). Si de otro departamento ya tiene cupo, cambia sus lugares directo y, si deja de venir, su cupo se borra
 * y su acuerdo aceptado se cancela (supuesto por confirmar). Las propuestas no se tocan: se retiran con
 * WithdrawGroupQuotaProposal.
 *
 * HC-07: la suma de los cupos que quedan no pasa la capacidad; se revisa antes de escribir y también se traduce el
 * rechazo del trigger de la base. Las bajas y bajadas de lugares se escriben primero. No edita un grupo cancelado ni
 * con la carga fuera de proposed, staffing o adjusting (QuotaRules::ensureEditable).
 *
 * En una transacción, con el grupo bloqueado. Deja bitácora `quotas_updated` en el grupo (cupos antes y después) y,
 * después del commit, avisa con GroupQuotasChanged; sin cambios no escribe ni avisa.
 */
final class SetGroupQuotas
{
    public function __construct(
        private readonly QuotaRules $rules,
        private readonly CancelAgreement $cancel,
    ) {}

    /**
     * @param  array<string, int>  $seatsByCareer
     *
     * @throws ValidationException
     */
    public function handle(Group $group, array $seatsByCareer, User $actor): void
    {
        $careers = $this->careers($group, $seatsByCareer);

        try {
            $this->rules->guard(fn () => DB::transaction(function () use ($group, $seatsByCareer, $careers, $actor): void {
                $this->rules->lockLoad($group);
                $group = Group::query()->lockForUpdate()->findOrFail($group->getKey());
                $this->rules->ensureEditable($group);

                $quotas = GroupQuota::query()->where('group_id', $group->id)->get()->keyBy('career_id');
                $accepted = InterdepartmentalAgreement::query()
                    ->where('kind', AgreementKind::SharedGroup->value)
                    ->where('group_id', $group->id)
                    ->live()
                    ->whereIn('career_id', $quotas->keys()->all())
                    ->get()
                    ->keyBy('career_id');

                $this->ensureReachable($group, $seatsByCareer, $careers, $quotas);
                $this->rules->ensureWithinCapacity(array_sum($seatsByCareer), $group->capacity);

                $before = $quotas->map(fn (GroupQuota $quota): int => $quota->max_seats)->all();

                foreach ($accepted as $careerId => $agreement) {
                    if (! array_key_exists($careerId, $seatsByCareer)) {
                        $this->cancel->handle($agreement, $actor);
                    }
                }

                $this->write($group, $seatsByCareer, $quotas);

                if ($before == $seatsByCareer) {
                    return;
                }

                activity()
                    ->performedOn($group)
                    ->causedBy($actor)
                    ->event('quotas_updated')
                    ->withChanges(['old' => ['quotas' => $before], 'attributes' => ['quotas' => $seatsByCareer]])
                    ->log('Repartió los cupos por carrera del grupo');

                GroupQuotasChanged::dispatch($group);
            }));
        } catch (QueryException $e) {
            throw $this->rules->fromDatabase($e) ?? $e;
        }
    }

    /**
     * Las carreras pedidas, con la forma de los lugares revisada.
     *
     * @param  array<string, int>  $seatsByCareer
     * @return Collection<string, Career>
     *
     * @throws ValidationException
     */
    private function careers(Group $group, array $seatsByCareer): Collection
    {
        $errors = [];

        if (! array_key_exists($group->owner_career_id, $seatsByCareer)) {
            $errors['quotas'] = 'La carrera dueña conserva su cupo: no se quita.';
        }

        foreach ($seatsByCareer as $careerId => $seats) {
            if (! is_int($seats) || $seats < 1 || $seats > QuotaRules::MAX_SEATS) {
                $errors["quotas.{$careerId}"] = 'Los lugares van de 1 a '.QuotaRules::MAX_SEATS.'.';
            }
        }

        $careers = Career::query()->withTrashed()->whereIn('id', array_keys($seatsByCareer))->get()->keyBy('id');

        foreach (array_diff(array_keys($seatsByCareer), $careers->keys()->all()) as $careerId) {
            $errors["quotas.{$careerId}"] = 'La carrera no existe.';
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $careers;
    }

    /**
     * Una carrera sin cupo solo entra si es del departamento que imparte y se ofrece en el campus; la de otro
     * departamento se propone primero.
     *
     * @param  array<string, int>  $seatsByCareer
     * @param  Collection<string, Career>  $careers
     * @param  Collection<string, GroupQuota>  $quotas
     *
     * @throws ValidationException
     */
    private function ensureReachable(Group $group, array $seatsByCareer, Collection $careers, Collection $quotas): void
    {
        foreach (array_keys($seatsByCareer) as $careerId) {
            if ($quotas->has($careerId)) {
                continue;
            }

            $career = $careers[$careerId];

            if ($career->department_id !== $group->department_id) {
                $department = Department::withTrashed()->find($career->department_id)?->name;

                throw ValidationException::withMessages(["quotas.{$careerId}" => "Propón primero el cupo a {$department}."]);
            }

            $this->rules->ensureOffered($career, $group);
        }
    }

    /**
     * Borra, baja, sube y crea, en ese orden para que ninguna suma intermedia pase la capacidad.
     *
     * @param  array<string, int>  $after
     * @param  Collection<string, GroupQuota>  $quotas
     */
    private function write(Group $group, array $after, Collection $quotas): void
    {
        foreach ($quotas as $careerId => $quota) {
            if (! array_key_exists($careerId, $after)) {
                $quota->delete();
            }
        }

        $changes = collect($after)
            ->filter(fn (int $seats, string $careerId): bool => $quotas->has($careerId) && $quotas[$careerId]->max_seats !== $seats)
            ->sortBy(fn (int $seats, string $careerId): int => $seats - $quotas[$careerId]->max_seats);

        foreach ($changes as $careerId => $seats) {
            $quotas[$careerId]->update(['max_seats' => $seats]);
        }

        foreach ($after as $careerId => $seats) {
            if (! $quotas->has($careerId)) {
                GroupQuota::query()->create(['group_id' => $group->id, 'career_id' => $careerId, 'max_seats' => $seats, 'is_owner' => false]);
            }
        }
    }
}
