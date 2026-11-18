<?php

namespace App\Modules\Solver\Queries;

use App\Modules\Core\Support\WeekGrid;
use App\Modules\Core\Support\WeekTime;
use App\Modules\Scheduling\Enums\SessionKind;
use App\Modules\Solver\Rules\ApplyPlan;

/**
 * Una corrida contra la carga como estaba al lanzarla (igual a hoy mientras siga vigente, SV-02), lista para la pantalla
 * de corridas (tarea 6.10, variante C «Semana con cambios»). La arma RunComparisonQuery; puro desde ahí.
 *
 * Cada sesión es un registro con su estado y sus dos lugares (`now`, como quedaría al aceptar; `was`, como está):
 * - `new` (solo `now`), `moved` (de un día u hora a otro), `room` (misma hora, otro salón), `removed` (solo `was`);
 * - `same` (un grupo que se escribe y conserva esa sesión, o uno cuyo horario propuesto es igual al de hoy);
 * - `kept` (un grupo que se queda como está: sin acomodar, con violación dura o que chocaría, ApplyPlan);
 * - `fixed` (un grupo de otra carga que la corrida tomó como fijo).
 *
 * «Ver por» es un lente: un grupo (`grupo:<id>`), un docente (`docente:<id>`), un salón (`salon:<id>`) o un alumno tipo
 * (`tipo:<plan>:<semestre>:<campus>`, los grupos del mismo plan, semestre y campus). El campus filtra los lentes y las
 * cifras; `career` (la vista del coordinador) deja solo los grupos movibles de su carrera.
 */
