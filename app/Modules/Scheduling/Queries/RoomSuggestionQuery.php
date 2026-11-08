<?php

namespace App\Modules\Scheduling\Queries;

use App\Modules\Academic\Models\Department;
use App\Modules\Academic\Models\Subject;
use App\Modules\AcademicLoad\Enums\AgreementKind;
use App\Modules\AcademicLoad\Enums\AgreementStatus;
use App\Modules\AcademicLoad\Models\InterdepartmentalAgreement;
use App\Modules\AcademicLoad\Queries\RoomLoansQuery;
use App\Modules\Core\Models\Term;
use App\Modules\Facilities\Enums\RoomType;
use App\Modules\Facilities\Models\Room;
use App\Modules\Facilities\Models\RoomBlock;
use App\Modules\Facilities\Models\SubjectRoomDefault;
use App\Modules\Scheduling\Enums\SessionKind;
use App\Modules\Scheduling\Models\Group;
use App\Modules\Scheduling\Models\GroupSession;
use App\Modules\Scheduling\Rules\SessionRules;
use App\Modules\Scheduling\Validation\Minutes;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;

/**
 * Los salones que el cajón de la sesión ofrece (tarea 3.7, SC-03): los activos del campus del grupo, ordenados por un
 * puntaje con su desglose en motivos, y lo que pasaría con cada uno en esta sesión. Lee en un número fijo de
 * consultas, sin importar cuántos salones tenga el campus. No decide quién lo ve ni quién asigna: eso es de la
 * pantalla (GroupPolicy::assignRoom).
 *
 * El puntaje (supuesto por confirmar; reglas-de-negocio.md, "Editor de horarios"):
 * - **Por defecto de la materia** en el campus (`subject_room_defaults`): DEFAULT_POINTS con prioridad 1, DEFAULT_STEP
 *   menos por cada prioridad siguiente, nunca menos de DEFAULT_MIN.
 * - **Historial:** HISTORY_POINTS por cada periodo anterior en que la materia usó el salón, hasta HISTORY_MAX.
 * - **Tipo requerido:** TYPE_POINTS si la práctica de una materia con laboratorio cae en laboratorio, taller o sala de
 *   cómputo, o la teoría en un aula. La práctica sin laboratorio y la sesión virtual no piden tipo.
 * - **Capacidad:** si cabe el grupo, hasta CAPACITY_POINTS, más mientras más justo.
 * - **Mismo edificio** que la clase anterior del semestre del grupo (su plan y semestre) ese día: BUILDING_POINTS.
 * - Lo que causaría resta: ocupado (HC-02) BUSY_PENALTY, bloqueado (HC-11) BLOCKED_PENALTY, tipo equivocado (HC-06)
 *   WRONG_TYPE_PENALTY, no cabe (HC-05) TOO_SMALL_PENALTY y préstamo sin aceptar (HC-10) LOAN_PENALTY.
 *
 * A igual puntaje, por clave. Un salón ocupado no se elige: por la sesión de otro grupo a esa hora (HC-02) o por el
 * préstamo vivo de otro grupo a esa hora («Pedido para…», «Prestado a…»; HC-10, porque el préstamo no se podría
 * pedir). Los demás se pueden elegir y sus choques duros se guardan marcados (confirmado el 7 de noviembre de 2026). Si
 * el préstamo aceptado del grupo cubre la sesión, se ve cubierto; la cobertura es la de RoomLoansQuery::uncovered().
 *
 * Cada choque y cada «ocupado» trae una etiqueta corta para la insignia (`label`, `busy`) y el texto libre aparte
 * (`detail`, `busyDetail`), para que la pantalla lo parta en renglones y no desborde a 390 px.
 *
 * @phpstan-type Conflict array{rule: string, label: string, detail: ?string, message: string}
 * @phpstan-type State array{free: bool, busy: ?string, busyDetail: ?string, busyNote: ?string, blocked: ?string, needsLoan: bool, covered: bool, pending: bool, tooSmall: bool, wrongType: bool}
 * @phpstan-type Row array{id: string, code: string, name: ?string, type: string, capacity: int, building: ?string, owner: array{code: string, name: string}|null, score: int, reasons: list<string>, state: State, conflicts: list<Conflict>, selectable: bool, current: bool}
 */
final class RoomSuggestionQuery
{
    public const int DEFAULT_POINTS = 40;

    public const int DEFAULT_STEP = 10;

