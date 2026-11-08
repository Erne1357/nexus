<?php

namespace App\Modules\Scheduling\Queries;

use App\Modules\AcademicLoad\Enums\AcademicLoadPermission;
use App\Modules\AcademicLoad\Enums\AgreementKind;
use App\Modules\AcademicLoad\Enums\AgreementStatus;
use App\Modules\AcademicLoad\Models\InterdepartmentalAgreement;
use App\Modules\Core\Authorization\Actor;
use App\Modules\Core\Authorization\Target;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Queries\KeyLookup;
use App\Modules\Core\Queries\TextSearch;
use App\Modules\Scheduling\Models\Group;
use App\Modules\Scheduling\Validation\Minutes;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Str;

/**
 * Lo que muestra la pantalla Acuerdos (tareas 3.6 y 3.7, reglas-de-negocio.md, "Acuerdos entre departamentos"): los
 * préstamos de salón y los grupos compartidos del periodo y campus activos que el actor ve (`academic_load.agreement.view`
 * sobre el departamento que lo pide o el que lo decide, con `constrain()`), en tres grupos: «Por decidir» (propuestos
 * dirigidos a un departamento que el actor decide), «Enviados» (los demás propuestos: los que pidió un departamento
 * suyo y, para quien solo ve, los que esperan respuesta; supuesto por confirmar) y «Decididos» (aceptados, rechazados y
 * cancelados de cualquiera de los dos lados). Quién puede actuar lo decide InterdepartmentalAgreementPolicy en la página.
 */
final class AgreementsInboxQuery
{
    public const string DECIDE = 'decide';

    public const string SENT = 'sent';

    public const string DONE = 'done';

    public function __construct(private readonly RoomScheduleQuery $rooms) {}

    /**
     * Los acuerdos visibles, filtrados por tipo y texto (salón, grupo, materia, departamentos o carrera), agrupados y
     * del más reciente (decisión o petición) al más antiguo.
     *
     * @return array{decide: list<array<string, mixed>>, sent: list<array<string, mixed>>, done: list<array<string, mixed>>}
     */
    public function inbox(Actor $actor, Term $term, Campus $campus, ?AgreementKind $kind = null, string $search = ''): array
    {
        $agreements = $this->visible($actor, $term, $campus)
            ->when($kind !== null, fn (Builder $query) => $query->where('kind', $kind->value))
            ->when(trim($search) !== '', fn (Builder $query) => $this->search($query, trim($search)))
            ->get();

        $rows = $this->rows($agreements);
        $deciders = [];
        $grouped = [self::DECIDE => [], self::SENT => [], self::DONE => []];

        foreach ($rows as $row) {
            if ($row['status'] !== AgreementStatus::Proposed) {
                $grouped[self::DONE][] = $row;

                continue;
            }

            $deciders[$row['to']['id']] ??= $actor->allows(AcademicLoadPermission::AgreementDecide, Target::of(campus: $campus->getKey(), unit: $row['to']['id']));
            $grouped[$deciders[$row['to']['id']] ? self::DECIDE : self::SENT][] = $row;
        }

        return array_map(
            fn (array $list): array => collect($list)->sortByDesc('sortKey')->values()->all(),
            $grouped,
        );
    }

    /**
     * Un acuerdo visible para el actor en el periodo y campus por un id que llega del cliente; uno que no ve no existe.
     *
     * @throws ModelNotFoundException<InterdepartmentalAgreement>
     */
    public function findOrFail(Actor $actor, Term $term, Campus $campus, string $id): InterdepartmentalAgreement
    {
        return KeyLookup::findOrFail($this->visible($actor, $term, $campus), $id);
    }