final readonly class RunComparison
{
    public const array VIEWS = ['tipo' => 'Alumno tipo', 'docente' => 'Docente', 'salon' => 'Salón', 'grupo' => 'Grupo'];

    public const array MODES = ['despues' => 'Con la corrida', 'antes' => 'Como está hoy', 'ambas' => 'Las dos'];

    private const array CHANGES = ['new', 'moved', 'room', 'removed'];

    private const array DAYS = [1 => 'Lun', 2 => 'Mar', 3 => 'Mié', 4 => 'Jue', 5 => 'Vie', 6 => 'Sáb', 7 => 'Dom'];

    /**
     * @param  array<string, array<string, mixed>>  $groups  por id: título, materia, campus, docente, plan, semestre, carrera, departamento, si se mueve, estado del plan y motivos
     * @param  list<array{key: string, group_id: string, state: string, now: ?array<string, mixed>, was: ?array<string, mixed>}>  $records
     * @param  array<string, array{code: string, building: string}>  $rooms
     * @param  array<string, string>  $teachers
     */
    public function __construct(
        public array $groups,
        public array $records,
        public array $rooms,
        public array $teachers,
        public ApplyPlan $plan,
    ) {}

    /**
     * Los grupos movibles que se ven (de la carrera, en la vista del coordinador), en un campus o en todos.
     *
     * @return array<string, array<string, mixed>>
     */
    public function movable(?string $campus = null): array
    {
        return array_filter($this->groups, fn (array $group): bool => $group['movable'] && ($campus === null || $group['campus'] === $campus));
    }

    /**
     * Cuántas sesiones cambian, de cuántas, y en cuántos grupos.
     *
     * @return array{changes: int, total: int, groups: int, new: int, moved: int, room: int, removed: int, same: int}
     */
    public function stats(?string $campus = null): array
    {
        $movable = $this->movable($campus);
        $counts = ['changes' => 0, 'total' => 0, 'groups' => 0, 'new' => 0, 'moved' => 0, 'room' => 0, 'removed' => 0, 'same' => 0];
        $changed = [];

        foreach ($this->records as $record) {
            if (! isset($movable[$record['group_id']])) {
                continue;
            }

            if ($record['now'] !== null) {
                $counts['total']++;
            }

            if (in_array($record['state'], self::CHANGES, true)) {
                $counts['changes']++;
                $counts[$record['state']]++;
                $changed[$record['group_id']] = true;
            } elseif ($record['state'] === 'same') {
                $counts['same']++;
            }
        }

        $counts['groups'] = count($changed);

        return $counts;
    }

    /**
     * Las sesiones que cambian en cada campus, por código.
     *
     * @return array<string, int>
     */
    public function changesByCampus(): array
    {
        $counts = [];

        foreach ($this->movable() as $group) {
            $counts[$group['campus']] ??= 0;
        }

        foreach ($this->records as $record) {
            $group = $this->groups[$record['group_id']];

            if ($group['movable'] && in_array($record['state'], self::CHANGES, true)) {
                $counts[$group['campus']]++;
            }
        }

        ksort($counts);

        return $counts;
    }

    /**
     * Los grupos que se quedan como están, con sus motivos.
     *
     * @return list<array{group: array<string, mixed>, reasons: list<string>}>
     */
    public function kept(?string $campus = null): array
    {
        $rows = [];

        foreach ($this->movable($campus) as $group) {
            if ($group['status'] === ApplyPlan::KEPT) {
                $rows[] = ['group' => $group, 'reasons' => $group['reasons']];
            }
        }

        usort($rows, fn (array $a, array $b): int => $a['group']['title'] <=> $b['group']['title']);

        return $rows;
    }

    /**
     * Los problemas de entrada por corregir (ApplyPlan::$inputProblems: HC-07, HC-08, HC-13): no retienen al grupo, que
     * se escribe, y se avisan sin bloquear. Cada uno con los títulos de sus grupos de la carga.
     *
     * @return list<array{rule: string, message: string, groups: list<string>}>
     */
    public function inputProblems(): array
    {
        $movable = $this->movable();
        $rows = [];

        foreach ($this->plan->inputProblems as $problem) {
            $titles = array_values(array_map(fn (string $id): string => $movable[$id]['title'], array_filter($problem['group_ids'], fn (string $id): bool => isset($movable[$id]))));
            sort($titles);
            $rows[] = ['rule' => $problem['rule'], 'message' => $problem['message'], 'groups' => $titles];
        }

        return $rows;
    }

    /**
     * Las opciones de un «Ver por» con las sesiones que cambian en cada una.
     *
     * @return list<array{id: string, label: string, changes: int}>
     */
    public function lensOptions(string $view, ?string $campus = null): array
    {
        $movable = $this->movable($campus);
        $options = [];

        foreach ($movable as $group) {
            foreach ($this->lensIdsOf($view, $group) as $id => $label) {
                $options[$id] ??= ['id' => $id, 'label' => $label, 'changes' => 0, 'sort' => $this->sortKey($view, $group, $label)];
            }
        }

        foreach ($this->records as $record) {
            $group = $this->groups[$record['group_id']];

            if (! isset($movable[$group['id']]) || ! in_array($record['state'], self::CHANGES, true)) {
                continue;
            }

            foreach (array_keys($this->lensIdsOf($view, $group, $record)) as $id) {
                if (isset($options[$id])) {
                    $options[$id]['changes']++;
                }
            }
        }

        usort($options, fn (array $a, array $b): int => $a['sort'] <=> $b['sort']);

        return array_map(fn (array $option): array => ['id' => $option['id'], 'label' => $option['label'], 'changes' => $option['changes']], $options);
    }

    /**
     * El lente pedido si está entre las opciones; si no, el de más cambios (el primero en empate).
     *
     * @param  list<array{id: string, label: string, changes: int}>  $options
     * @return array{id: string, label: string, changes: int}|null
     */
    public static function chooseLens(array $options, string $wanted): ?array
    {
        foreach ($options as $option) {
            if ($option['id'] === $wanted) {
                return $option;
            }
        }

        $best = null;

        foreach ($options as $option) {
            if ($best === null || $option['changes'] > $best['changes']) {
                $best = $option;
            }
        }

        return $best;
    }

    /**
     * Los grupos movibles del lente con lo que cambia en cada uno, para la bandeja.
     *
     * @return list<array{group: array<string, mixed>, new: int, moved: int, same: int, changed: bool, kept: bool}>
     */
    public function tray(string $lensId, ?string $campus = null): array
    {
        $movable = $this->movable($campus);
        $rows = [];

        foreach ($this->records as $record) {
            $group = $this->groups[$record['group_id']];

            if (! isset($movable[$group['id']]) || ! $this->inLens($lensId, $group, $record)) {
                continue;
            }

            $row = $rows[$group['id']] ?? ['group' => $group, 'new' => 0, 'moved' => 0, 'same' => 0, 'changed' => false, 'kept' => $group['status'] === ApplyPlan::KEPT];
            $row[match ($record['state']) {
                'new' => 'new', 'moved', 'room', 'removed' => 'moved', default => 'same'
            }]++;
            $row['changed'] = $row['new'] + $row['moved'] > 0;
            $rows[$group['id']] = $row;
        }

        foreach ($movable as $group) {
            if (! isset($rows[$group['id']]) && $this->inLens($lensId, $group, null)) {
                $rows[$group['id']] = ['group' => $group, 'new' => 0, 'moved' => 0, 'same' => 0, 'changed' => false, 'kept' => $group['status'] === ApplyPlan::KEPT];
            }
        }

        $rows = array_values($rows);
        usort($rows, fn (array $a, array $b): int => [$a['group']['subject_code'], $a['group']['code']] <=> [$b['group']['subject_code'], $b['group']['code']]);

        return $rows;
    }

    /**
     * Lo que cambia en un grupo, renglón por sesión, para la barra del grupo elegido.
     *
     * @return list<array{state: string, label: string, was: ?string, now: ?string}>
     */
    public function linesOf(string $groupId): array
    {
        $lines = [];

        foreach ($this->records as $record) {
            if ($record['group_id'] !== $groupId || ! in_array($record['state'], self::CHANGES, true)) {
                continue;
            }

            $lines[] = [
                'state' => $record['state'],
                'label' => match ($record['state']) {
                    'new' => 'Nueva', 'moved' => 'Movida', 'room' => 'Otro salón', default => 'Se quita'
                },
                'was' => $record['was'] === null ? null : $this->place($record['was']),
                'now' => $record['now'] === null ? null : $this->place($record['now']),
            ];
        }

        return $lines;
    }

    /**
     * Las sesiones del lente en la semana, con su estado y su marca, como las acomoda WeekGrid: `despues` (como quedaría),
     * `antes` (como está) o `ambas` (como quedaría, con el lugar anterior de lo que se mueve). El lugar anterior se ve
     * en `despues` solo del grupo elegido, en la vista por grupo o cuando la sesión sale del salón del lente.
     *
     * @return list<array<string, mixed>>
     */
    public function weekItems(string $lensId, string $mode, ?string $selected, ?string $campus = null): array
    {
        $view = explode(':', $lensId)[0];
        $items = [];
        $chains = $this->chains($lensId, $campus);

        foreach ($this->records as $record) {
            $group = $this->groups[$record['group_id']];

            if (! $this->visible($group, $campus) || ! $this->inLens($lensId, $group, $record)) {
                continue;
            }

            $now = $record['now'] !== null && $this->positionInLens($lensId, $record['now']) ? $record['now'] : null;
            $was = $record['was'] !== null && $this->positionInLens($lensId, $record['was']) ? $record['was'] : null;

            if ($mode === 'antes') {
                if ($was !== null) {
                    $items[] = $this->item($record, $group, $was, false, 'antes', $chains, $selected, $view);
                }

                continue;
            }

            if ($now !== null) {
                $items[] = $this->item($record, $group, $now, false, $mode, $chains, $selected, $view);
            }

            $leaves = in_array($record['state'], ['moved', 'removed'], true) || ($record['state'] === 'room' && $now === null);

            if ($was !== null && $leaves && ($mode === 'ambas' || $selected === $group['id'] || $view === 'grupo' || $now === null)) {
                $items[] = $this->item($record, $group, $was, true, $mode, $chains, $selected, $view);
            }
        }

        return $items;
    }

    /**
     * Las sesiones que cambian en cada día de la semana que se ve.
     *
     * @param  list<array<string, mixed>>  $items
     * @return array<int, int>
     */
    public static function changesByDay(array $items): array
    {
        $counts = [];

        foreach ($items as $item) {
            $counts[$item['day']] ??= 0;

            if (! $item['ghost'] && $item['change']) {
                $counts[$item['day']]++;
            }
        }

        return $counts;
    }

    /**
     * Una sesión del lente en la semana.
     *
     * @param  array<string, mixed>  $record
     * @param  array<string, mixed>  $group
     * @param  array<string, mixed>  $at
     * @param  array<string, int>  $chains
     * @return array<string, mixed>
     */
    private function item(array $record, array $group, array $at, bool $ghost, string $mode, array $chains, ?string $selected, string $view): array
    {
        $state = $record['state'];
        $room = $at['room_id'] === null ? null : ($this->rooms[$at['room_id']]['code'] ?? null);
        $mark = $ghost ? null : $this->mark($record, $group, $mode);
        $where = WeekTime::day($at['day']).' de '.WeekTime::clock($at['starts_at']).' a '.WeekTime::clock($at['ends_at']);
        $stateText = match (true) {
            $ghost => 'lugar anterior; pasa '.($record['now'] === null ? 'a ninguna hora' : 'al '.$this->place($record['now'])),
            $mode === 'antes' && $state === 'moved' => 'se mueve al '.$this->place($record['now']),
            $mode === 'antes' && $state === 'room' => 'cambia al salón '.($this->rooms[$record['now']['room_id'] ?? '']['code'] ?? 'sin salón'),
            $state === 'new' => 'nueva',
            $state === 'moved' => 'movida desde el '.$this->place($record['was']),
            $state === 'room' => 'otro salón, antes '.($this->rooms[$record['was']['room_id'] ?? '']['code'] ?? 'sin salón'),
            $state === 'removed' => 'se quita',
            $state === 'kept' => 'se queda como está',
            $state === 'fixed' => 'fija, de '.$group['department_code'],
            default => 'sin cambio',
        };

        return [
            'id' => $record['key'].($ghost ? ':antes' : ''),
            'group_id' => $group['id'],
            'day' => $at['day'],
            'starts_at' => $at['starts_at'],
            'ends_at' => $at['ends_at'],
            'hours' => WeekGrid::range($at['starts_at'], $at['ends_at']),
            'kind' => SessionKind::tryFrom($at['kind']) ?? SessionKind::Teoria,
            'title' => $group['title'],
            'room' => $room,
            'teacher' => $group['teacher_short'],
            'campus' => $group['campus'],
            'state' => $state,
            'ghost' => $ghost,
            'change' => in_array($state, self::CHANGES, true) && ! ($mode === 'antes' && $state === 'removed'),
            'mark' => $mark,
            'chain' => $group['movable'] ? ($chains[$group['subject_code']] ?? null) : null,
            'selectable' => $group['movable'],
            'selected' => $selected === $group['id'],
            'muted' => $selected !== null && $selected !== $group['id'] && $group['movable'],
            'show_campus' => $view === 'docente',
            'show_teacher' => $view === 'salon',
            'label' => "{$group['title']}, {$group['subject_name']}, {$where}".($room ? ", {$room}" : '').", {$stateText}",
            'moves_to' => $ghost && $record['now'] !== null ? $this->place($record['now']) : null,
        ];
    }

    /**
     * La marca de una sesión: «Nueva», de dónde viene («↩ Mar 12:00» o el salón anterior), a dónde va (en «Como está
     * hoy»), «Se queda» o «Fija · CB».
     *
     * @param  array<string, mixed>  $record
     * @param  array<string, mixed>  $group
     * @return array{tone: string, icon: string, text: string}|null
     */
    private function mark(array $record, array $group, string $mode): ?array
    {
        $roomOf = fn (?array $at): string => $at === null || $at['room_id'] === null ? 'sin salón' : ($this->rooms[$at['room_id']]['code'] ?? 'sin salón');

        return match (true) {
            $record['state'] === 'fixed' => ['tone' => 'fix', 'icon' => 'o-lock-closed', 'text' => "Fija · {$group['department_code']}"],
            $record['state'] === 'kept' => ['tone' => 'kept', 'icon' => 'o-x-circle', 'text' => 'Se queda'],
            $mode === 'antes' && $record['state'] === 'moved' => ['tone' => 'mov', 'icon' => 'o-arrow-right', 'text' => $this->short($record['now'])],
            $mode === 'antes' && $record['state'] === 'room' => ['tone' => 'mov', 'icon' => 'o-arrow-right', 'text' => $roomOf($record['now'])],
            $mode === 'antes' && $record['state'] === 'removed' => ['tone' => 'mov', 'icon' => 'o-minus-circle', 'text' => 'Se quita'],
            $record['state'] === 'new' => ['tone' => 'new', 'icon' => 'o-plus-circle', 'text' => 'Nueva'],
            $record['state'] === 'moved' => ['tone' => 'mov', 'icon' => 'o-arrow-uturn-left', 'text' => $this->short($record['was'])],
            $record['state'] === 'room' => ['tone' => 'mov', 'icon' => 'o-arrow-uturn-left', 'text' => $roomOf($record['was'])],
            default => null,
        };
    }

    /**
     * El color de cada materia de los grupos movibles del lente (un token `chain-N`), por clave.
     *
     * @return array<string, int>
     */
    public function chains(string $lensId, ?string $campus = null): array
    {
        $codes = [];

        foreach ($this->records as $record) {
            $group = $this->groups[$record['group_id']];

            if ($group['movable'] && $this->visible($group, $campus) && $this->inLens($lensId, $group, $record)) {
                $codes[$group['subject_code']] = true;
            }
        }

        $codes = array_keys($codes);
        sort($codes);

        return array_flip($codes);
    }

    /**
     * Un grupo se ve si es del campus del filtro (o con «Todos»).
     *
     * @param  array<string, mixed>  $group
     */
    private function visible(array $group, ?string $campus): bool
    {
        return $campus === null || $group['campus'] === $campus;
    }

    /**
     * Si el grupo (o, en la vista por salón, alguno de los lugares de la sesión) es del lente.
     *
     * @param  array<string, mixed>  $group
     * @param  array<string, mixed>|null  $record
     */
    private function inLens(string $lensId, array $group, ?array $record): bool
    {
        [$view, $key] = array_pad(explode(':', $lensId, 2), 2, '');

        return match ($view) {
            'grupo' => $group['id'] === $key,
            'docente' => $group['teacher_id'] === $key,
            'tipo' => "{$group['plan_id']}:{$group['semester']}:{$group['campus']}" === $key,
            'salon' => $record !== null && (($record['now']['room_id'] ?? null) === $key || ($record['was']['room_id'] ?? null) === $key),
            default => false,
        };
    }

    /**
     * En la vista por salón, cada lugar de la sesión cuenta solo si es en ese salón.
     *
     * @param  array<string, mixed>  $at
     */
    private function positionInLens(string $lensId, array $at): bool
    {
        [$view, $key] = array_pad(explode(':', $lensId, 2), 2, '');

        return $view !== 'salon' || $at['room_id'] === $key;
    }

    /**
     * Las opciones de un «Ver por» a las que pertenece un grupo (o una sesión, en la vista por salón).
     *
     * @param  array<string, mixed>  $group
     * @param  array<string, mixed>|null  $record
     * @return array<string, string>
     */
    private function lensIdsOf(string $view, array $group, ?array $record = null): array
    {
        return match ($view) {
            'grupo' => ["grupo:{$group['id']}" => "{$group['title']} · {$group['campus']} · {$group['subject_name']}"],
            'docente' => $group['teacher_id'] === null ? [] : ["docente:{$group['teacher_id']}" => $group['teacher'] ?? 'Sin docente'],
            'tipo' => ["tipo:{$group['plan_id']}:{$group['semester']}:{$group['campus']}" => "{$group['plan_code']} · Semestre {$group['semester']} · {$group['campus']}"],
            'salon' => $this->roomLenses($group, $record),
            default => [],
        };
    }

    /**
     * @param  array<string, mixed>  $group
     * @param  array<string, mixed>|null  $record
     * @return array<string, string>
     */
    private function roomLenses(array $group, ?array $record): array
    {
        $records = $record === null ? array_filter($this->records, fn (array $each): bool => $each['group_id'] === $group['id']) : [$record];
        $ids = [];

        foreach ($records as $each) {
            foreach ([$each['now']['room_id'] ?? null, $each['was']['room_id'] ?? null] as $roomId) {
                if ($roomId !== null && isset($this->rooms[$roomId])) {
                    $ids["salon:{$roomId}"] = "{$this->rooms[$roomId]['code']} · {$this->rooms[$roomId]['building']}";
                }
            }
        }

        return $ids;
    }

    /**
     * @param  array<string, mixed>  $group
     * @return list<int|string>
     */
    private function sortKey(string $view, array $group, string $label): array
    {
        return match ($view) {
            'tipo' => [$group['career_code'], $group['campus'], $group['semester'], $label],
            'docente' => [$group['teacher_sort'] ?? $label, $label],
            default => [$label],
        };
    }

    /**
     * «miércoles 10:00–12:00 · LC-1».
     *
     * @param  array<string, mixed>  $at
     */
    private function place(array $at): string
    {
        $room = $at['room_id'] === null ? '' : ' · '.($this->rooms[$at['room_id']]['code'] ?? '');

        return WeekTime::day($at['day']).' '.WeekGrid::range($at['starts_at'], $at['ends_at']).$room;
    }

    /**
     * «Mar 12:00».
     *
     * @param  array<string, mixed>|null  $at
     */
    private function short(?array $at): string
    {
        return $at === null ? '' : (self::DAYS[$at['day']] ?? (string) $at['day']).' '.WeekTime::clock($at['starts_at']);
    }

    /**
     * «Lun» de un día.
     */
    public static function dayShort(int $day): string
    {
        return self::DAYS[$day] ?? (string) $day;
    }
}
