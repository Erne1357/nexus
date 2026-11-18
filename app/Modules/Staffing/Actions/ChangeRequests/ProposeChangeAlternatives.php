<?php

namespace App\Modules\Staffing\Actions\ChangeRequests;

use App\Modules\AcademicLoad\Models\AcademicLoad;
use App\Modules\Core\Models\Modality;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\User;
use App\Modules\Facilities\Models\Room;
use App\Modules\Faculty\Models\Teacher;
use App\Modules\Scheduling\Actions\Sessions\ReplaceGroupSessions;
use App\Modules\Scheduling\Enums\SessionKind;
use App\Modules\Scheduling\Models\Group;
use App\Modules\Scheduling\Models\GroupSession;
use App\Modules\Scheduling\Queries\ScheduleStateQuery;
use App\Modules\Scheduling\Validation\Minutes;
use App\Modules\Scheduling\Validation\ScheduleState;
use App\Modules\Scheduling\Validation\SessionCandidate;
use App\Modules\Solver\Actions\ProposePlacements;
use App\Modules\Solver\Contract\SessionKind as ContractSessionKind;
use App\Modules\Solver\Contract\SessionSlot;
use App\Modules\Solver\Contract\SolverInput;
use App\Modules\Solver\Contract\SolverOutput;
use App\Modules\Solver\Engines\PhpHeuristic\PhpHeuristicEngine;
use App\Modules\Solver\Evaluation\ContractEvaluator;
use App\Modules\Solver\Evaluation\ContractState;
use App\Modules\Solver\Queries\BuildSolverInput;
use App\Modules\Solver\Rules\InputFingerprint;
use App\Modules\Staffing\Enums\ChangeRequestStatus;
use App\Modules\Staffing\Models\ChangeRequest;
use App\Modules\Staffing\Rules\ChangeRequestRules;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * CR-02 (tarea 8.7; supuesto por confirmar): pide al motor acomodos sin choques para una solicitud de cambio pendiente
 * cuya propuesta deja choques duros, y los guarda en `change_requests.alternatives` con bitácora. Decide el jefe del
 * departamento (ChangeRequestPolicy::decide, que esta Action vuelve a revisar). No escribe sesiones.
 *
 * Con la carga del departamento en asignación o ajustes, como para decidir, y una propuesta que se puede simular (CR-01)
 * y deja choques duros (la que no los deja se aprueba como se pidió). Pide a ProposePlacements, con la heurística en
 * PHP porque corre en la petición, hasta ALTERNATIVES acomodos:
 *
 * - (a) la cadena: la propuesta queda fija (las sesiones que simula CR-01, con el salón y el tipo de la sesión que cada
 *   una reemplaza) y se mueven los grupos con los que choca que son del mismo departamento, periodo y modalidad; una sola.
 * - (b) el grupo solo: se mueve el grupo pedido con todo lo demás fijo; las que falten para llegar a ALTERNATIVES. Una
 *   que lo deje como está no cuenta, y cada una lleva al grupo a horas distintas de las anteriores (ProposePlacements).
 *
 * Los grupos de otros departamentos nunca se mueven. Cada alternativa se revisa además con el validador de horarios
 * (AssessChangeImpact::simulateSessions, solo lo que agrega) y se descarta si deja un choque duro. Se guarda con las
 * sesiones resultantes de cada grupo que cambia (día, inicio, fin, tipo, salón y la sesión que reemplaza; las horas que
 * se pegan con el mismo tipo y salón se unen, como en el editor), los avisos blandos, el puntaje del motor (y el de hoy
 * sobre la misma entrada), los grupos y docentes afectados y la huella de la entrada (SV-02), que ChooseChangeAlternative
 * compara para decir «Esto cambió…». Si ninguna queda, se guarda NONE_FOUND: el jefe rechaza o, si la propuesta ya no
 * deja choques, la aprueba como se pidió.
 *
 * El motor corre fuera de los candados (hasta ProposePlacements::TIME_LIMIT_SECONDS por cada una de las dos llamadas);
 * guardar toma los de LocksChangeRequestScope, en su orden, y vuelve a revisar el estado. Pedirlas otra vez reemplaza
 * las anteriores.
 */
