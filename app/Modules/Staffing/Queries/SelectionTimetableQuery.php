<?php

namespace App\Modules\Staffing\Queries;

use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\User;
use App\Modules\Core\Queries\KeyLookup;
use App\Modules\Facilities\Models\Room;
use App\Modules\Faculty\Models\Teacher;
use App\Modules\Faculty\Models\TeacherSubjectHistory;
use App\Modules\Faculty\Models\TeacherSubjectQualification;
use App\Modules\Faculty\Queries\TeacherBaseHoursQuery;
use App\Modules\Scheduling\Enums\GroupStatus;
use App\Modules\Scheduling\Enums\SessionKind;
use App\Modules\Scheduling\Models\Group;
use App\Modules\Scheduling\Models\GroupSession;
use App\Modules\Scheduling\Validation\Minutes;
use App\Modules\Staffing\Enums\SelectionEntrySource;
use App\Modules\Staffing\Enums\SelectionEntryStatus;
use App\Modules\Staffing\Enums\SelectionStatus;
use App\Modules\Staffing\Models\Selection;
use App\Modules\Staffing\Models\SelectionEntry;
use App\Modules\Staffing\Rules\ScheduleSummary;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as BaseCollection;
use Illuminate\Support\Facades\DB;

/**
 * El horario de la orden de selección (tarea 1.33, diseño 6.4 y maqueta A «Franjas por hora»): los grupos de las
 * cargas incluidas (SelectionGroupsQuery) por día y hora, con lo que el docente necesita para elegir: si es suyo, si
 * le choca con lo que ya tiene en el periodo (HC-01, de cualquier carga o campus), si la materia está en su perfil o en
 * su historial, sus horas contra su base (SEL-05) y el turno de ahora (SelectionTurnQuery, con la hora que se le da).
 * Sin docente (la jefatura sin renglón en la cola) no hay «tuyo», choques ni perfil. El periodo es el de la selección,
 * no el de la credencial. Un número constante de consultas, sin importar los grupos.
 *
 * `cells` va por sesión con llave `"{día}|{H:i}"`; strips() lo junta en un renglón por hora con los días de cada grupo
 * como marcas, que es lo que pinta la pantalla.
 *
 * @phpstan-type Cell array{group_id: string, subject_code: string, subject_name: string, group_code: string, capacity: int, required_space: string, teacher: ?string, mine: bool, room: ?string, in_profile: bool, clashes: bool, clash_with: ?string, teacher_id: ?string, semester: ?int, hours: int, department_id: ?string, modality_id: ?string, campus_id: ?string, ends_at: string}
 */
final class SelectionTimetableQuery
{
    /** La primera hora del horario y la hora en que acaba el último renglón (decisión del usuario: de 7:00 a 21:00). */
    public const int FIRST_HOUR = 7;

    public const int LAST_HOUR = 21;

    public function __construct(
        private readonly SelectionGroupsQuery $groups,
        private readonly SelectionTurnQuery $turns,
        private readonly TeacherBaseHoursQuery $baseHours,
    ) {}

    /**
     * Las selecciones vivas (publicadas, en curso o en ronda extra), con su departamento, periodo y modalidad. Quién ve
     * cada una lo decide SelectionPolicy::view.
     *
     * @return Collection<int, Selection>
     */
    public function live(): Collection
    {
        return Selection::query()
            ->whereIn('status', array_map(fn (SelectionStatus $status): string => $status->value, SelectionStatus::live()))
            ->with(['department:id,code,name', 'term:id,code,name', 'modality:id,code,name'])
            ->orderBy('starts_at')
            ->get();
    }

    /**
     * Una selección viva por su id del cliente, o null.
     */
    public function liveSelection(string $id): ?Selection
    {
        $selection = KeyLookup::find(Selection::query()->with(['department:id,code,name', 'term:id,code,name']), $id);

        return $selection?->status->isLive() ? $selection : null;
    }

