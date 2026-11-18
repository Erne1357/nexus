<?php

namespace App\Modules\Scheduling\Actions\Parallels;

use App\Modules\Academic\Models\Career;
use App\Modules\Academic\Models\Subject;
use App\Modules\AcademicLoad\Actions\Agreements\CancelAgreement;
use App\Modules\AcademicLoad\Enums\AgreementKind;
use App\Modules\AcademicLoad\Enums\AgreementStatus;
use App\Modules\AcademicLoad\Enums\ProposalReviewStatus;
use App\Modules\AcademicLoad\Enums\ProposalStatus;
use App\Modules\AcademicLoad\Models\InterdepartmentalAgreement;
use App\Modules\AcademicLoad\Models\ProposalItem;
use App\Modules\AcademicLoad\Models\ProposalReview;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Modality;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\User;
use App\Modules\Demand\Rules\SuggestedGroups;
use App\Modules\Scheduling\Actions\CancelGroup;
use App\Modules\Scheduling\Enums\GroupStatus;
use App\Modules\Scheduling\Events\GroupQuotasChanged;
use App\Modules\Scheduling\Events\GroupsCreated;
use App\Modules\Scheduling\Events\ParallelGroupsArranged;
use App\Modules\Scheduling\Models\Group;
use App\Modules\Scheduling\Models\GroupQuota;
use App\Modules\Scheduling\Queries\ParallelsQuery;
use App\Modules\Scheduling\Rules\GroupCapacity;
use App\Modules\Scheduling\Rules\GroupCode;
use App\Modules\Scheduling\Rules\LoadEditability;
use App\Modules\Scheduling\Rules\ParallelPlacement;
use App\Modules\Scheduling\Rules\QuotaRules;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Enums\ActivityEvent;

/**
 * Arma los paralelos de una materia en un campus (tarea 8.8; reglas-de-negocio.md, «Paralelos»): el jefe del departamento
 * que imparte junta lo que piden las carreras en menos grupos, sin junta ni acuerdo (confirmado el 18 de noviembre de
 * 2026). Recibe el arreglo deseado, una lista de grupos, cada uno `carrera => lugares`, y reorganiza los grupos sueltos que
 * ya existen de esa materia y campus en la carga del departamento en la modalidad; los de paquete (DM-09) no se tocan.
 * Quién puede lo decide ParallelsPolicy::arrange antes de llamarla.
 *
 * - Qué grupo se queda en cada grupo del arreglo lo decide ParallelPlacement, igual que la sugerencia de ParallelsQuery: el
 *   de más avance (con docente; luego con más horario), que conserva su clave, su horario, su docente y su carrera dueña.
 *   Un grupo con docente nunca se cancela: si el arreglo no le deja lugar, se rechaza con el motivo.
 * - Los que sobran se cancelan con CancelGroup: pierden sus sesiones (el resultado lo dice) y sus acuerdos vivos.
 * - Los cupos de cada grupo que queda son los del arreglo, en exclusiva, escritos directo y sin acuerdo, también los de
 *   carreras de otros departamentos (confirmado el 18 de noviembre de 2026); lo que no reparte el arreglo queda libre. Las
 *   propuestas vivas de grupo compartido del grupo se cancelan, y un acuerdo aceptado se cancela si su carrera ya no tiene
 *   cupo; el aceptado de una carrera que sigue se queda (sus lugares cambian directo, como en SetGroupQuotas).
 * - HC-07: los cupos de cada grupo no pasan su capacidad (la del grupo que se queda o la del que nace).
 * - Si el arreglo pide más grupos de los que existen, crea los que faltan, sueltos y sin horario, como
 *   CreateGroupsFromProposal (capacidad de GroupCapacity, clave provisional), de la fila de su carrera dueña: la de más
 *   lugares en ese grupo, y en un empate la primera del arreglo (supuesto por confirmar).
 *
 * Guardas: la carga del departamento editable (LoadEditability), el campus activo y, por cada carrera del arreglo, su parte
 * del departamento aceptada con la materia pedida en ese campus: la pendiente se ve en la pestaña pero no se arma hasta
 * aceptarla (supuesto por confirmar), y cada una activa y ofrecida en el campus (HC-13). De 1 a MAX_GROUPS grupos, cada uno
 * con al menos una carrera y cupos de 1 a 999.
 *
 * En una transacción: toma la carga (FOR SHARE), el candado de aviso de claves de la materia (el de CreateGroupsFromProposal,
 * así una parte que se acepta a la vez espera) y bloquea los grupos sueltos. Deja bitácora por grupo tocado (cupos
 * `parallels_arranged`, alta `created`, baja `cancelled`) y, después del commit y solo si cambió algo, avisa con
 * GroupQuotasChanged por grupo, GroupsCreated, GroupCancelled y GroupScheduleChanged (CancelGroup) y ParallelGroupsArranged,
 * con lo que cambió para cada carrera.
 */
