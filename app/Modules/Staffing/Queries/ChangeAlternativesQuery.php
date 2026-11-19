<?php

namespace App\Modules\Staffing\Queries;

use App\Modules\Core\Models\Modality;
use App\Modules\Core\Support\WeekGrid;
use App\Modules\Core\Support\WeekTime;
use App\Modules\Facilities\Models\Room;
use App\Modules\Scheduling\Enums\GroupStatus;
use App\Modules\Scheduling\Models\Group;
use App\Modules\Scheduling\Models\GroupSession;
use App\Modules\Scheduling\Validation\SessionCandidate;
use App\Modules\Staffing\Actions\ChangeRequests\AssessChangeImpact;
use App\Modules\Staffing\Enums\ChangeRequestKind;
use App\Modules\Staffing\Enums\ChangeRequestStatus;
use App\Modules\Staffing\Models\ChangeRequest;
use App\Modules\Staffing\Support\ChangeAlternatives;
use App\Modules\Staffing\Support\SessionLabels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Lo que pinta «Impacto y alternativas» en el detalle de una solicitud de cambio (tarea 8.7, maqueta A «Pestañas»):
 * «Lo pedido» (el impacto de CR-01) y una opción por alternativa del motor (CR-02), cada una con su veredicto (choques
 * duros o puntaje), qué tan cerca queda de lo pedido, la cadena de movimientos (cada grupo con sus sesiones de antes y
 * de después: «Movida», «Choca», «Nueva», «Quitada», «Sin cambio»), los avisos que deja, los docentes que cambian y la
 * semana del alumno tipo del grupo de la solicitud con la opción puesta.
 *
 * El puntaje de una alternativa es lo que agrega al de hoy sobre la misma entrada del motor (`score - score_before`):
 * menos es mejor, y uno negativo mejora el horario de hoy. Lo pedido no tiene puntaje (el motor no lo evalúa).
 *
 * Solo lee: la propuesta de lo pedido sale de AssessChangeImpact::proposal(), y la semana se arma con los grupos del
 * mismo periodo, modalidad, campus, plan y semestre que el de la solicitud (el alumno tipo de HC-03).
 */
final class ChangeAlternativesQuery
{
    public const string PEDIDO = 'pedido';

    public function __construct(private readonly AssessChangeImpact $assess) {}

    /**
     * Las opciones de la solicitud y la elegida para el panel (`$selected`: 'pedido', el id de una alternativa o '' para
     * la de omisión: la primera alternativa si lo pedido deja choques duros, si no lo pedido; ya decidida, la elegida).
     *
     * @return array{assessable: bool, searched: bool, items: int, message: ?string, computedAt: ?Carbon, chosen: ?array<string, mixed>, tabs: list<array<string, mixed>>, selected: ?array<string, mixed>, pedidoHard: int, engineLine: ?string, moved: list<string>}
     */
    public function for(ChangeRequest $request, string $selected = ''): array
    {
        $request = (clone $request)->setRelation('group', Group::query()
            ->with(['subject:id,code,name', 'teacher:id,name', 'department:id,code', 'ownerCareer:id,code', 'campus:id,code,name', 'sessions.room:id,code'])
            ->whereKey($request->group_id)->firstOrFail());
        $alternatives = $request->alternatives;
        $items = array_values($alternatives['items'] ?? []);
        $impact = $request->impact ?? [];
        $assessable = (bool) ($impact['assessable'] ?? AssessChangeImpact::isAssessable($request));
        $pedidoHard = array_sum(array_map(fn (array $rule): int => count($rule['messages'] ?? []), $impact['hard'] ?? []));
        $chosen = ChangeAlternatives::chosen($alternatives);

        $groups = $this->groups($request, $items);
        $key = match (true) {
            $chosen !== null && $request->status !== ChangeRequestStatus::Pending => $chosen['id'],
            $selected === self::PEDIDO => self::PEDIDO,
            $selected !== '' && collect($items)->contains('id', $selected) => $selected,
            $pedidoHard > 0 && $items !== [] => $items[0]['id'],
            default => self::PEDIDO,
        };

        $tabs = [$this->pedidoTab($impact, $pedidoHard)];

        foreach ($items as $item) {
            $tabs[] = $this->itemTab($alternatives, $item);
        }

        $option = match (true) {
            ! $assessable => null,
            $key === self::PEDIDO => $this->pedido($request, $impact, $pedidoHard, $groups),
            default => $this->alternative($request, collect($items)->firstWhere('id', $key), $alternatives, $groups),
        };

        $computedAt = isset($alternatives['computed_at']) ? Carbon::parse($alternatives['computed_at'])->timezone(config('app.timezone')) : null;
        $moved = collect($items)->flatMap(fn (array $item): array => $item['movable_group_ids'])->unique()
            ->map(fn (string $id): ?string => isset($groups[$id]) ? ChangeAlternatives::label($groups[$id]) : null)->filter()->sort()->values()->all();

        return [
            'assessable' => $assessable,
            'searched' => $alternatives !== null,
            'items' => count($items),
            'message' => $alternatives['message'] ?? null,
            'computedAt' => $computedAt,
            'chosen' => $chosen === null ? null : [...$tabs[array_search($chosen['id'], array_column($tabs, 'key'), true)] ?? [], 'option' => $chosen],
            'tabs' => $tabs,
            'selected' => $option === null ? null : [...$option, 'key' => $key],
            'pedidoHard' => $pedidoHard,
            'engineLine' => $computedAt === null || $items === [] ? null : $this->engineLine($computedAt, $moved),
            'moved' => $moved,
        ];
    }