final class ProposeChangeAlternatives
{
    /** Cuántas alternativas se guardan como mucho. */
    public const int ALTERNATIVES = 3;

    public const string CHAIN = 'chain';

    public const string ALONE = 'alone';

    public const string NONE_FOUND = 'El motor no encontró acomodos sin choques.';

    public function __construct(
        private readonly AssessChangeImpact $assess,
        private readonly BuildSolverInput $build,
        private readonly ProposePlacements $placements,
        private readonly ContractEvaluator $evaluator,
        private readonly ScheduleStateQuery $states,
        private readonly LocksChangeRequestScope $locks,
    ) {}

    /**
     * @throws ValidationException si la solicitud no está pendiente, la carga no está en asignación o ajustes, o la
     *                             propuesta no se puede simular o no deja choques duros
     */
    public function handle(ChangeRequest $request, User $actor): ChangeRequest
    {
        Gate::forUser($actor)->authorize('decide', $request);

        $request->refresh();
        self::ensureDecidable($request, AcademicLoad::query()->ofDepartment($request->term_id, $request->modality_id, $request->department_id)->first());

        $proposal = $this->assess->proposal($request);

        if ($proposal === []) {
            throw ValidationException::withMessages(['status' => 'La solicitud solo trae texto: el motor no tiene un horario o un salón que acomodar.']);
        }

        $impact = $this->assess->simulate($request);

        if ($impact['hard'] === []) {
            throw ValidationException::withMessages(['status' => 'El cambio no deja choques duros: se puede aprobar como se pidió.']);
        }

        $alternatives = $this->alternatives($request, $proposal, $impact);

        return $this->locks->guard(fn (): ChangeRequest => DB::transaction(function () use ($request, $actor, $alternatives): ChangeRequest {
            self::ensureDecidable($request, $this->locks->lock($request));

            $request->forceFill(['alternatives' => $alternatives])->save();

            activity()
                ->performedOn($request)
                ->causedBy($actor)
                ->event('alternatives_proposed')
                ->withProperties(['engine' => $alternatives['engine'], 'alternatives' => array_column($alternatives['items'], 'kind', 'id')])
                ->log($alternatives['items'] === [] ? 'Pidió alternativas al motor; no encontró acomodos sin choques' : 'Pidió alternativas al motor para la solicitud de cambio');

            return $request;
        }));
    }

    /**
     * @throws ValidationException
     */
    private static function ensureDecidable(ChangeRequest $request, ?AcademicLoad $load): void
    {
        if ($request->status !== ChangeRequestStatus::Pending) {
            throw ValidationException::withMessages(['status' => 'La solicitud ya no está pendiente.']);
        }

        if (! ChangeRequestRules::acceptsRequests($load?->status)) {
            throw ValidationException::withMessages(['status' => 'La carga del departamento está en '.($load?->status->label() ?? 'ningún estado').': solo se deciden solicitudes con la carga en asignación o en ajustes.']);
        }
    }