final class ArmParallelGroups
{
    public const int MAX_GROUPS = 60;

    public function __construct(
        private readonly LoadEditability $editability,
        private readonly QuotaRules $quotas,
        private readonly ParallelsQuery $parallels,
        private readonly CancelGroup $cancelGroup,
        private readonly CancelAgreement $cancelAgreement,
    ) {}

    /**
     * @param  list<array<string, int>>  $arrangement
     *
     * @throws ValidationException
     */
    public function handle(Term $term, Modality $modality, Subject $subject, Campus $campus, array $arrangement, User $actor): ParallelArrangement
    {
        $this->ensureShape($arrangement);

        try {
            return $this->editability->guard(fn (): ParallelArrangement => DB::transaction(
                fn (): ParallelArrangement => $this->arm($term, $modality, $subject, $campus, $arrangement, $actor),
            ), 'groups');
        } catch (QueryException $e) {
            throw $this->quotas->fromDatabase($e, 'groups') ?? $e;
        }
    }

    /**
     * @param  list<array<string, int>>  $arrangement
     */
    private function arm(Term $term, Modality $modality, Subject $subject, Campus $campus, array $arrangement, User $actor): ParallelArrangement
    {
        $this->editability->ensureLoad($term->getKey(), $modality->getKey(), $subject->department_id, 'paralelos', 'groups');

        if (! Campus::query()->whereKey($campus->getKey())->where('is_active', true)->exists()) {
            throw ValidationException::withMessages(['groups' => "{$campus->name} está inactivo: no se arman paralelos ahí."]);
        }

        DB::select('select pg_advisory_xact_lock(hashtext(?))', ["groups:{$term->getKey()}:{$subject->getKey()}"]);

        $items = $this->acceptedItems($term, $modality, $subject, $campus);
        $careers = $this->ensureCareers($arrangement, $items, $subject, $campus);
        $groups = $this->parallels->looseGroups($term->getKey(), $modality->getKey(), $subject->department_id, $subject->getKey(), $campus->getKey())->lockForUpdate()->get()->keyBy('id');
        $placement = ParallelPlacement::place($arrangement, $groups->map(fn (Group $group): array => ParallelsQuery::existing($group)->forPlacement())->values()->all());

        $this->ensureNoTeacherCancelled($placement, $groups, $careers);
        $newCapacity = GroupCapacity::for($subject, $campus->getKey(), SuggestedGroups::fromSettings());
        $this->ensureWithinCapacity($arrangement, $placement, $groups, $newCapacity);

        $before = $groups->map(fn (Group $group): array => $group->quotas->pluck('max_seats', 'career_id')->all())->all();
        $cancelled = array_map(fn (string $id): array => $this->cancelGroup->handle($groups[$id], $actor), $placement->cancelled);
        $kept = [];
        $created = [];

        foreach ($arrangement as $index => $seats) {
            if (isset($placement->kept[$index])) {
                $group = $groups[$placement->kept[$index]];
                $this->rewriteQuotas($group, $seats, $subject, $actor);
                $kept[] = ['group' => $group, 'before' => $before[$group->getKey()], 'after' => $seats];
            } else {
                $created[] = $this->create($term, $modality, $subject, $campus, $items[(string) array_search(max($seats), $seats, true)], $seats, $newCapacity, $actor);
            }
        }

        $result = new ParallelArrangement($kept, $created, $cancelled);

        if ($created !== []) {
            GroupsCreated::dispatch($term->getKey(), $modality->getKey(), array_map(fn (Group $group): string => $group->getKey(), $created));
        }

        if ($result->changed()) {
            ParallelGroupsArranged::dispatch($term->getKey(), $modality->getKey(), $campus->getKey(), $subject->getKey(), $subject->department_id, $this->careerChanges($result, $before));
        }

        return $result;
    }