    /**
     * Lo que dice «Aprobada · alternativa A» en «Cambio de horario»: el horario completo de cada grupo de la elegida.
     *
     * @param  array<string, mixed>  $item
     * @return list<array{label: ?string, line: string}>
     */
    public function approvedLines(ChangeRequest $request, array $item): array
    {
        $groups = Group::query()->with('subject:id,code')->whereIn('id', array_column($item['groups'], 'group_id'))->get()->keyBy('id');
        $lines = [];

        foreach ($item['groups'] as $entry) {
            foreach (SessionLabels::snapshot($entry['sessions']) as $line) {
                $lines[] = [
                    'label' => $entry['requested'] ? null : (isset($groups[$entry['group_id']]) ? ChangeAlternatives::label($groups[$entry['group_id']]) : $entry['code']),
                    'line' => trim("{$line['days']} {$line['time']}".($line['room'] === null ? '' : " · {$line['room']}")),
                ];
            }
        }

        return $lines;
    }

    /**
     * El grupo de la solicitud y los de las alternativas, con su materia, docente, departamento y sesiones vivas.
     *
     * @param  list<array<string, mixed>>  $items
     * @return Collection<string, Group>
     */
    private function groups(ChangeRequest $request, array $items): Collection
    {
        $ids = collect($items)->flatMap(fn (array $item): array => [...array_column($item['groups'], 'group_id'), ...$item['movable_group_ids']])
            ->reject(fn (string $id): bool => $id === $request->group_id)->unique()->values();

        $others = $ids->isEmpty() ? collect() : Group::query()->with(['subject:id,code,name', 'teacher:id,name', 'department:id,code', 'sessions.room:id,code'])->whereIn('id', $ids)->get();

        return $others->keyBy('id')->put($request->group_id, $request->group);
    }

    /**
     * @param  array<string, mixed>  $impact
     * @return array<string, mixed>
     */
    private function pedidoTab(array $impact, int $hard): array
    {
        return ['key' => self::PEDIDO, 'letter' => null, 'name' => 'Lo pedido', 'short' => 'Pedido', 'hard' => $hard, 'score' => null, 'soft' => count($impact['soft'] ?? [])];
    }

    /**
     * @param  array<string, mixed>  $alternatives
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    private function itemTab(array $alternatives, array $item): array
    {
        $letter = (string) ChangeAlternatives::letter($alternatives, $item['id']);

        return ['key' => $item['id'], 'letter' => $letter, 'name' => "Alternativa {$letter}", 'short' => $letter, 'hard' => 0, 'score' => self::score($item), 'soft' => count($item['soft'] ?? [])];
    }

    /**
     * Lo que agrega al puntaje de hoy (menos es mejor).
     *
     * @param  array<string, mixed>  $item
     */
    public static function score(array $item): int
    {
        return (int) $item['score'] - (int) ($item['score_before'] ?? 0);
    }