    public const int DEFAULT_MIN = 10;

    public const int HISTORY_POINTS = 10;

    public const int HISTORY_MAX = 30;

    public const int TYPE_POINTS = 20;

    public const int CAPACITY_POINTS = 15;

    public const int BUILDING_POINTS = 10;

    public const int BUSY_PENALTY = 100;

    public const int BLOCKED_PENALTY = 60;

    public const int WRONG_TYPE_PENALTY = 40;

    public const int TOO_SMALL_PENALTY = 30;

    public const int LOAN_PENALTY = 5;

    /** Cuántos periodos del historial se nombran en el motivo. */
    private const int HISTORY_SHOWN = 3;

    /**
     * El departamento del grupo (el que asigna), el salón actual de la sesión (aunque ya no esté activo), los
     * `$limit` mejores salones y, con `$search`, hasta `$limit` más que contienen el texto en su clave o nombre y no
     * están entre los sugeridos (`results`) y los ids de los sugeridos que también lo contienen (`matched`). Todo sale
     * del mismo contexto: buscar no vuelve a leer la base.
     *
     * @return array{department: array{code: string, name: string}, current: Row|null, rooms: list<Row>, results: list<Row>, matched: list<string>}
     */
    public function for(GroupSession $session, int $limit = 8, string $search = ''): array
    {
        $group = $session->group;
        $subject = Subject::withTrashed()->whereKey($group->subject_id)->firstOrFail(['id', 'code', 'needs_lab']);
        $department = Department::withTrashed()->whereKey($group->department_id)->firstOrFail(['code', 'name']);
        $withTrashed = fn (Relation $query) => $query->withTrashed();

        $rooms = Room::query()
            ->where('campus_id', $group->campus_id)
            ->where('is_active', true)
            ->with(['building' => $withTrashed, 'department' => $withTrashed])
            ->get();

        $currentRoom = $session->room_id === null ? null : $rooms->firstWhere('id', $session->room_id)
            ?? Room::withTrashed()->whereKey($session->room_id)->with(['building' => $withTrashed, 'department' => $withTrashed])->first();

        $loans = $this->loans($group, $session);

        $context = [
            'group' => $group,
            'subject' => $subject,
            'session' => $session,
            'defaults' => $this->defaults($group),
            'history' => $this->history($group),
            'building' => $this->previousBuilding($group, $session),
            'busy' => $this->busy($group, $session) + $loans['taken'],
            'blocks' => $this->blocks($group, $session),
            'loans' => $loans['own'],
        ];

        $ranked = $rooms->toBase()
            ->map(fn (Room $room): array => $this->row($room, $context))
            ->sort(fn (array $a, array $b): int => [$b['score'], $a['code']] <=> [$a['score'], $b['code']])
            ->values();
        $needle = mb_strtolower(trim($search));
        $matches = fn (array $row): bool => $needle !== ''
            && (str_contains(mb_strtolower($row['code']), $needle) || str_contains(mb_strtolower((string) $row['name']), $needle));

        return [
            'department' => ['code' => $department->code, 'name' => $department->name],
            'current' => $currentRoom === null ? null : $this->row($currentRoom, $context),
            'rooms' => $ranked->take($limit)->all(),
            'results' => $ranked->skip($limit)->filter($matches)->take($limit)->values()->all(),
            'matched' => $ranked->take($limit)->filter($matches)->pluck('id')->values()->all(),
        ];
    }