    /**
     * @param  list<SessionCandidate>  $proposal
     * @param  array<string, mixed>  $impact
     * @return array{computed_at: string, engine: string, items: list<array<string, mixed>>, message: ?string, chosen: null}
     */
    private function alternatives(ChangeRequest $request, array $proposal, array $impact): array
    {
        $group = Group::query()->findOrFail($request->group_id);
        $term = Term::query()->findOrFail($group->term_id);
        $modality = Modality::withTrashed()->findOrFail($group->modality_id);
        $state = $this->states->forModality($term, $modality->id);
        $usesRooms = $state->profile($modality->id)->usesRooms;
        $pinned = array_map(fn (SessionCandidate $session): SessionSlot => new SessionSlot($session->day, Minutes::clock($session->startsAt), Minutes::clock($session->endsAt),
            ContractSessionKind::from($session->kind->value), $usesRooms ? $session->roomId : null), $proposal);

        $runs = [];
        $chain = $this->chainGroups($state, $group, $impact);

        if ($chain !== []) {
            $base = $this->build->forGroups($term, $modality, [$group->id, ...$chain], [], null, ProposePlacements::TIME_LIMIT_SECONDS, $state);

            foreach ($this->placements->propose($base, PhpHeuristicEngine::KEY, 1, [$group->id => $pinned]) as $output) {
                $runs[] = [self::CHAIN, $base, $output];
            }
        }

        $base = $this->build->forGroups($term, $modality, [$group->id], [], null, ProposePlacements::TIME_LIMIT_SECONDS, $state);

        foreach ($this->placements->propose($base, PhpHeuristicEngine::KEY, self::ALTERNATIVES - count($runs)) as $output) {
            $runs[] = [self::ALONE, $base, $output];
        }

        $items = [];
        $numbers = [];
        $before = [];

        foreach ($runs as [$kind, $input, $output]) {
            $before[spl_object_id($input)] ??= $this->evaluator->evaluate($input, ContractState::currentSessions($input))->softScore->total;
            $item = $this->item($group, $input, $output, $before[spl_object_id($input)]);

            if ($item !== null) {
                $numbers[$kind] = ($numbers[$kind] ?? 0) + 1;
                $items[] = ['id' => "{$kind}-{$numbers[$kind]}", 'kind' => $kind, ...$item];
            }
        }

        return [
            'computed_at' => now()->toIso8601String(),
            'engine' => PhpHeuristicEngine::KEY,
            'items' => $items,
            'message' => $items === [] ? self::NONE_FOUND : null,
            'chosen' => null,
        ];
    }