    /**
     * «Lo pedido»: el impacto guardado (CR-01) y la propuesta como la simula el validador.
     *
     * @param  array<string, mixed>  $impact
     * @param  Collection<string, Group>  $groups
     * @return array<string, mixed>
     */
    private function pedido(ChangeRequest $request, array $impact, int $hard, Collection $groups): array
    {
        $group = $request->group;
        $rooms = Room::withTrashed()->whereIn('id', collect($this->assess->proposal($request))->pluck('roomId')->filter()->unique())->pluck('code', 'id');
        $sessions = array_map(fn (SessionCandidate $session): array => [
            'day' => $session->day,
            'starts_at' => WeekTime::hhmm(WeekTime::minutes($session->startsAt)),
            'ends_at' => WeekTime::hhmm(WeekTime::minutes($session->endsAt)),
            'kind' => $session->kind->value,
            'room_id' => $session->roomId,
            'room' => $session->roomId === null ? null : ($rooms[$session->roomId] ?? null),
            'replaces_session_id' => $session->replacesSessionId,
        ], $this->assess->proposal($request));
        $impactGroups = collect($impact['affected_groups'] ?? [])->keyBy('id');

        $entry = ['group_id' => $group->id, 'requested' => true, 'sessions' => $sessions];
        $colors = $this->colors($request, [$entry], $groups);

        return [
            'letter' => null,
            'name' => 'Lo pedido',
            'kind' => self::PEDIDO,
            'hardCount' => $hard,
            'hard' => array_map(fn (array $rule): array => [...$rule, 'groupChips' => collect($rule['groups'])->map(fn (string $id): ?array => $impactGroups->get($id))->filter()->values()->all()], $impact['hard'] ?? []),
            'soft' => $this->soft($impact['soft'] ?? []),
            'score' => null,
            'near' => null,
            'chain' => [$this->chainRow($request, $group, $entry, $hard > 0, $colors)],
            'teachers' => [],
            'week' => $this->week($request, [$group->id => $sessions], $hard > 0, $colors),
            'assessedAt' => isset($impact['assessed_at']) ? Carbon::parse($impact['assessed_at']) : null,
        ];
    }