    /**
     * @param  array<string, mixed>  $context
     * @return Row
     */
    private function row(Room $room, array $context): array
    {
        /** @var Group $group */
        $group = $context['group'];
        /** @var GroupSession $session */
        $session = $context['session'];
        $reasons = [];
        $conflicts = [];
        $score = 0;

        if (isset($context['defaults'][$room->id])) {
            $priority = $context['defaults'][$room->id];
            $score += max(self::DEFAULT_MIN, self::DEFAULT_POINTS - ($priority - 1) * self::DEFAULT_STEP);
            $reasons[] = $priority === 1 ? 'Por defecto de la materia' : 'Sugerido para la materia';
        }

        if (isset($context['history'][$room->id])) {
            $terms = $context['history'][$room->id];
            $score += min(self::HISTORY_MAX, count($terms) * self::HISTORY_POINTS);
            $reasons[] = 'Usado en '.self::sentence(array_slice($terms, 0, self::HISTORY_SHOWN));
        }

        $practiceSpace = $session->kind === SessionKind::Practica && $context['subject']->needs_lab;
        $wrongType = $practiceSpace && ! $room->type->isPracticeSpace();

        if (($practiceSpace && $room->type->isPracticeSpace()) || ($session->kind === SessionKind::Teoria && $room->type === RoomType::Aula)) {
            $score += self::TYPE_POINTS;
            $reasons[] = $room->type->label();
        }

        $tooSmall = $room->capacity < $group->capacity;

        if ($tooSmall) {
            $reasons[] = "No cabe: {$group->capacity} de {$room->capacity}";
        } else {
            $score += (int) round(self::CAPACITY_POINTS * $group->capacity / max(1, $room->capacity));
            $reasons[] = "Cabe: {$group->capacity} de {$room->capacity}";
        }

        if ($context['building'] !== null && $room->building_id === $context['building']) {
            $score += self::BUILDING_POINTS;
            $reasons[] = 'Mismo edificio que la clase anterior';
        }

        $busy = $context['busy'][$room->id] ?? null;
        $block = $context['blocks'][$room->id] ?? null;
        $needsLoan = $room->department_id !== null && $room->department_id !== $group->department_id;
        $loans = $context['loans'][$room->id] ?? [];
        $accepted = array_map(fn (array $loan): array => [$loan['start'], $loan['end']], array_filter($loans, fn (array $loan): bool => $loan['accepted']));
        $covered = $needsLoan && RoomLoansQuery::uncovered($accepted, Minutes::of($session->starts_at), Minutes::of($session->ends_at)) === [];
        $pending = $needsLoan && array_any($loans, fn (array $loan): bool => ! $loan['accepted']);
        $subjectGroup = "{$context['subject']->code} {$group->code}";

        if ($busy !== null) {
            $score -= self::BUSY_PENALTY;
        }

        if ($block !== null) {
            $score -= self::BLOCKED_PENALTY;
            $conflicts[] = ['rule' => 'HC-11', 'label' => 'Bloqueado', 'detail' => "{$block['reason']} · {$block['short']}", 'message' => "{$room->code} está bloqueado el {$block['when']}: {$block['reason']}."];
        }

        if ($wrongType) {
            $score -= self::WRONG_TYPE_PENALTY;
            $type = $room->type->label();
            $conflicts[] = ['rule' => 'HC-06', 'label' => 'No sirve para práctica', 'detail' => null, 'message' => "{$room->code} es ".mb_strtolower($type).": las prácticas de {$context['subject']->code} piden laboratorio, taller o sala de cómputo."];
        }

        if ($tooSmall) {
            $score -= self::TOO_SMALL_PENALTY;
            $conflicts[] = ['rule' => 'HC-05', 'label' => 'No cabe', 'detail' => null, 'message' => "{$subjectGroup} es de {$group->capacity} lugares y {$room->code} tiene {$room->capacity}."];
        }

        if ($needsLoan && ! $covered) {
            $score -= self::LOAN_PENALTY;
            $owner = $room->department?->name;
            $conflicts[] = [
                'rule' => 'HC-10',
                'label' => $pending ? 'Préstamo pendiente' : 'Pide préstamo',
                'detail' => "De {$owner}",
                'message' => "{$room->code} es de {$owner}: el préstamo del ".SessionRules::describe($session).' '.($pending ? 'está' : 'queda').' pendiente hasta que su jefe lo acepte.',
            ];
        }

        return [
            'id' => $room->id,
            'code' => $room->code,
            'name' => $room->name,
            'type' => $room->type->label(),
            'capacity' => $room->capacity,
            'building' => $room->building?->code,
            'owner' => $room->department_id === null ? null : ['code' => $room->department?->code, 'name' => $room->department?->name],
            'score' => $score,
            'reasons' => $reasons,
            'state' => [
                'free' => $busy === null && $conflicts === [],
                'busy' => $busy['label'] ?? null,
                'busyDetail' => $busy['detail'] ?? null,
                'busyNote' => $busy['note'] ?? null,
                'blocked' => $block === null ? null : "{$block['reason']} · {$block['short']}",
                'needsLoan' => $needsLoan,
                'covered' => $covered,
                'pending' => $pending,
                'tooSmall' => $tooSmall,
                'wrongType' => $wrongType,
            ],
            'conflicts' => $conflicts,
            'selectable' => $busy === null,
            'current' => $room->id === $session->room_id,
        ];
    }