    /**
     * La ficha de docente activa del usuario de la sesión: el docente que elige sale siempre de ahí, nunca del
     * navegador.
     */
    public function activeTeacherOf(User $user): ?Teacher
    {
        return Teacher::query()->where('user_id', $user->getKey())->where('is_active', true)->first();
    }

    public function entryOf(Selection $selection, Teacher $teacher): ?SelectionEntry
    {
        return SelectionEntry::query()->where('selection_id', $selection->getKey())->where('teacher_id', $teacher->getKey())->first();
    }

    /**
     * Un grupo de la selección por su id del cliente, o null si no es de sus cargas incluidas.
     */
    public function group(Selection $selection, string $id): ?Group
    {
        return KeyLookup::find($this->groups->of($selection), $id);
    }

    /**
     * Un docente de la cola de la selección por su id del cliente, o null: la jefatura asigna a mano solo a quien está
     * en el orden.
     */
    public function queueTeacher(Selection $selection, string $id): ?Teacher
    {
        return KeyLookup::find(Teacher::query()->whereIn('id', SelectionEntry::query()->where('selection_id', $selection->getKey())->select('teacher_id')), $id);
    }

    /**
     * Un salón por su id del cliente, o null. Si se puede elegir lo deciden SelectionRoomOptionsQuery y las Actions.
     */
    public function room(string $id): ?Room
    {
        return KeyLookup::find(Room::query(), $id);
    }

    /**
     * Las sesiones del grupo que llevan salón (todas menos las virtuales): un salón por grupo, como PickSelectionGroup.
     *
     * @return list<string>
     */
    public function roomSessionIds(Group $group): array
    {
        return GroupSession::query()->where('group_id', $group->getKey())->where('kind', '!=', SessionKind::Virtual->value)->pluck('id')->all();
    }

    /**
     * Las horas del docente en la selección y su base (SEL-05), para «Con este grupo llevarías…».
     *
     * @return array{hours: int, base: int}
     */
    public function hoursOf(Selection $selection, SelectionEntry $entry): array
    {
        $hours = (int) $this->groups->of($selection)
            ->where('groups.teacher_id', $entry->teacher_id)
            ->join('subjects', 'subjects.id', '=', 'groups.subject_id')
            ->sum(DB::raw('subjects.theory_hours + subjects.practice_hours'));

        return ['hours' => $hours, 'base' => $this->baseOf($selection, $entry)];
    }

    /**
     * @return array{days: list<int>, hours: list<string>, cells: array<string, list<Cell>>, my_hours: int, base_hours: int, my_groups: list<array<string, mixed>>, groups: int, free: int, turn: array<string, mixed>}
     */
    public function forTeacher(Selection $selection, ?Teacher $teacher, CarbonImmutable $now): array
    {
        $entry = $teacher === null ? null : $this->entryOf($selection, $teacher);
        $groups = $this->load($this->groups->of($selection));
        $context = $this->teacherContext($selection, $teacher);
        $cells = [];
        $days = [1, 2, 3, 4, 5];
        $hours = range(self::FIRST_HOUR, self::LAST_HOUR - 1);
        $mine = [];

        foreach ($groups as $group) {
            $card = $this->describe($group, $teacher, $context);

            if ($card['mine']) {
                $mine[] = $this->summary($group, $card);
            }

            foreach ($group->sessions as $session) {
                $start = Minutes::clock($session->starts_at);
                $cells["{$session->day}|{$start}"][] = $card + ['room' => $session->room?->code, 'ends_at' => Minutes::clock($session->ends_at)];
                $days[] = $session->day;
                $hours[] = intdiv(Minutes::of($session->starts_at), 60);
            }
        }

        $days = array_values(array_unique($days));
        $hours = array_values(array_unique($hours));
        sort($days);
        sort($hours);
        ksort($cells, SORT_NATURAL);

        return [
            'days' => $days,
            'hours' => array_map(fn (int $hour): string => sprintf('%02d:00', $hour), $hours),
            'cells' => $cells,
            'my_hours' => array_sum(array_column($mine, 'hours')),
            'base_hours' => $entry === null ? 0 : $this->baseOf($selection, $entry),
            'my_groups' => $mine,
            'groups' => $groups->count(),
            'free' => $groups->whereNull('teacher_id')->count(),
            'turn' => $this->turn($selection, $entry, $now),
        ];
    }