    /**
     * @param  list<array<string, int>>  $arrangement
     *
     * @throws ValidationException
     */
    private function ensureShape(array $arrangement): void
    {
        if ($arrangement === [] || ! array_is_list($arrangement) || count($arrangement) > self::MAX_GROUPS) {
            throw ValidationException::withMessages(['groups' => 'El arreglo lleva de 1 a '.self::MAX_GROUPS.' grupos.']);
        }

        foreach ($arrangement as $index => $seats) {
            if (! is_array($seats) || $seats === []) {
                throw ValidationException::withMessages(["groups.{$index}" => 'Cada grupo lleva al menos una carrera.']);
            }

            foreach ($seats as $careerId => $count) {
                if (! is_string($careerId) || ! is_int($count) || $count < 1 || $count > QuotaRules::MAX_SEATS) {
                    throw ValidationException::withMessages(["groups.{$index}" => 'Los lugares de cada carrera van de 1 a '.QuotaRules::MAX_SEATS.'.']);
                }
            }
        }
    }

    /**
     * Las filas de la materia en el campus con la parte del departamento aceptada, por id de carrera.
     *
     * @return Collection<string, ProposalItem>
     */
    private function acceptedItems(Term $term, Modality $modality, Subject $subject, Campus $campus): Collection
    {
        return ProposalItem::query()
            ->requested()
            ->where('subject_id', $subject->getKey())
            ->where('campus_id', $campus->getKey())
            ->whereHas('proposal', fn ($proposal) => $proposal->where('term_id', $term->getKey())->where('modality_id', $modality->getKey())->where('status', '<>', ProposalStatus::Draft->value))
            ->whereIn('career_proposal_id', ProposalReview::query()->select('career_proposal_id')->where('department_id', $subject->department_id)->where('status', ProposalReviewStatus::Accepted->value))
            ->with('proposal')
            ->get()
            ->keyBy(fn (ProposalItem $item): string => $item->proposal->career_id);
    }

    /**
     * Cada carrera del arreglo existe, tiene su parte aceptada con la materia en el campus y se ofrece activa en él.
     *
     * @param  list<array<string, int>>  $arrangement
     * @param  Collection<string, ProposalItem>  $items
     * @return Collection<string, Career>
     *
     * @throws ValidationException
     */
    private function ensureCareers(array $arrangement, Collection $items, Subject $subject, Campus $campus): Collection
    {
        $ids = array_values(array_unique(array_merge(...array_map(array_keys(...), $arrangement))));
        $careers = Career::query()->withTrashed()->whereIn('id', array_filter($ids, fn (string $id): bool => Str::isUuid($id)))->get()->keyBy('id');

        foreach ($ids as $id) {
            $career = $careers->get($id) ?? throw ValidationException::withMessages(['groups' => 'Una carrera del arreglo no existe.']);

            if (! $items->has($id)) {
                throw ValidationException::withMessages(['groups' => "{$career->code} no tiene aceptada su parte con {$subject->name} en {$campus->code}: acéptala antes de armar."]);
            }

            if (! $career->is_active || $career->trashed() || ! $career->offerings()->where('campus_id', $campus->getKey())->where('is_active', true)->exists()) {
                throw ValidationException::withMessages(['groups' => "{$career->code} no está activa u ofrecida en {$campus->code} (HC-13)."]);
            }
        }

        return $careers;
    }