    /**
     * Los grupos con los que choca la propuesta que se pueden mover en la cadena: activos, del mismo periodo, modalidad y
     * departamento.
     *
     * @param  array<string, mixed>  $impact
     * @return list<string>
     */
    private function chainGroups(ScheduleState $state, Group $group, array $impact): array
    {
        $ids = [];

        foreach ($impact['affected_groups'] as $affected) {
            $other = $state->group((string) $affected['id']);

            if ($other !== null && $other->id !== $group->id && $other->isActive && $other->modalityId === $group->modality_id && $other->departmentId === $group->department_id) {
                $ids[] = $other->id;
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * Una alternativa para guardar, o null si el validador le encuentra un choque duro.
     *
     * @return array<string, mixed>|null
     */
    private function item(Group $requested, SolverInput $input, SolverOutput $output, int $scoreBefore): ?array
    {
        $slots = [];

        foreach ($output->sessions as $session) {
            $slots[$session->groupId][] = ['day' => $session->day, 'starts_at' => $session->startsAt, 'ends_at' => $session->endsAt, 'kind' => $session->kind->value, 'room_id' => $session->roomId];
        }

        $groups = Group::query()->with(['subject:id,code,name', 'teacher:id,name', 'sessions'])->whereIn('id', array_keys($slots))->get()->keyBy('id');
        $rooms = Room::withTrashed()->whereIn('id', array_filter(array_column(array_merge(...array_values($slots)), 'room_id')))->pluck('code', 'id');
        $entries = [];
        $candidates = [];

        foreach ($slots as $groupId => $groupSlots) {
            $group = $groups[$groupId];
            $joined = ReplaceGroupSessions::joined($groupSlots);

            if ($groupId !== $requested->id && self::sameAsLive($group->sessions, $joined)) {
                continue;
            }

            [$sessions, $removed] = self::paired($group->sessions, $joined);
            $candidates[$groupId] = array_map(fn (array $session): SessionCandidate => new SessionCandidate($session['day'], $session['starts_at'], $session['ends_at'],
                $session['room_id'], SessionKind::from($session['kind']), $session['replaces_session_id']), $sessions);
            $entries[] = [
                'group_id' => $group->id,
                'code' => $group->code,
                'subject' => $group->subject?->name,
                'teacher_id' => $group->teacher_id,
                'teacher' => $group->teacher?->name,
                'requested' => $groupId === $requested->id,
                'sessions' => array_map(fn (array $session): array => [...$session, 'room' => $session['room_id'] === null ? null : $rooms[$session['room_id']] ?? null], $sessions),
                'removed_session_ids' => $removed,
            ];
        }

        $check = $this->assess->simulateSessions($requested->term_id, $requested->modality_id, $candidates, fresh: false);

        if ($check['hard'] !== []) {
            return null;
        }

        usort($entries, fn (array $a, array $b): int => [! $a['requested'], $a['code']] <=> [! $b['requested'], $b['code']]);
        $others = array_values(array_filter($entries, fn (array $entry): bool => ! $entry['requested']));
        $teacherIds = array_values(array_unique(array_filter(array_column($entries, 'teacher_id'))));

        return [
            'movable_group_ids' => array_values(array_map(fn ($group): string => $group->id, array_filter($input->groups, fn ($group): bool => $group->movable))),
            'fingerprint' => InputFingerprint::of($input),
            'groups' => $entries,
            'soft' => $check['soft'],
            'score' => $output->softScore->total,
            'score_by_rule' => $output->softScore->byRule,
            'score_before' => $scoreBefore,
            'affected_groups' => array_map(fn (array $entry): array => ['id' => $entry['group_id'], 'code' => $entry['code'], 'subject' => $entry['subject'], 'teacher_id' => $entry['teacher_id'], 'teacher' => $entry['teacher']], $others),
            'affected_teachers' => Teacher::query()->whereIn('id', $teacherIds)->orderBy('name')->get(['id', 'name'])
                ->map(fn (Teacher $teacher): array => ['id' => $teacher->id, 'name' => $teacher->name])->values()->all(),
        ];
    }

    /**
     * Si las sesiones de la alternativa son las que el grupo ya tiene (día, horas, tipo y salón).
     *
     * @param  Collection<int, GroupSession>  $live
     * @param  list<array{day: int, starts_at: string, ends_at: string, kind: string, room_id: ?string}>  $joined
     */
    private static function sameAsLive(Collection $live, array $joined): bool
    {
        $key = fn (int $day, string $start, string $end, string $kind, ?string $room): string => implode('|', [$day, Minutes::clock($start), Minutes::clock($end), $kind, $room ?? '']);
        $current = $live->map(fn (GroupSession $session): string => $key($session->day, (string) $session->starts_at, (string) $session->ends_at, $session->kind->value, $session->room_id))->sort()->values()->all();
        $proposed = collect($joined)->map(fn (array $session): string => $key($session['day'], $session['starts_at'], $session['ends_at'], $session['kind'], $session['room_id']))->sort()->values()->all();

        return $current === $proposed;
    }

    /**
     * La sesión viva que cada sesión de la alternativa reemplaza: primero la del mismo día y horas, luego en orden (por
     * día y hora) las que quedan; las vivas sin pareja se quitan.
     *
     * @param  Collection<int, GroupSession>  $live
     * @param  list<array{day: int, starts_at: string, ends_at: string, kind: string, room_id: ?string}>  $joined
     * @return array{0: list<array{day: int, starts_at: string, ends_at: string, kind: string, room_id: ?string, replaces_session_id: ?string}>, 1: list<string>}
     */
    private static function paired(Collection $live, array $joined): array
    {
        $rest = $live->sortBy([['day', 'asc'], ['starts_at', 'asc']])->values();
        $pairs = [];

        foreach ($joined as $index => $session) {
            $match = $rest->search(fn (GroupSession $current): bool => $current->day === $session['day']
                && Minutes::clock((string) $current->starts_at) === $session['starts_at'] && Minutes::clock((string) $current->ends_at) === $session['ends_at']);

            if ($match !== false) {
                $pairs[$index] = $rest[$match]->id;
                $rest = $rest->forget($match)->values();
            }
        }

        foreach (array_keys($joined) as $index) {
            if (! isset($pairs[$index]) && $rest->isNotEmpty()) {
                $pairs[$index] = $rest->shift()->id;
            }
        }

        $sessions = array_map(fn (int $index): array => [...$joined[$index], 'replaces_session_id' => $pairs[$index] ?? null], array_keys($joined));

        return [$sessions, $rest->pluck('id')->values()->all()];
    }
}
