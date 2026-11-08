<?php

namespace App\Modules\Scheduling\Actions\Quotas;

use App\Modules\Academic\Models\Career;
use App\Modules\Academic\Models\Department;
use App\Modules\AcademicLoad\Actions\Agreements\CancelAgreement;
use App\Modules\AcademicLoad\Actions\Agreements\ProposeSharedGroup;
use App\Modules\AcademicLoad\Enums\AgreementKind;
use App\Modules\AcademicLoad\Enums\AgreementStatus;
use App\Modules\AcademicLoad\Models\InterdepartmentalAgreement;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Term;
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
 * - La dueña y las carreras del departamento que imparte se crean, cambian o quitan directo.
 * - Una carrera de otro departamento que ya tiene cupo (aceptado antes) cambia sus lugares directo y, si deja de
 *   venir, su cupo se borra y su acuerdo se cancela (supuesto por confirmar). Si no tiene cupo, se propone con
 *   ProposeSharedGroup: queda «Propuesto» y no cuenta hasta que su jefe lo acepta (AcceptSharedGroup). Un propuesto
 *   con otros lugares se cancela y se propone de nuevo; uno que deja de venir se cancela.
 * - HC-07: la suma de los cupos que quedan (sin los propuestos) no pasa la capacidad; se revisa antes de escribir y
 *   también se traduce el rechazo del trigger de la base. Las bajas y bajadas de lugares se escriben primero.
 *
 * En una transacción, con el grupo bloqueado. Deja bitácora `quotas_updated` en el grupo (cupos antes y después) y,
 * después del commit, avisa con GroupQuotasChanged; sin cambios no escribe ni avisa.
 */
final class SetGroupQuotas
{
    public function __construct(
        private readonly QuotaRules $rules,
        private readonly ProposeSharedGroup $propose,
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
            DB::transaction(function () use ($group, $seatsByCareer, $careers, $actor): void {
                $group = Group::query()->lockForUpdate()->findOrFail($group->getKey());

                $quotas = GroupQuota::query()->where('group_id', $group->id)->get()->keyBy('career_id');
                $agreements = InterdepartmentalAgreement::query()
                    ->where('kind', AgreementKind::SharedGroup->value)
                    ->where('group_id', $group->id)
                    ->live()
                    ->get()
                    ->keyBy('career_id');

                $plan = $this->plan($group, $seatsByCareer, $careers, $quotas, $agreements);
                $this->rules->ensureWithinCapacity(array_sum($plan['after']), $group->capacity);

                $before = $quotas->map(fn (GroupQuota $quota): int => $quota->max_seats)->all();
                $proposalsChanged = $this->write($group, $plan, $quotas, $agreements, $careers, $actor);

                if ($before == $plan['after'] && ! $proposalsChanged) {
                    return;
                }

                activity()
                    ->performedOn($group)
                    ->causedBy($actor)
                    ->event('quotas_updated')
                    ->withChanges(['old' => ['quotas' => $before], 'attributes' => ['quotas' => $plan['after']]])
                    ->log('Repartió los cupos por carrera del grupo');

                GroupQuotasChanged::dispatch($group);
            });
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
     * Qué cupos quedan (`after`: sin los propuestos), cuáles se proponen y cuáles acuerdos se cancelan.
     *
     * @param  array<string, int>  $seatsByCareer
     * @param  Collection<string, Career>  $careers
     * @param  Collection<string, GroupQuota>  $quotas
     * @param  Collection<string, InterdepartmentalAgreement>  $agreements
     * @return array{after: array<string, int>, propose: array<string, int>, cancel: list<string>}
     */
    private function plan(Group $group, array $seatsByCareer, $careers, $quotas, $agreements): array
    {
        $after = [];
        $propose = [];
        $cancel = [];

        foreach ($seatsByCareer as $careerId => $seats) {
            $direct = $careerId === $group->owner_career_id
                || $quotas->has($careerId)
                || $careers[$careerId]->department_id === $group->department_id;

            if ($direct) {
                $after[$careerId] = $seats;

                continue;
            }

            $live = $agreements->get($careerId);

            if ($live?->status === AgreementStatus::Proposed && $live->max_seats !== $seats) {
                $cancel[] = $careerId;
                $propose[$careerId] = $seats;
            } elseif ($live === null) {
                $propose[$careerId] = $seats;
            }
        }

        foreach ($agreements as $careerId => $agreement) {
            if (! array_key_exists($careerId, $seatsByCareer)) {
                $cancel[] = $careerId;
            }
        }

        foreach ($quotas as $careerId => $quota) {
            if (! array_key_exists($careerId, $seatsByCareer) && $agreements->has($careerId) && ! in_array($careerId, $cancel, true)) {
                $cancel[] = $careerId;
            }
        }

        return ['after' => $after, 'propose' => $propose, 'cancel' => $cancel];
    }

    /**
     * Escribe el plan: cancela, borra, baja, sube, crea y propone, en ese orden para que ninguna suma intermedia
     * pase la capacidad. Dice si cambió alguna propuesta.
     *
     * @param  array{after: array<string, int>, propose: array<string, int>, cancel: list<string>}  $plan
     * @param  Collection<string, GroupQuota>  $quotas
     * @param  Collection<string, InterdepartmentalAgreement>  $agreements
     * @param  Collection<string, Career>  $careers
     */
    private function write(Group $group, array $plan, $quotas, $agreements, $careers, User $actor): bool
    {
        foreach ($plan['cancel'] as $careerId) {
            $this->cancel->handle($agreements[$careerId], $actor);
        }

        foreach ($quotas as $careerId => $quota) {
            if (! array_key_exists($careerId, $plan['after'])) {
                $quota->delete();
            }
        }

        $changes = collect($plan['after'])->filter(fn (int $seats, string $careerId): bool => $quotas->has($careerId) && $quotas[$careerId]->max_seats !== $seats)
            ->sortBy(fn (int $seats, string $careerId): int => $seats - $quotas[$careerId]->max_seats);

        foreach ($changes as $careerId => $seats) {
            $quotas[$careerId]->update(['max_seats' => $seats]);
        }

        foreach ($plan['after'] as $careerId => $seats) {
            if (! $quotas->has($careerId)) {
                GroupQuota::query()->create(['group_id' => $group->id, 'career_id' => $careerId, 'max_seats' => $seats, 'is_owner' => false]);
            }
        }

        if ($plan['propose'] !== []) {
            $term = Term::query()->findOrFail($group->term_id);
            $campus = Campus::query()->findOrFail($group->campus_id);
            $from = Department::withTrashed()->findOrFail($group->department_id);

            foreach ($plan['propose'] as $careerId => $seats) {
                $this->propose->handle($term, $campus, $from, $group->id, $careers[$careerId], $seats, $actor);
            }
        }

        return $plan['propose'] !== [] || $plan['cancel'] !== [];
    }
}