    /**
     * Una alternativa del motor.
     *
     * @param  array<string, mixed>  $item
     * @param  array<string, mixed>  $alternatives
     * @param  Collection<string, Group>  $groups
     * @return array<string, mixed>
     */
    private function alternative(ChangeRequest $request, array $item, array $alternatives, Collection $groups): array
    {
        $letter = (string) ChangeAlternatives::letter($alternatives, $item['id']);
        $colors = $this->colors($request, $item['groups'], $groups);
        $chain = [];

        foreach ($item['groups'] as $entry) {
            if (isset($groups[$entry['group_id']])) {
                $chain[] = $this->chainRow($request, $groups[$entry['group_id']], $entry, false, $colors);
            }
        }

        $teachers = collect($chain)->filter(fn (array $row): bool => $row['teacher'] !== null)
            ->map(fn (array $row): array => ['name' => $row['teacher'], 'asked' => $row['requested'] && $request->teacher_id !== null])
            ->unique('name')->values()->all();

        return [
            'letter' => $letter,
            'name' => "Alternativa {$letter}",
            'kind' => $item['kind'],
            'hardCount' => 0,
            'hard' => [],
            'soft' => $this->soft($item['soft'] ?? []),
            'score' => self::score($item),
            'near' => $this->near($request, $item),
            'chain' => $chain,
            'teachers' => $teachers,
            'week' => $this->week($request, collect($item['groups'])->mapWithKeys(fn (array $entry): array => [$entry['group_id'] => $entry['sessions']])->all(), false, $colors),
            'assessedAt' => null,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $rules
     * @return list<array{rule: string, message: string}>
     */
    private function soft(array $rules): array
    {
        $soft = [];

        foreach ($rules as $rule) {
            foreach ($rule['messages'] ?? [$rule['message']] as $message) {
                $soft[] = ['rule' => $rule['rule'], 'message' => $message];
            }
        }

        return $soft;
    }

    /**
     * Qué tan cerca queda el grupo de la solicitud de lo que pidió: las mismas horas (y, si cambia, en qué salón) o
     * cuáles en lugar de cuáles.
     *
     * @param  array<string, mixed>  $item
     * @return array{same: bool, text: string, note: ?string}|null
     */
    private function near(ChangeRequest $request, array $item): ?array
    {
        $mine = collect($item['groups'])->firstWhere('requested', true);

        if ($mine === null) {
            return null;
        }

        $times = fn (array $sessions): array => collect($sessions)->map(fn (array $session): string => $session['day'].'|'.WeekTime::hhmm(WeekTime::minutes($session['starts_at'])).'|'.WeekTime::hhmm(WeekTime::minutes($session['ends_at'])))->sort()->values()->all();
        $live = $request->group->sessions->keyBy('id');
        $otherRoom = collect($mine['sessions'])->filter(fn (array $session): bool => $session['replaces_session_id'] !== null && isset($live[$session['replaces_session_id']]) && $live[$session['replaces_session_id']]->room_id !== $session['room_id'])->values()->all();
        $note = $otherRoom === [] ? null : 'En otro salón: '.ChangeAlternatives::line($otherRoom).'.';

        if ($request->kind === ChangeRequestKind::Room) {
            $asked = $request->proposed['room_id'] ?? null;
            $same = collect($mine['sessions'])->every(fn (array $session): bool => $session['room_id'] === $asked);

            return $same
                ? ['same' => true, 'text' => 'El salón que pidió', 'note' => null]
                : ['same' => false, 'text' => ChangeAlternatives::line($mine['sessions']).' en lugar del salón '.($request->proposed['room'] ?? 'que pidió'), 'note' => null];
        }

        $proposed = $request->proposed['sessions'] ?? [];

        if ($times($proposed) === $times($mine['sessions'])) {
            return ['same' => true, 'text' => 'Las horas que pidió', 'note' => $note];
        }

        return ['same' => false, 'text' => ChangeAlternatives::line(array_map(fn (array $session): array => [...$session, 'room' => null], $mine['sessions'])).' en lugar de '.ChangeAlternatives::line($proposed), 'note' => $note];
    }

    /**
     * Un grupo de la cadena: sus sesiones de antes a después, agrupadas por las mismas horas y el mismo salón, y las
     * que no cambian.
     *
     * @param  array<string, mixed>  $entry
     * @param  array<string, int|null>  $colors
     * @return array<string, mixed>
     */
    private function chainRow(ChangeRequest $request, Group $group, array $entry, bool $clashes, array $colors): array
    {
        $live = $group->sessions->keyBy('id');
        $snap = fn (GroupSession $session): array => ['day' => $session->day, 'starts_at' => substr((string) $session->starts_at, 0, 5), 'ends_at' => substr((string) $session->ends_at, 0, 5), 'room' => $session->room?->code];
        $moves = [];
        $same = [];
        $used = [];

        foreach ($entry['sessions'] as $session) {
            $now = ['day' => $session['day'], 'starts_at' => WeekTime::hhmm(WeekTime::minutes($session['starts_at'])), 'ends_at' => WeekTime::hhmm(WeekTime::minutes($session['ends_at'])), 'room' => $session['room'] ?? null];
            $was = isset($session['replaces_session_id'], $live[$session['replaces_session_id']]) ? $live[$session['replaces_session_id']] : null;

            if ($was !== null) {
                $used[$was->id] = true;
                $before = $snap($was);

                if ($before === $now) {
                    $same[] = $now;

                    continue;
                }

                $key = "{$before['starts_at']}|{$before['ends_at']}|{$before['room']}>{$now['starts_at']}|{$now['ends_at']}|{$now['room']}";
                $moves[$key] ??= ['tag' => $clashes ? 'Choca' : 'Movida', 'tone' => $clashes ? 'error' : 'warning', 'from' => [], 'to' => []];
                $moves[$key]['from'][] = $before;
                $moves[$key]['to'][] = $now;
            } else {
                $moves['new|'.count($moves)] = ['tag' => 'Nueva', 'tone' => 'info', 'from' => [], 'to' => [$now]];
            }
        }

        foreach ($live as $id => $session) {
            if (! isset($used[$id])) {
                $moves['gone|'.$id] = ['tag' => 'Quitada', 'tone' => 'neutral', 'from' => [$snap($session)], 'to' => []];
            }
        }

        $requested = $group->id === $request->group_id;

        return [
            'group_id' => $group->id,
            'label' => ChangeAlternatives::label($group),
            'subject' => $group->subject?->name,
            'teacher' => $group->teacher?->name,
            'requested' => $requested,
            'color' => $colors[$group->id] ?? null,
            'moves' => array_values(array_map(fn (array $move): array => [
                'tag' => $move['tag'],
                'tone' => $move['tone'],
                'was' => $move['from'] === [] ? null : ChangeAlternatives::line($move['from']),
                'now' => $move['to'] === [] ? null : ChangeAlternatives::line($move['to']),
            ], $moves)),
            'same' => $same === [] ? null : ChangeAlternatives::line($same),
        ];
    }

    /**
     * El color de cada grupo (el índice de su materia en `WeekGrid::CHAINS`): los del alumno tipo del grupo de la
     * solicitud por la clave de su materia y después los demás de la opción; los de otro departamento van sin color
     * (null, fijos).
     *
     * @param  list<array<string, mixed>>  $entries
     * @param  Collection<string, Group>  $groups
     * @return array<string, int|null>
     */
    private function colors(ChangeRequest $request, array $entries, Collection $groups): array
    {
        $colors = [];
        $subjects = [];
        $index = function (Group $group) use (&$subjects, $request): ?int {
            if ($group->department_id !== $request->department_id) {
                return null;
            }

            return $subjects[$group->subject_id] ??= count($subjects);
        };

        foreach ($this->cohort($request)->sortBy(fn (Group $group): string => (string) $group->subject?->code) as $group) {
            $colors[$group->id] = $index($group);
        }

        foreach ($entries as $entry) {
            $group = $groups[$entry['group_id']] ?? null;

            if ($group !== null && ! array_key_exists($group->id, $colors)) {
                $colors[$group->id] = $index($group);
            }
        }

        return $colors;
    }

    /** @var array<string, Collection<int, Group>> */
    private array $cohorts = [];

    /**
     * Los grupos activos del alumno tipo del grupo de la solicitud (HC-03): el mismo periodo, modalidad, campus, plan y
     * semestre, con sus sesiones.
     *
     * @return Collection<int, Group>
     */
    private function cohort(ChangeRequest $request): Collection
    {
        $group = $request->group;

        return $this->cohorts[$group->id] ??= $group->study_plan_id === null ? collect([$group]) : Group::query()
            ->with(['subject:id,code,name', 'department:id,code', 'sessions.room:id,code'])
            ->where('term_id', $group->term_id)
            ->where('modality_id', $group->modality_id)
            ->where('campus_id', $group->campus_id)
            ->where('study_plan_id', $group->study_plan_id)
            ->where('semester', $group->semester)
            ->where('status', '<>', GroupStatus::Cancelled->value)
            ->get();
    }

    /**
     * La semana del alumno tipo con la opción puesta: las sesiones nuevas de los grupos que cambian (movida, con de
     * dónde viene; nueva; o choque duro en lo pedido que choca) y las de los demás sin cambio, o fijas si son de otro
     * departamento. Solo las horas que ocupa alguna, en los días del perfil de la modalidad.
     *
     * @param  array<string, list<array<string, mixed>>>  $changes  las sesiones nuevas de cada grupo que cambia
     * @param  array<string, int|null>  $colors
     * @return array<string, mixed>
     */
    private function week(ChangeRequest $request, array $changes, bool $clashes, array $colors): array
    {
        $group = $request->group;
        $cohort = $this->cohort($request);
        $items = [];

        foreach ($cohort as $member) {
            $fixed = $member->department_id !== $request->department_id;
            $base = [
                'group_id' => $member->id,
                'title' => $member->subject?->code ?? $member->code,
                'label' => ChangeAlternatives::label($member),
                'color' => $fixed ? null : ($colors[$member->id] ?? null),
            ];

            if (! isset($changes[$member->id])) {
                foreach ($member->sessions as $session) {
                    $items[] = [...$base, ...self::slot($session->day, (string) $session->starts_at, (string) $session->ends_at), 'room' => $session->room?->code,
                        'state' => $fixed ? 'fixed' : 'same', 'mark' => $fixed ? 'Fija · '.($member->department?->code ?? '') : null, 'prev' => null];
                }

                continue;
            }

            $live = $member->sessions->keyBy('id');

            foreach ($changes[$member->id] as $session) {
                $slot = self::slot($session['day'], $session['starts_at'], $session['ends_at']);
                $was = isset($session['replaces_session_id'], $live[$session['replaces_session_id']]) ? $live[$session['replaces_session_id']] : null;
                $unchanged = $was !== null && $was->day === $slot['day'] && substr((string) $was->starts_at, 0, 5) === $slot['starts_at']
                    && substr((string) $was->ends_at, 0, 5) === $slot['ends_at'] && $was->room_id === $session['room_id'];

                $items[] = [...$base, ...$slot, 'room' => $session['room'] ?? null,
                    'state' => match (true) {
                        $unchanged => 'same',
                        $clashes => 'hard',
                        $was === null => 'new',
                        default => 'moved',
                    },
                    'mark' => null,
                    'prev' => $unchanged || $was === null ? null : SessionLabels::DAYS[$was->day].' '.WeekTime::clock((string) $was->starts_at).($was->room_id !== $session['room_id'] && $was->day === $slot['day'] && substr((string) $was->starts_at, 0, 5) === $slot['starts_at'] ? ' · '.($was->room?->code ?? 'sin salón') : ''),
                ];
            }
        }

        $grid = WeekGrid::of(Modality::withTrashed()->whereKey($group->modality_id)->firstOrFail()->profile);
        $blocks = $grid->blocks;

        if ($items !== []) {
            $first = min(array_map(fn (array $item): int => WeekTime::minutes($item['starts_at']), $items));
            $last = max(array_map(fn (array $item): int => WeekTime::minutes($item['ends_at']), $items));
            $blocks = array_values(array_filter($grid->blocks, fn (array $block): bool => WeekTime::minutes($block['ends_at']) > $first && WeekTime::minutes($block['starts_at']) < $last));
        }

        $offset = $blocks === [] ? 0 : array_search($blocks[0]['starts_at'], array_column($grid->blocks, 'starts_at'), true);
        $laid = array_map(fn (array $day): array => array_map(fn (array $item): array => [...$item, 'top' => $item['top'] - $offset], $day), $grid->lay($items));
        $changed = collect($items)->whereIn('state', ['moved', 'new', 'hard'])->sortBy(fn (array $item): string => $item['day'].$item['starts_at'])->values()->all();

        return [
            'label' => $this->cohortLabel($request),
            'days' => $grid->days,
            'blocks' => $blocks,
            'laid' => $laid,
            'changed' => $changed,
            'hasHard' => collect($items)->contains('state', 'hard'),
            'hasNew' => collect($items)->contains('state', 'new'),
            'hasFixed' => collect($items)->contains('state', 'fixed'),
        ];
    }

    /**
     * @return array{day: int, starts_at: string, ends_at: string, hours: string}
     */
    private static function slot(int $day, string $startsAt, string $endsAt): array
    {
        $start = WeekTime::hhmm(WeekTime::minutes($startsAt));
        $end = WeekTime::hhmm(WeekTime::minutes($endsAt));

        return ['day' => $day, 'starts_at' => $start, 'ends_at' => $end, 'hours' => WeekGrid::range($start, $end)];
    }

    /**
     * «ISC · 5.º semestre · Campus II»: el alumno tipo del grupo de la solicitud.
     */
    private function cohortLabel(ChangeRequest $request): string
    {
        $group = $request->group;

        return implode(' · ', array_filter([$group->ownerCareer?->code, $group->semester === null ? null : "{$group->semester}.º semestre", $group->campus?->name]));
    }

    /**
     * «Heurística · hoy a las 10:31. Mueven solo SCD-1015 · 5A y SCA-1026 · 5A; lo demás queda fijo.»
     *
     * @param  list<string>  $moved
     */
    private function engineLine(Carbon $computedAt, array $moved): string
    {
        $when = $computedAt->isToday() ? 'hoy a las '.$computedAt->format('G:i') : $computedAt->translatedFormat('j M').' a las '.$computedAt->format('G:i');
        $list = count($moved) <= 1 ? implode('', $moved) : implode(', ', array_slice($moved, 0, -1)).' y '.end($moved);

        return "Heurística · {$when}. Las alternativas mueven solo {$list}; lo demás queda fijo, y los grupos de otros departamentos nunca se mueven.";
    }
}