    /**
     * Un grupo de la selección como lo ve el docente (o la jefatura, sin docente), para el drawer: los datos de su ficha,
     * su horario, sus días y su salón.
     *
     * @return array<string, mixed>
     */
    public function card(Selection $selection, Group $group, ?Teacher $teacher): array
    {
        $group = $this->load(Group::query()->whereKey($group->getKey()))->firstOrFail();
        $card = $this->describe($group, $teacher, $this->teacherContext($selection, $teacher));
        $sessions = $group->sessions;

        return $card + $this->summary($group, $card) + [
            'days' => $sessions->pluck('day')->unique()->sort()->values()->all(),
            'department' => $group->department_id === $selection->department_id ? null : $group->department?->name,
            'teacher_id' => $group->teacher_id,
        ];
    }

    /**
     * Los docentes de la cola para «Asignar a…» de la jefatura, en el orden de selección: sus horas en la selección
     * contra su base y con cuál de sus grupos del periodo le choca este (HC-01; no veta en la lista, lo veta
     * AssignTeacher). `$order` es SelectionOrderQuery::forSelection().
     *
     * @param  list<array<string, mixed>>  $order
     * @return list<array{teacher_id: string, position: int, name: string, tier_label: ?string, hours: int, base_hours: int, status: string, in_turn: bool, clash_with: ?string}>
     */
    public function candidates(Selection $selection, Group $group, array $order): array
    {
        $order = array_values(array_filter($order, fn (array $row): bool => $row['teacher_id'] !== null));
        $ids = array_column($order, 'teacher_id');
        $hours = $this->groups->of($selection)
            ->whereIn('groups.teacher_id', $ids)
            ->join('subjects', 'subjects.id', '=', 'groups.subject_id')
            ->groupBy('groups.teacher_id')
            ->selectRaw('groups.teacher_id, sum(subjects.theory_hours + subjects.practice_hours) as hours')
            ->toBase()
            ->pluck('hours', 'teacher_id');
        $appended = array_column(array_filter($order, fn (array $row): bool => $row['source'] === SelectionEntrySource::Appended->value), 'teacher_id');
        $nexusBase = $appended === [] ? [] : $this->baseHours->forTerm($this->term($selection), $appended);
        $clashes = $this->clashesFor($selection, $group, $ids);

        return array_map(fn (array $row): array => [
            'teacher_id' => $row['teacher_id'],
            'position' => $row['position'],
            'name' => $row['name'],
            'tier_label' => $row['tier_label'],
            'hours' => (int) ($hours[$row['teacher_id']] ?? 0),
            'base_hours' => $row['source'] === SelectionEntrySource::Appended->value ? ($nexusBase[$row['teacher_id']] ?? 0) : $row['base_hours'],
            'status' => $row['status'],
            'in_turn' => $row['in_turn'],
            'clash_with' => $clashes[$row['teacher_id']] ?? null,
        ], $order);
    }