    /**
     * El detalle de un acuerdo para la página: sus datos y, en un préstamo, la semana del salón con el bloque
     * pedido y si choca con otro grupo o un bloqueo del salón (HC-02).
     *
     * @return array<string, mixed>
     */
    public function detail(InterdepartmentalAgreement $agreement): array
    {
        $row = $this->rows(new EloquentCollection([$agreement]))[0];
        $row['week'] = $agreement->kind === AgreementKind::RoomLoan ? $this->week($agreement) : null;
        $row['clash'] = $row['week']['clash'] ?? null;

        return $row;
    }

    /**
     * @return Builder<InterdepartmentalAgreement>
     */
    private function visible(Actor $actor, Term $term, Campus $campus): Builder
    {
        $query = InterdepartmentalAgreement::query()
            ->where('term_id', $term->getKey())
            ->where('campus_id', $campus->getKey());

        return $actor->constrain($query, AcademicLoadPermission::AgreementView, [
            'campus' => 'campus_id',
            'unit' => fn (Builder $scoped, array $ids) => $scoped->where(fn (Builder $either) => $either
                ->whereIn('from_department_id', $ids)
                ->orWhereIn('to_department_id', $ids)),
        ]);
    }

    /**
     * @param  Builder<InterdepartmentalAgreement>  $query
     * @return Builder<InterdepartmentalAgreement>
     */
    private function search(Builder $query, string $term): Builder
    {
        $pattern = '%'.TextSearch::escape($term).'%';

        return $query->where(fn (Builder $any) => $any
            ->orWhereHas('room', fn ($room) => TextSearch::apply($room->withTrashed(), ['code'], $term))
            ->orWhereHas('fromDepartment', fn ($department) => TextSearch::apply($department->withTrashed(), ['code', 'name'], $term))
            ->orWhereHas('toDepartment', fn ($department) => TextSearch::apply($department->withTrashed(), ['code', 'name'], $term))
            ->orWhereHas('career', fn ($career) => TextSearch::apply($career->withTrashed(), ['code', 'name'], $term))
            ->orWhereIn('group_id', Group::query()->select('groups.id')->where(fn (Builder $group) => $group
                ->orWhereLike('groups.code', $pattern)
                ->orWhereHas('subject', fn ($subject) => TextSearch::apply($subject->withTrashed(), ['code', 'name'], $term)))));
    }

    /**
     * Las filas de la lista, con lo que hace falta de grupos, departamentos, carrera, salón y personas en un número
     * fijo de consultas.
     *
     * @param  EloquentCollection<int, InterdepartmentalAgreement>  $agreements
     * @return list<array<string, mixed>>
     */
    private function rows(EloquentCollection $agreements): array
    {
        $trashed = fn (Relation $relation) => $relation->withTrashed();
        $agreements->load([
            'room' => $trashed, 'career' => $trashed, 'fromDepartment' => $trashed, 'toDepartment' => $trashed,
            'requester', 'decider', 'canceller',
        ]);
        $groups = Group::query()
            ->whereIn('id', $agreements->pluck('group_id')->filter()->all())
            ->with(['subject' => $trashed])
            ->get()
            ->keyBy('id');

        return $agreements->map(function (InterdepartmentalAgreement $agreement) use ($groups): array {
            $group = $groups->get($agreement->group_id);
            $closedAt = $agreement->cancelled_at ?? $agreement->decided_at;

            return [
                'id' => $agreement->getKey(),
                'kind' => $agreement->kind,
                'status' => $agreement->status,
                'room' => $agreement->room?->code,
                'day' => $agreement->day,
                'starts_at' => $agreement->starts_at === null ? null : substr($agreement->starts_at, 0, 5),
                'ends_at' => $agreement->ends_at === null ? null : substr($agreement->ends_at, 0, 5),
                'hours' => $agreement->starts_at === null ? null : ltrim(substr($agreement->starts_at, 0, 5), '0').'–'.ltrim(substr($agreement->ends_at, 0, 5), '0'),
                'day_name' => $agreement->day === null ? null : Str::ucfirst(Minutes::dayName($agreement->day)),
                'day_short' => $agreement->day === null ? null : mb_substr(Str::ucfirst(Minutes::dayName($agreement->day)), 0, 3),
                'group' => $group === null ? null : [
                    'id' => $group->getKey(),
                    'code' => $group->code,
                    'subject_code' => $group->subject->code,
                    'subject_name' => $group->subject->name,
                    'label' => "{$group->subject->code} {$group->code}",
                ],
                'from' => ['id' => $agreement->from_department_id, 'code' => $agreement->fromDepartment->code, 'name' => $agreement->fromDepartment->name],
                'to' => ['id' => $agreement->to_department_id, 'code' => $agreement->toDepartment->code, 'name' => $agreement->toDepartment->name],
                'career' => $agreement->career === null ? null : ['code' => $agreement->career->code, 'name' => $agreement->career->name],
                'seats' => $agreement->max_seats,
                'requested_by' => $agreement->requester?->name,
                'requested_at' => $agreement->created_at,
                'decided_by' => $agreement->decider?->name,
                'decided_at' => $agreement->decided_at,
                'cancelled_by' => $agreement->canceller?->name,
                'cancelled_at' => $agreement->cancelled_at,
                'comment' => $agreement->comment,
                'sortKey' => ($closedAt ?? $agreement->created_at)?->format('Y-m-d H:i:s.u'),
            ];
        })->all();
    }