    /**
     * @param  Collection<string, Group>  $groups
     * @param  Collection<string, Career>  $careers
     *
     * @throws ValidationException
     */
    private function ensureNoTeacherCancelled(ParallelPlacement $placement, Collection $groups, Collection $careers): void
    {
        if ($placement->blocked === []) {
            return;
        }

        $group = $groups[$placement->blocked[0]];
        $owner = $careers->get($group->owner_career_id)?->code ?? Career::withTrashed()->whereKey($group->owner_career_id)->value('code');

        throw ValidationException::withMessages(['groups' => "El grupo {$group->code} de {$owner} tiene docente y el arreglo no le deja lugar: deja un grupo con lugares de {$owner} o quítale primero el docente."]);
    }

    /**
     * HC-07 antes de escribir: los cupos de cada grupo del arreglo caben en la capacidad del grupo que se queda o del nuevo.
     *
     * @param  list<array<string, int>>  $arrangement
     * @param  Collection<string, Group>  $groups
     *
     * @throws ValidationException
     */
    private function ensureWithinCapacity(array $arrangement, ParallelPlacement $placement, Collection $groups, int $newCapacity): void
    {
        foreach ($arrangement as $index => $seats) {
            $group = isset($placement->kept[$index]) ? $groups[$placement->kept[$index]] : null;
            $capacity = $group?->capacity ?? $newCapacity;
            $total = array_sum($seats);

            if ($total > $capacity) {
                $name = $group === null ? 'del grupo nuevo '.($index + 1) : "del grupo {$group->code}";

                throw ValidationException::withMessages(["groups.{$index}" => "HC-07: los cupos {$name} suman {$total} y es de {$capacity} lugares."]);
            }
        }
    }

    /**
     * Deja los cupos del grupo en los del arreglo: cancela sus propuestas vivas de grupo compartido y los acuerdos aceptados
     * de carreras que salen, y borra, baja, sube y crea, en ese orden, para que ninguna suma intermedia pase la capacidad.
     *
     * @param  array<string, int>  $after
     */
    private function rewriteQuotas(Group $group, array $after, Subject $subject, User $actor): void
    {
        InterdepartmentalAgreement::query()
            ->where('kind', AgreementKind::SharedGroup->value)
            ->where('group_id', $group->getKey())
            ->live()
            ->orderBy('id')
            ->get()
            ->reject(fn (InterdepartmentalAgreement $agreement): bool => $agreement->status === AgreementStatus::Accepted && isset($after[$agreement->career_id]))
            ->each(fn (InterdepartmentalAgreement $agreement) => $this->cancelAgreement->handle($agreement, $actor));

        $quotas = $group->quotas->keyBy('career_id');
        $before = $quotas->map(fn (GroupQuota $quota): int => $quota->max_seats)->all();

        if ($before == $after) {
            return;
        }

        foreach ($quotas as $careerId => $quota) {
            if (! isset($after[$careerId])) {
                $quota->delete();
            }
        }

        collect($after)
            ->filter(fn (int $seats, string $careerId): bool => $quotas->has($careerId) && $quotas[$careerId]->max_seats !== $seats)
            ->sortBy(fn (int $seats, string $careerId): int => $seats - $quotas[$careerId]->max_seats)
            ->each(fn (int $seats, string $careerId) => $quotas[$careerId]->update(['max_seats' => $seats]));

        foreach ($after as $careerId => $seats) {
            if (! $quotas->has($careerId)) {
                GroupQuota::query()->create(['group_id' => $group->getKey(), 'career_id' => $careerId, 'max_seats' => $seats, 'is_owner' => $careerId === $group->owner_career_id]);
            }
        }

        activity()
            ->causedBy($actor)
            ->performedOn($group)
            ->event('parallels_arranged')
            ->withChanges(['old' => ['quotas' => $before], 'attributes' => ['quotas' => $after]])
            ->log("Armó los paralelos de {$subject->name}: repartió los cupos del grupo {$group->code}");

        $group->load('quotas');
        GroupQuotasChanged::dispatch($group);
    }

