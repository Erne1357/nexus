<?php

namespace App\Modules\Scheduling\Queries;

use App\Modules\Academic\Models\StudyPlanSubject;
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
use App\Modules\Scheduling\Actions\Agreements\CancelRoomLoan;
use App\Modules\Scheduling\Actions\Agreements\RejectRoomLoan;
use App\Modules\Scheduling\Models\Group;
use App\Modules\Scheduling\Models\GroupSession;
use App\Modules\Scheduling\Rules\RoomLoanFree;
use App\Modules\Scheduling\Validation\Minutes;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Eloquent\Relations\Relation;
use Spatie\Activitylog\Models\Activity;

/**
 * Lo que muestra la pantalla Acuerdos (tareas 3.6 y 3.7, reglas-de-negocio.md, "Acuerdos entre departamentos"): los
 * préstamos de salón y los grupos compartidos del periodo y campus activos que el actor ve (`academic_load.agreement.view`
 * sobre el departamento que lo pide o el que lo decide, con `constrain()`), en tres grupos: «Por decidir» (propuestos
 * dirigidos a un departamento que el actor decide), «Enviados» (los demás propuestos: los que pidió un departamento
 * suyo y, para quien solo ve, los que esperan respuesta; supuesto por confirmar) y «Decididos» (aceptados, rechazados y
 * cancelados de cualquiera de los dos lados). Quién puede actuar lo decide InterdepartmentalAgreementPolicy en la página.
 *
 * Los pendientes van del más antiguo al más reciente, sin importar el tipo, para que el que más espera quede arriba y
 * ningún préstamo se pierda detrás de una serie de grupos compartidos; los decididos, del más reciente al más antiguo.
 */
final class AgreementsInboxQuery
{
    public const string DECIDE = 'decide';

    public const string SENT = 'sent';

    public const string DONE = 'done';

    public function __construct(
        private readonly RoomScheduleQuery $rooms,
        private readonly RoomLoanFree $free,
    ) {}

    /**
     * Los acuerdos visibles, filtrados por tipo y texto (salón, grupo, materia, departamentos o carrera) y agrupados.
     * Cada renglón dice de qué lado está el actor (`side`): `to` si decide el departamento al que va, `from` si pide por
     * el que lo pidió y `null` si solo lo ve.
     *
     * @return array{decide: list<array<string, mixed>>, sent: list<array<string, mixed>>, done: list<array<string, mixed>>}
     */
    public function inbox(Actor $actor, Term $term, Campus $campus, ?AgreementKind $kind = null, string $search = ''): array
    {
        $agreements = $this->visible($actor, $term, $campus)
            ->when($kind !== null, fn (Builder $query) => $query->where('kind', $kind->value))
            ->when(trim($search) !== '', fn (Builder $query) => $this->search($query, trim($search), $term, $campus))
            ->get();

        $rows = $this->rows($agreements);
        $deciders = [];
        $requesters = [];
        $grouped = [self::DECIDE => [], self::SENT => [], self::DONE => []];

        foreach ($rows as $row) {
            $deciders[$row['to']['id']] ??= $actor->allows(AcademicLoadPermission::AgreementDecide, Target::of(campus: $campus->getKey(), unit: $row['to']['id']));
            $requesters[$row['from']['id']] ??= $actor->allows(AcademicLoadPermission::AgreementRequest, Target::of(campus: $campus->getKey(), unit: $row['from']['id']));
            $row['side'] = $deciders[$row['to']['id']] ? 'to' : ($requesters[$row['from']['id']] ? 'from' : null);

            if ($row['status'] !== AgreementStatus::Proposed) {
                $grouped[self::DONE][] = $row;
            } else {
                $grouped[$deciders[$row['to']['id']] ? self::DECIDE : self::SENT][] = $row;
            }
        }

        $oldestFirst = fn (array $list): array => collect($list)->sortBy([['sortKey', 'asc'], ['id', 'asc']])->values()->all();

        return [
            self::DECIDE => $oldestFirst($grouped[self::DECIDE]),
            self::SENT => $oldestFirst($grouped[self::SENT]),
            self::DONE => collect($grouped[self::DONE])->sortBy([['sortKey', 'desc'], ['id', 'desc']])->values()->all(),
        ];
    }