    /**
     * La prioridad más alta (el número menor) de cada salón por defecto o sugerido de la materia en el campus.
     *
     * @return array<string, int>
     */
    private function defaults(Group $group): array
    {
        return SubjectRoomDefault::query()
            ->where('subject_id', $group->subject_id)
            ->where('campus_id', $group->campus_id)
            ->groupBy('room_id')
            ->selectRaw('room_id, min(priority) as priority')
            ->pluck('priority', 'room_id')
            ->map(fn (mixed $priority): int => (int) $priority)
            ->all();
    }

    /**
     * Los periodos anteriores en que la materia usó cada salón del campus, del más reciente al más viejo.
     *
     * @return array<string, list<string>>
     */
    private function history(Group $group): array
    {
        $history = [];

        GroupSession::query()
            ->join('groups', 'groups.id', '=', 'group_sessions.group_id')
            ->join('terms', 'terms.id', '=', 'group_sessions.term_id')
            ->where('groups.subject_id', $group->subject_id)
            ->where('group_sessions.campus_id', $group->campus_id)
            ->where('group_sessions.is_active', true)
            ->whereNotNull('group_sessions.room_id')
            ->where('terms.starts_on', '<', Term::query()->select('starts_on')->whereKey($group->term_id))
            ->distinct()
            ->orderByDesc('terms.starts_on')
            ->toBase()
            ->get(['group_sessions.room_id', 'terms.code', 'terms.starts_on'])
            ->each(function (object $row) use (&$history): void {
                $history[$row->room_id][] = $row->code;
            });

        return $history;
    }

    /**
     * El edificio de la clase anterior del semestre del grupo (su plan y semestre) ese día: la que termina más tarde
     * antes de que empiece la sesión, con salón.
     */
    private function previousBuilding(Group $group, GroupSession $session): ?string
    {
        return GroupSession::query()
            ->join('groups', 'groups.id', '=', 'group_sessions.group_id')
            ->join('rooms', 'rooms.id', '=', 'group_sessions.room_id')
            ->where('group_sessions.term_id', $group->term_id)
            ->where('group_sessions.campus_id', $group->campus_id)
            ->where('group_sessions.is_active', true)
            ->where('groups.study_plan_id', $group->study_plan_id)
            ->where('groups.semester', $group->semester)
            ->where('group_sessions.day', $session->day)
            ->where('group_sessions.ends_at', '<=', $session->starts_at)
            ->whereKeyNot($session->id)
            ->orderByDesc('group_sessions.ends_at')
            ->value('rooms.building_id');
    }

    /**
     * HC-02: quién ocupa cada salón del campus a la hora de la sesión: «Ocupado», «AED-1026 3A · mar 15:00–16:00».
     *
     * @return array<string, array{label: string, detail: string, note: string}>
     */
    private function busy(Group $group, GroupSession $session): array
    {
        $busy = [];

        $this->overlapping(GroupSession::query(), 'group_sessions', $session)
            ->join('groups', 'groups.id', '=', 'group_sessions.group_id')
            ->join('subjects', 'subjects.id', '=', 'groups.subject_id')
            ->where('group_sessions.term_id', $group->term_id)
            ->where('group_sessions.campus_id', $group->campus_id)
            ->where('group_sessions.is_active', true)
            ->whereNotNull('group_sessions.room_id')
            ->whereKeyNot($session->id)
            ->orderBy('group_sessions.starts_at')
            ->toBase()
            ->get(['group_sessions.room_id', 'group_sessions.starts_at', 'group_sessions.ends_at', 'groups.code as group_code', 'subjects.code as subject_code'])
            ->each(function (object $row) use (&$busy, $session): void {
                $busy[$row->room_id] ??= [
                    'label' => 'Ocupado',
                    'detail' => "{$row->subject_code} {$row->group_code} · ".self::shortWhen($session->day, $row->starts_at, $row->ends_at),
                    'note' => 'HC-02: no se puede elegir.',
                ];
            });

        return $busy;
    }