    /**
     * La maqueta A: un renglón por hora, de FIRST_HOUR a LAST_HOUR (y las horas de fuera que tengan grupos), con los
     * grupos que empiezan a esa hora como fichas; los días de cada ficha son los de sus sesiones a esa hora, y sus
     * salones, los de esas sesiones. Las fichas van por grupo y materia.
     *
     * @param  array{hours: list<string>, cells: array<string, list<array<string, mixed>>>}  $timetable
     * @return list<array{hour: string, until: string, groups: list<array<string, mixed>>, free: int, mine: bool}>
     */
    public static function strips(array $timetable): array
    {
        $byHour = [];

        foreach ($timetable['cells'] as $key => $items) {
            [$day, $start] = explode('|', $key);
            $hour = substr($start, 0, 2).':00';

            foreach ($items as $item) {
                $card = $byHour[$hour][$item['group_id']] ?? $item + ['days' => [], 'rooms' => [], 'starts_at' => $start];
                $card['days'][] = (int) $day;
                $card['rooms'][] = $item['room'];
                $card['starts_at'] = min($card['starts_at'], $start);
                $card['ends_at'] = max($card['ends_at'], $item['ends_at']);
                $byHour[$hour][$item['group_id']] = $card;
            }
        }

        return array_map(function (string $hour) use ($byHour): array {
            $until = sprintf('%02d:00', (int) substr($hour, 0, 2) + 1);
            $cards = array_map(function (array $card) use ($hour, $until): array {
                $card['days'] = array_values(array_unique($card['days']));
                sort($card['days']);
                $card['rooms'] = array_values(array_unique(array_filter($card['rooms'])));
                $card['time'] = $card['starts_at'] === $hour && $card['ends_at'] === $until ? null : Minutes::labelOf($card['starts_at']).'–'.Minutes::labelOf($card['ends_at']);
                unset($card['room']);

                return $card;
            }, array_values($byHour[$hour] ?? []));

            usort($cards, fn (array $a, array $b): int => strnatcmp($a['group_code'], $b['group_code']) ?: strcmp($a['subject_code'], $b['subject_code']));

            return [
                'hour' => $hour,
                'until' => $until,
                'groups' => $cards,
                'free' => count(array_filter($cards, fn (array $card): bool => $card['teacher'] === null)),
                'mine' => in_array(true, array_column($cards, 'mine'), true),
            ];
        }, $timetable['hours']);
    }

    /**
     * Los grupos con lo que pinta la ficha, en tres consultas.
     *
     * @param  Builder<Group>  $query
     * @return Collection<int, Group>
     */
    private function load(Builder $query): Collection
    {
        return $query
            ->with([
                'subject' => fn ($subject) => $subject->withTrashed()->select(['id', 'code', 'name', 'theory_hours', 'practice_hours', 'required_space']),
                'teacher' => fn ($teacher) => $teacher->withTrashed()->select(['id', 'name']),
                'department:id,name',
                'sessions' => fn ($sessions) => $sessions->whereNotNull('starts_at')->whereNotNull('ends_at')->orderBy('day')->orderBy('starts_at')->with('room:id,code'),
            ])
            ->get();
    }

    /**
     * Lo del docente que se cruza con cada ficha: las materias de su perfil o su historial y sus sesiones del periodo
     * (de cualquier carga, modalidad o campus) para HC-01.
     *
     * @return array{profile: array<string, true>, own: BaseCollection<int, object>}
     */
    private function teacherContext(Selection $selection, ?Teacher $teacher): array
    {
        if ($teacher === null) {
            return ['profile' => [], 'own' => collect()];
        }

        $profile = TeacherSubjectQualification::query()->where('teacher_id', $teacher->getKey())->pluck('subject_id')
            ->merge(TeacherSubjectHistory::query()->where('teacher_id', $teacher->getKey())->distinct()->pluck('subject_id'))
            ->filter()
            ->mapWithKeys(fn (string $id): array => [$id => true])
            ->all();

        $own = GroupSession::query()
            ->join('groups', 'groups.id', '=', 'group_sessions.group_id')
            ->join('subjects', 'subjects.id', '=', 'groups.subject_id')
            ->where('groups.term_id', $selection->term_id)
            ->where('groups.teacher_id', $teacher->getKey())
            ->where('groups.status', '<>', GroupStatus::Cancelled->value)
            ->whereNotNull('group_sessions.starts_at')
            ->whereNotNull('group_sessions.ends_at')
            ->toBase()
            ->get(['group_sessions.group_id', 'group_sessions.day', 'group_sessions.starts_at', 'group_sessions.ends_at', 'groups.code', 'subjects.code as subject_code']);

        return ['profile' => $profile, 'own' => $own];
    }