    /**
     * La semana del salón con lo que ya tiene y el bloque pedido.
     *
     * @return array{
     *     hours: list<int>,
     *     days: list<int>,
     *     busy: list<array{day: int, starts_at: string, ends_at: string, label: string}>,
     *     requested: array{day: int, starts_at: string, ends_at: string},
     *     clash: ?array{day: int, starts_at: string, ends_at: string, label: string},
     * }
     */
    private function week(InterdepartmentalAgreement $agreement): array
    {
        $room = $agreement->room()->withTrashed()->firstOrFail();
        $term = KeyLookup::findOrFail(Term::query(), $agreement->term_id);
        $campus = KeyLookup::findOrFail(Campus::query(), $agreement->campus_id);
        $profile = $campus->profile;
        $schedule = $this->rooms->for($room, $term);

        $busy = [];

        foreach ($schedule['sessions'] as $session) {
            if ($session['group_id'] === $agreement->group_id) {
                continue;
            }

            $busy[] = ['day' => $session['day'], 'starts_at' => $session['starts_at'], 'ends_at' => $session['ends_at'], 'label' => "{$session['subject_code']} {$session['group_code']}"];
        }

        foreach ($schedule['blocks'] as $block) {
            $busy[] = ['day' => $block['day'], 'starts_at' => $block['starts_at'], 'ends_at' => $block['ends_at'], 'label' => $block['reason'] === '' ? 'Bloqueado' : $block['reason']];
        }

        $requested = ['day' => (int) $agreement->day, 'starts_at' => substr($agreement->starts_at, 0, 5), 'ends_at' => substr($agreement->ends_at, 0, 5)];
        $clash = collect($busy)->first(fn (array $slot): bool => $slot['day'] === $requested['day']
            && Minutes::overlap(Minutes::of($slot['starts_at']), Minutes::of($slot['ends_at']), Minutes::of($requested['starts_at']), Minutes::of($requested['ends_at'])));

        $first = min(intdiv(Minutes::of($profile->dayStart), 60), intdiv(Minutes::of($requested['starts_at']), 60));
        $last = max((int) ceil(Minutes::of($profile->dayEnd) / 60), (int) ceil(Minutes::of($requested['ends_at']) / 60));

        return [
            'hours' => range($first, $last - 1),
            'days' => collect([...$profile->days, $requested['day'], ...array_column($busy, 'day')])->unique()->sort()->values()->all(),
            'busy' => $busy,
            'requested' => $requested,
            'clash' => $clash,
        ];
    }
}