    /**
     * HC-11: el primer bloqueo vigente (del periodo o de todos) de cada salón del campus a la hora de la sesión.
     *
     * @return array<string, array{reason: string, when: string, short: string}>
     */
    private function blocks(Group $group, GroupSession $session): array
    {
        $blocks = [];

        $this->overlapping(RoomBlock::query(), 'room_blocks', $session)
            ->join('rooms', 'rooms.id', '=', 'room_blocks.room_id')
            ->where('rooms.campus_id', $group->campus_id)
            ->where(fn (Builder $query) => $query->whereNull('room_blocks.term_id')->orWhere('room_blocks.term_id', $group->term_id))
            ->orderBy('room_blocks.starts_at')
            ->get(['room_blocks.room_id', 'room_blocks.starts_at', 'room_blocks.ends_at', 'room_blocks.reason'])
            ->each(function (RoomBlock $block) use (&$blocks, $session): void {
                $blocks[$block->room_id] ??= [
                    'reason' => (string) ($block->reason ?: 'Bloqueado'),
                    'when' => Minutes::dayName($session->day).' de '.substr($block->starts_at, 0, 5).' a '.substr($block->ends_at, 0, 5),
                    'short' => self::shortWhen($session->day, $block->starts_at, $block->ends_at),
                ];
            });

        return $blocks;
    }

    /**
     * HC-10: los préstamos vivos del campus que tocan la sesión, en una sola lectura: los del grupo por salón (`own`,
     * ordenados por inicio) y, de los de otros grupos, quién aparta cada salón (`taken`): «Apartado», «Pedido para
     * AED-1026 3A · SC» o «Prestado a …», con el departamento que lo pidió.
     *
     * @return array{own: array<string, list<array{start: int, end: int, accepted: bool}>>, taken: array<string, array{label: string, detail: string, note: string}>}
     */
    private function loans(Group $group, GroupSession $session): array
    {
        $own = [];
        $taken = [];

        $this->overlapping(InterdepartmentalAgreement::query(), 'interdepartmental_agreements', $session)
            ->leftJoin('groups', 'groups.id', '=', 'interdepartmental_agreements.group_id')
            ->leftJoin('subjects', 'subjects.id', '=', 'groups.subject_id')
            ->join('departments', 'departments.id', '=', 'interdepartmental_agreements.from_department_id')
            ->where('interdepartmental_agreements.kind', AgreementKind::RoomLoan->value)
            ->where('interdepartmental_agreements.term_id', $group->term_id)
            ->where('interdepartmental_agreements.campus_id', $group->campus_id)
            ->whereIn('interdepartmental_agreements.status', AgreementStatus::liveValues())
            ->orderBy('interdepartmental_agreements.starts_at')
            ->toBase()
            ->get([
                'interdepartmental_agreements.group_id', 'interdepartmental_agreements.room_id', 'interdepartmental_agreements.status',
                'interdepartmental_agreements.starts_at', 'interdepartmental_agreements.ends_at',
                'groups.code as group_code', 'subjects.code as subject_code', 'departments.code as department_code',
            ])
            ->each(function (object $loan) use (&$own, &$taken, $group): void {
                $accepted = $loan->status === AgreementStatus::Accepted->value;

                if ($loan->group_id === $group->id) {
                    $own[$loan->room_id][] = ['start' => Minutes::of($loan->starts_at), 'end' => Minutes::of($loan->ends_at), 'accepted' => $accepted];

                    return;
                }

                $taken[$loan->room_id] ??= [
                    'label' => 'Apartado',
                    'detail' => ($accepted ? 'Prestado a ' : 'Pedido para ').trim("{$loan->subject_code} {$loan->group_code}")." · {$loan->department_code}",
                    'note' => 'HC-10: el salón ya está apartado para otro grupo; no se puede elegir.',
                ];
            });

        return ['own' => $own, 'taken' => $taken];
    }

    /**
     * Lo de la tabla que cae el día de la sesión y se cruza con sus horas.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    private function overlapping(Builder $query, string $table, GroupSession $session): Builder
    {
        return $query
            ->where("{$table}.day", $session->day)
            ->where("{$table}.starts_at", '<', $session->ends_at)
            ->where("{$table}.ends_at", '>', $session->starts_at);
    }

    /**
     * «mar 15:00–16:00».
     */
    private static function shortWhen(int $day, string $startsAt, string $endsAt): string
    {
        return mb_strtolower(Minutes::dayLabel($day, short: true)).' '.substr($startsAt, 0, 5).'–'.substr($endsAt, 0, 5);
    }

    /**
     * «20263», «20263 y 20261», «20263, 20261 y 20253».
     *
     * @param  list<string>  $items
     */
    private static function sentence(array $items): string
    {
        $last = array_pop($items);

        return $items === [] ? (string) $last : implode(', ', $items)." y {$last}";
    }
}