    /**
     * @param  array{profile: array<string, true>, own: BaseCollection<int, object>}  $context
     * @return array<string, mixed>
     */
    private function describe(Group $group, ?Teacher $teacher, array $context): array
    {
        $mine = $teacher !== null && $group->teacher_id === $teacher->getKey();
        $clash = $teacher === null || $mine ? null : $context['own']->first(fn (object $own): bool => $own->group_id !== $group->id
            && $group->sessions->contains(fn (GroupSession $session): bool => (int) $own->day === $session->day
                && Minutes::overlap(Minutes::of($session->starts_at), Minutes::of($session->ends_at), Minutes::of($own->starts_at), Minutes::of($own->ends_at))));

        return [
            'group_id' => $group->id,
            'subject_code' => (string) $group->subject?->code,
            'subject_name' => (string) $group->subject?->name,
            'group_code' => $group->code,
            'capacity' => (int) $group->capacity,
            'required_space' => $group->subject?->required_space?->value ?? 'aula',
            'teacher' => $group->teacher?->name,
            'mine' => $mine,
            'in_profile' => $teacher === null || isset($context['profile'][$group->subject_id]),
            'clashes' => $clash !== null,
            'clash_with' => $clash === null ? null : "{$clash->subject_code} {$clash->code}",
            'teacher_id' => $group->teacher_id,
            'semester' => $group->semester,
            'hours' => (int) ($group->subject?->theory_hours ?? 0) + (int) ($group->subject?->practice_hours ?? 0),
            'department_id' => $group->department_id,
            'modality_id' => $group->modality_id,
            'campus_id' => $group->campus_id,
        ];
    }

    /**
     * Lo que dice «Terminar mi carga» de un grupo: su horario en una línea y sus salones.
     *
     * @param  array<string, mixed>  $card
     * @return array{group_id: string, subject_code: string, group_code: string, subject_name: string, schedule: string, rooms: list<string>, hours: int}
     */
    private function summary(Group $group, array $card): array
    {
        $sessions = $group->sessions;

        return [
            'group_id' => $group->id,
            'subject_code' => $card['subject_code'],
            'group_code' => $card['group_code'],
            'subject_name' => $card['subject_name'],
            'schedule' => ScheduleSummary::of($sessions->map(fn (GroupSession $session): array => ['day' => $session->day, 'starts_at' => $session->starts_at, 'ends_at' => $session->ends_at]))->label(),
            'rooms' => $sessions->map(fn (GroupSession $session): ?string => $session->room?->code)->filter()->unique()->values()->all(),
            'hours' => $card['hours'],
        ];
    }

    /**
     * La base del docente (SEL-05): la del CSV o, si no viene en él, la de sus plazas en Nexus al inicio del periodo.
     */
    private function baseOf(Selection $selection, SelectionEntry $entry): int
    {
        if ($entry->source !== SelectionEntrySource::Appended) {
            return $entry->base_hours;
        }

        return $this->baseHours->forTerm($this->term($selection), [$entry->teacher_id])[$entry->teacher_id] ?? 0;
    }

    private function term(Selection $selection): Term
    {
        return $selection->relationLoaded('term') && $selection->term !== null ? $selection->term : Term::query()->whereKey($selection->term_id)->firstOrFail();
    }