    /**
     * Los préstamos propuestos de una lista que no se pueden aceptar porque su bloque empalma con la sesión de otro
     * grupo (HC-02) o con un bloqueo del salón (HC-11), con lo que lo ocupa: la revisión de AcceptRoomLoan
     * (RoomLoanFree), en dos consultas para toda la lista. Los grupos compartidos no entran.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, array{day: int, starts_at: string, ends_at: string, label: string, rule: string}>
     */
    public function blocked(array $rows): array
    {
        $loans = array_values(array_filter($rows, fn (array $row): bool => $row['kind'] === AgreementKind::RoomLoan
            && $row['status'] === AgreementStatus::Proposed && $row['room_id'] !== null && $row['day'] !== null));

        if ($loans === []) {
            return [];
        }

        $busy = $this->free->busy(
            array_values(array_unique(array_column($loans, 'room_id'))),
            array_values(array_unique(array_column($loans, 'term_id'))),
        );
        $blocked = [];

        foreach ($loans as $loan) {
            $clash = $this->free->clash([...$loan, 'group_id' => $loan['group']['id'] ?? null], $busy);

            if ($clash !== null) {
                $blocked[$loan['id']] = ['day' => $clash['day'], 'starts_at' => $clash['starts_at'], 'ends_at' => $clash['ends_at'], 'label' => $clash['label'], 'rule' => $clash['rule']];
            }
        }

        return $blocked;
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
     * La fila de un acuerdo, sin la semana del salón: lo que alcanza para resumirlo.
     *
     * @return array<string, mixed>
     */
    public function row(InterdepartmentalAgreement $agreement): array
    {
        return $this->rows(new EloquentCollection([$agreement]))[0];
    }

    /**
     * El detalle de un acuerdo para la página: sus datos y, en un préstamo, la semana del salón con el bloque
     * pedido y si choca con otro grupo o un bloqueo del salón (HC-02). En un grupo compartido, la semana del grupo
     * (`schedule`) y dónde está (`placement`): su semestre en el plan de la carrera dueña y en qué semestre lleva la
     * materia la carrera a la que se le abre el cupo, en cada plan suyo que la tiene.
     *
     * @return array<string, mixed>
     */
    public function detail(InterdepartmentalAgreement $agreement): array
    {
        $row = $this->row($agreement);
        $isLoan = $agreement->kind === AgreementKind::RoomLoan;
        $row['week'] = $isLoan ? $this->week($agreement) : null;
        $row['clash'] = $row['week']['clash'] ?? null;
        $group = $isLoan || $agreement->group_id === null ? null : Group::query()->with(['studyPlan' => fn (Relation $plan) => $plan->withTrashed(), 'ownerCareer' => fn (Relation $career) => $career->withTrashed()])->whereKey($agreement->group_id)->first();
        $row['schedule'] = $group === null ? null : $this->schedule($group, $agreement);
        $row['placement'] = $group === null ? null : $this->placement($group, $agreement);

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
    private function search(Builder $query, string $term, Term $period, Campus $campus): Builder
    {
        return $query->where(fn (Builder $any) => $any
            ->orWhereHas('room', fn ($room) => TextSearch::apply($room->withTrashed(), ['code'], $term))
            ->orWhereHas('fromDepartment', fn ($department) => TextSearch::apply($department->withTrashed(), ['code', 'name'], $term))
            ->orWhereHas('toDepartment', fn ($department) => TextSearch::apply($department->withTrashed(), ['code', 'name'], $term))
            ->orWhereHas('career', fn ($career) => TextSearch::apply($career->withTrashed(), ['code', 'name'], $term))
            ->orWhereIn('group_id', Group::query()->select('groups.id')
                ->where('groups.term_id', $period->getKey())
                ->where('groups.campus_id', $campus->getKey())
                ->where(fn (Builder $group) => $group
                    ->orWhere(fn (Builder $code) => TextSearch::apply($code, ['groups.code'], $term))
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

        $closedLoans = $agreements->filter(fn (InterdepartmentalAgreement $agreement): bool => $agreement->kind === AgreementKind::RoomLoan
            && in_array($agreement->status, [AgreementStatus::Rejected, AgreementStatus::Cancelled], true));
        $cleared = $closedLoans->isEmpty() ? [] : Activity::query()
            ->where('subject_type', (new Group)->getMorphClass())
            ->whereIn('subject_id', $closedLoans->pluck('group_id')->unique()->all())
            ->whereIn('event', [RejectRoomLoan::EVENT, CancelRoomLoan::EVENT])
            ->get()
            ->map(fn (Activity $entry): ?string => $entry->properties['agreement_id'] ?? null)
            ->all();

        return $agreements->map(function (InterdepartmentalAgreement $agreement) use ($groups, $cleared): array {
            $group = $groups->get($agreement->group_id);
            $closedAt = $agreement->cancelled_at ?? $agreement->decided_at;

            return [
                'id' => $agreement->getKey(),
                'kind' => $agreement->kind,
                'status' => $agreement->status,
                'room' => $agreement->room?->code,
                'room_id' => $agreement->room_id,
                'term_id' => $agreement->term_id,
                'day' => $agreement->day,
                'starts_at' => $agreement->starts_at === null ? null : substr($agreement->starts_at, 0, 5),
                'ends_at' => $agreement->ends_at === null ? null : substr($agreement->ends_at, 0, 5),
                'hours' => $agreement->starts_at === null ? null : ltrim(substr($agreement->starts_at, 0, 5), '0').'–'.ltrim(substr($agreement->ends_at, 0, 5), '0'),
                'day_name' => $agreement->day === null ? null : Minutes::dayLabel($agreement->day),
                'day_short' => $agreement->day === null ? null : Minutes::dayLabel($agreement->day, short: true),
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
                'room_cleared' => in_array($agreement->getKey(), $cleared, true),
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
     *     day_labels: array<int, string>,
     *     busy: list<array{day: int, starts_at: string, ends_at: string, label: string, rule: string}>,
     *     requested: array{day: int, starts_at: string, ends_at: string},
     *     clash: ?array{day: int, starts_at: string, ends_at: string, label: string, rule: string},
     * }
     */
    private function week(InterdepartmentalAgreement $agreement): array
    {
        $room = $agreement->room()->withTrashed()->firstOrFail();
        $term = KeyLookup::findOrFail(Term::query(), $agreement->term_id);
        $campus = KeyLookup::findOrFail(Campus::query(), $agreement->campus_id);
        $schedule = $this->rooms->for($room, $term);

        $busy = [];

        foreach ($schedule['sessions'] as $session) {
            if ($session['group_id'] === $agreement->group_id) {
                continue;
            }

            $busy[] = ['room_id' => $room->id, 'term_id' => $term->id, 'group_id' => $session['group_id'], 'day' => $session['day'], 'starts_at' => $session['starts_at'], 'ends_at' => $session['ends_at'], 'label' => "{$session['subject_code']} {$session['group_code']}", 'rule' => 'HC-02'];
        }

        foreach ($schedule['blocks'] as $block) {
            $busy[] = ['room_id' => $room->id, 'term_id' => $block['all_terms'] ? null : $term->id, 'group_id' => null, 'day' => $block['day'], 'starts_at' => $block['starts_at'], 'ends_at' => $block['ends_at'], 'label' => $block['reason'] === '' ? 'Bloqueado' : $block['reason'], 'rule' => 'HC-11'];
        }

        $requested = ['day' => (int) $agreement->day, 'starts_at' => substr($agreement->starts_at, 0, 5), 'ends_at' => substr($agreement->ends_at, 0, 5)];
        $slot = $this->free->clash($this->free->loanOf($agreement), $busy);
        $clash = $slot === null ? null : ['day' => $slot['day'], 'starts_at' => $slot['starts_at'], 'ends_at' => $slot['ends_at'], 'label' => $slot['label'], 'rule' => $slot['rule']];
        $busy = array_map(fn (array $slot): array => ['day' => $slot['day'], 'starts_at' => $slot['starts_at'], 'ends_at' => $slot['ends_at'], 'label' => $slot['label'], 'rule' => $slot['rule']], $busy);

        return [...$this->frame($campus, [$requested, ...$busy]), 'busy' => $busy, 'requested' => $requested, 'clash' => $clash];
    }

    /**
     * Las sesiones del grupo de un grupo compartido, en la misma cuadrícula de la semana de un préstamo.
     *
     * @return array{hours: list<int>, days: list<int>, day_labels: array<int, string>, sessions: list<array{day: int, starts_at: string, ends_at: string, hours: string, room: ?string}>}
     */
    private function schedule(Group $group, InterdepartmentalAgreement $agreement): array
    {
        $sessions = GroupSession::query()
            ->where('group_id', $group->id)
            ->where('is_active', true)
            ->with(['room' => fn (Relation $room) => $room->withTrashed()])
            ->orderBy('day')
            ->orderBy('starts_at')
            ->get()
            ->map(fn (GroupSession $session): array => [
                'day' => (int) $session->day,
                'starts_at' => Minutes::clock($session->starts_at),
                'ends_at' => Minutes::clock($session->ends_at),
                'hours' => ltrim(Minutes::clock($session->starts_at), '0').'–'.ltrim(Minutes::clock($session->ends_at), '0'),
                'room' => $session->room?->code,
            ])
            ->all();

        return [...$this->frame(KeyLookup::findOrFail(Campus::query(), $agreement->campus_id), $sessions), 'sessions' => $sessions];
    }

    /**
     * Dónde está el grupo: su semestre y el plan de la carrera dueña, y los semestres en que la carrera del cupo lleva
     * la materia en sus planes vigentes (activos y sin borrar), del plan actual al más viejo.
     *
     * @return array{semester: int, plan: string, owner: string, career: list<array{plan: string, semester: int}>}
     */
    private function placement(Group $group, InterdepartmentalAgreement $agreement): array
    {
        $career = $agreement->career_id === null ? [] : StudyPlanSubject::query()
            ->join('study_plans', 'study_plans.id', '=', 'study_plan_subjects.study_plan_id')
            ->where('study_plans.career_id', $agreement->career_id)
            ->where('study_plans.is_active', true)
            ->whereNull('study_plans.deleted_at')
            ->where('study_plan_subjects.subject_id', $group->subject_id)
            ->orderByDesc('study_plans.is_current')
            ->orderBy('study_plans.code')
            ->toBase()
            ->get(['study_plans.code as plan', 'study_plan_subjects.semester'])
            ->map(fn (object $row): array => ['plan' => (string) $row->plan, 'semester' => (int) $row->semester])
            ->all();

        return ['semester' => (int) $group->semester, 'plan' => (string) $group->studyPlan?->code, 'owner' => (string) $group->ownerCareer?->code, 'career' => $career];
    }

    /**
     * Las horas y los días de una mini semana: los del perfil del campus, extendidos a lo que se pinta fuera de ellos.
     *
     * @param  list<array{day: int, starts_at: string, ends_at: string}>  $slots
     * @return array{hours: list<int>, days: list<int>, day_labels: array<int, string>}
     */
    private function frame(Campus $campus, array $slots): array
    {
        $profile = $campus->profile;
        $starts = [Minutes::of($profile->dayStart), ...array_map(fn (array $slot): int => Minutes::of($slot['starts_at']), $slots)];
        $ends = [Minutes::of($profile->dayEnd), ...array_map(fn (array $slot): int => Minutes::of($slot['ends_at']), $slots)];
        $first = intdiv(min($starts), 60);
        $last = (int) ceil(max($ends) / 60);
        $days = collect([...$profile->days, ...array_column($slots, 'day')])->unique()->sort()->values()->all();

        return [
            'hours' => range($first, $last - 1),
            'days' => $days,
            'day_labels' => collect($days)->mapWithKeys(fn (int $day): array => [$day => Minutes::dayLabel($day, short: true)])->all(),
        ];
    }
}