    /**
     * Crea un grupo suelto y sin horario de la fila de su carrera dueña, con los cupos del arreglo.
     *
     * @param  array<string, int>  $seats
     */
    private function create(Term $term, Modality $modality, Subject $subject, Campus $campus, ProposalItem $item, array $seats, int $capacity, User $actor): Group
    {
        $taken = Group::query()->active()->where('term_id', $term->getKey())->where('subject_id', $subject->getKey())->pluck('code')->all();
        $owner = $item->proposal->career_id;

        $group = Group::query()->create([
            'term_id' => $term->getKey(),
            'campus_id' => $campus->getKey(),
            'modality_id' => $modality->getKey(),
            'subject_id' => $subject->getKey(),
            'department_id' => $subject->department_id,
            'owner_career_id' => $owner,
            'study_plan_id' => $item->study_plan_id,
            'semester' => $item->semester,
            'code' => GroupCode::provisional($taken),
            'capacity' => $capacity,
            'status' => GroupStatus::Planned,
            'proposal_item_id' => $item->getKey(),
        ]);

        foreach ($seats as $careerId => $count) {
            GroupQuota::query()->create(['group_id' => $group->getKey(), 'career_id' => $careerId, 'max_seats' => $count, 'is_owner' => $careerId === $owner]);
        }

        activity()
            ->causedBy($actor)
            ->performedOn($group)
            ->event(ActivityEvent::Created)
            ->withChanges(['attributes' => [
                ...$group->only(['code', 'term_id', 'campus_id', 'modality_id', 'subject_id', 'owner_career_id', 'study_plan_id', 'semester', 'capacity', 'proposal_item_id']),
                'quotas' => $seats,
            ]])
            ->log("Creó el grupo {$group->code} de {$subject->name} al armar los paralelos");

        return $group->setRelation('quotas', $group->quotas()->get());
    }

    /**
     * Lo que cambió para cada carrera: sus lugares por clave de grupo antes y después, y el grupo donde le quedan más; solo
     * las carreras cuyos lugares cambiaron.
     *
     * @param  array<string, array<string, int>>  $before  cupos de cada grupo suelto antes, por id de grupo
     * @return array<string, array{before: array<string, int>, after: array<string, int>, group: ?string}>
     */
    private function careerChanges(ParallelArrangement $result, array $before): array
    {
        $careers = [];
        $add = function (string $side, Group $group, array $seats) use (&$careers): void {
            foreach ($seats as $careerId => $count) {
                $careers[$careerId] ??= ['before' => [], 'after' => [], 'group' => null, 'most' => 0];
                $careers[$careerId][$side][$group->code] = $count;

                if ($side === 'after' && $count > $careers[$careerId]['most']) {
                    $careers[$careerId]['group'] = $group->getKey();
                    $careers[$careerId]['most'] = $count;
                }
            }
        };

        foreach ($result->kept as $row) {
            $add('before', $row['group'], $row['before']);
            $add('after', $row['group'], $row['after']);
        }

        foreach ($result->cancelled as $row) {
            $add('before', $row['group'], $before[$row['group']->getKey()]);
        }

        foreach ($result->created as $group) {
            $add('after', $group, $group->quotas->pluck('max_seats', 'career_id')->all());
        }

        return collect($careers)
            ->reject(fn (array $change): bool => $change['before'] == $change['after'])
            ->map(fn (array $change): array => ['before' => $change['before'], 'after' => $change['after'], 'group' => $change['group']])
            ->all();
    }
}