    /**
     * El turno de ahora visto por el docente (o por la jefatura, sin renglón): si es el suyo, quién está en turno y
     * hasta cuándo, su hora (de la ronda en curso y de la extra), su lugar, cuántos terminaron y, si no hay nadie en
     * turno, quién sigue y a qué hora.
     *
     * @return array{is_mine: bool, current: ?string, ends_at: ?string, my_slot: ?string, my_extra_slot: ?string, my_slot_ends: ?string, position: ?int, total: int, entry_status: ?string, closed: bool, current_position: ?int, done: int, next: ?array{name: string, at: string}}
     */
    private function turn(Selection $selection, ?SelectionEntry $entry, CarbonImmutable $now): array
    {
        $extra = $selection->status === SelectionStatus::ExtraRound;
        [$startColumn, $endColumn] = $extra ? ['extra_slot_starts_at', 'extra_slot_ends_at'] : ['slot_starts_at', 'slot_ends_at'];
        $current = $this->turns->currentEntry($selection, $now)?->load('teacher:id,name');
        $isMine = $entry !== null && $current !== null && in_array($entry->getKey(), $this->turns->entryIdsInTurn($selection, $now), true);
        $counts = SelectionEntry::query()->where('selection_id', $selection->getKey())
            ->selectRaw('count(*) as total, count(*) filter (where status = ?) as done', [SelectionEntryStatus::Done->value])
            ->toBase()->first();
        $next = $current !== null ? null : SelectionEntry::query()
            ->where('selection_id', $selection->getKey())
            ->where('status', ($extra ? SelectionEntryStatus::PendingExtra : SelectionEntryStatus::Waiting)->value)
            ->where($startColumn, '>', $now->setTimezone(config('app.timezone')))
            ->orderBy($startColumn)->orderBy('position')
            ->with('teacher:id,name')
            ->first();

        return [
            'is_mine' => $isMine,
            'current' => $current?->teacher?->name ?? $current?->csv_name,
            'ends_at' => ($isMine ? $entry->{$endColumn} : $current?->{$endColumn})?->toIso8601String(),
            'my_slot' => $entry?->slot_starts_at?->toIso8601String(),
            'my_extra_slot' => $entry?->extra_slot_starts_at?->toIso8601String(),
            'my_slot_ends' => $entry?->{$endColumn}?->toIso8601String(),
            'position' => $entry?->position,
            'total' => (int) ($counts->total ?? 0),
            'entry_status' => $entry?->status->value,
            'closed' => $entry?->closed_at !== null,
            'current_position' => $current?->position,
            'done' => (int) ($counts->done ?? 0),
            'next' => $next === null ? null : ['name' => $next->teacher->name ?? (string) $next->csv_name, 'at' => $next->{$startColumn}->toIso8601String()],
        ];
    }

    /**
     * Con cuál de sus grupos del periodo choca este, por docente (HC-01): la primera sesión que se empalma.
     *
     * @param  list<string>  $teacherIds
     * @return array<string, string>
     */
    private function clashesFor(Selection $selection, Group $group, array $teacherIds): array
    {
        $sessions = GroupSession::query()->where('group_id', $group->id)->whereNotNull('starts_at')->whereNotNull('ends_at')->get(['day', 'starts_at', 'ends_at']);

        if ($sessions->isEmpty() || $teacherIds === []) {
            return [];
        }

        $clashes = [];

        GroupSession::query()
            ->join('groups', 'groups.id', '=', 'group_sessions.group_id')
            ->join('subjects', 'subjects.id', '=', 'groups.subject_id')
            ->where('groups.term_id', $selection->term_id)
            ->whereIn('groups.teacher_id', $teacherIds)
            ->where('groups.id', '!=', $group->id)
            ->where('groups.status', '<>', GroupStatus::Cancelled->value)
            ->whereIn('group_sessions.day', $sessions->pluck('day')->unique()->all())
            ->whereNotNull('group_sessions.starts_at')
            ->orderBy('subjects.code')
            ->orderBy('groups.code')
            ->toBase()
            ->get(['groups.teacher_id', 'groups.code', 'subjects.code as subject_code', 'group_sessions.day', 'group_sessions.starts_at', 'group_sessions.ends_at'])
            ->each(function (object $other) use ($sessions, &$clashes): void {
                if (isset($clashes[$other->teacher_id])) {
                    return;
                }

                $overlaps = $sessions->contains(fn (GroupSession $session): bool => $session->day === (int) $other->day
                    && Minutes::overlap(Minutes::of($session->starts_at), Minutes::of($session->ends_at), Minutes::of($other->starts_at), Minutes::of($other->ends_at)));

                if ($overlaps) {
                    $clashes[$other->teacher_id] = "{$other->subject_code} {$other->code}";
                }
            });

        return $clashes;
    }
}
