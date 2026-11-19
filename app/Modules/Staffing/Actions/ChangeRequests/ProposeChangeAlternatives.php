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
use App\Modules\Scheduling\Validation\Minutes;
use App\Modules\Scheduling\Validation\ScheduleState;
use App\Modules\Scheduling\Validation\SessionCandidate;
use App\Modules\Solver\Actions\ProposePlacements;
use App\Modules\Solver\Contract\SessionKind as ContractSessionKind;
use App\Modules\Solver\Contract\SessionSlot;
use App\Modules\Solver\Contract\SolverInput;
use App\Modules\Solver\Contract\SolverOutput;
use App\Modules\Solver\Engines\PhpHeuristic\PhpHeuristicEngine;
use App\Modules\Solver\Enums\PlacementStop;
use App\Modules\Solver\Evaluation\ContractEvaluator;
use App\Modules\Solver\Evaluation\ContractState;
use App\Modules\Solver\Queries\BuildSolverInput;
use App\Modules\Solver\Rules\ApplyPlan;
use App\Modules\Solver\Rules\InputFingerprint;
use App\Modules\Staffing\Enums\ChangeAlternativesStatus;
use App\Modules\Staffing\Enums\ChangeRequestStatus;
use App\Modules\Staffing\Jobs\ComputeChangeAlternatives;
use App\Modules\Staffing\Models\ChangeRequest;
use App\Modules\Staffing\Queries\GroupsScheduleFingerprintQuery;
use App\Modules\Staffing\Rules\ChangeRequestRules;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * CR-02 (tarea 8.7; supuesto por confirmar): pide al motor acomodos sin choques para una solicitud de cambio pendiente
 * cuya propuesta deja choques duros, y los guarda en `change_requests.alternatives` con bitácora. Decide el jefe del
 * departamento (ChangeRequestPolicy::decide, que handle() vuelve a revisar). No escribe sesiones.
 *
 * **En la cola** (CLAUDE.md §4.7): handle() revisa lo barato (pendiente, la carga en asignación o ajustes, una propuesta
 * que se puede simular y que, según el impacto guardado, deja choques duros), deja `alternatives` en `computing` con un
 * id de cálculo (`calculation_id`), `requested_at` y `requested_by`, y después del commit despacha
 * ComputeChangeAlternatives. Si ya hay un cálculo en curso que no venció, no pide otro (el estado y el id del cálculo,
 * con los candados, hacen que haya uno solo). El job llama a compute(), que marca `started_at` y, al terminar, guarda
 * `ready` (con alternativas), `none` (sin ninguna, con el motivo) o `failed` (la solicitud ya no se puede decidir, la
 * propuesta ya no deja choques o el motor falló), solo si ese cálculo sigue siendo el vigente y no venció; si la
 * solicitud se decidió mientras tanto, guarda `failed` sin alternativas (DECIDED_MEANWHILE). Los estados y lo que trae cada uno: ChangeAlternativesStatus y modelo-de-datos.md.
 *
 * El cálculo (compute()) pide a ProposePlacements, con la heurística en PHP, hasta ALTERNATIVES acomodos:
 *
 * - (a) la cadena: la propuesta queda fija (las sesiones que simula CR-01, con el salón y el tipo de la sesión que cada
 *   una reemplaza) y se mueven los grupos con los que choca que son del mismo departamento, periodo y modalidad; una sola.
 *   No se pide si la propuesta no suma las horas semanales del grupo (ProposePlacements::pinnedProblem; HC-12).
 * - (b) el grupo solo: se mueve el grupo pedido con todo lo demás fijo; las que falten para llegar a ALTERNATIVES. Una
 *   que lo deje como está no cuenta, y dentro de esta llamada cada una lleva al grupo a horas distintas de las
 *   anteriores (ProposePlacements). La cadena y el grupo solo son llamadas aparte: el grupo solo puede repetir horas de
 *   la cadena.
 *
 * Los grupos de otros departamentos nunca se mueven. Cada alternativa se revisa además con el validador de horarios
 * (AssessChangeImpact::simulateSessions, solo lo que agrega) y se descarta si deja un choque duro nuevo; HC-07, HC-08 y
 * HC-13 no dependen del acomodo (ApplyPlan::INPUT_RULES): no la descartan y se guardan aparte como `input_problems`,
 * con las que el motor reportó; las duras que esos grupos ya tenían y que la alternativa conserva van en
 * `previous_problems`. El validador ve lo que el contrato no trae (el núcleo sin combinación de HC-03), así que
 * descarta algunas que el motor sí dio. Cada alternativa lleva un id único (uuid) que no se repite entre cálculos, las
 * sesiones resultantes de cada grupo que cambia (día, inicio, fin, tipo, salón y la sesión que reemplaza; las horas que
 * se pegan con el mismo tipo y salón se unen, como en el editor), los avisos blandos, el puntaje del motor (y el de hoy
 * sobre la misma entrada), los grupos y docentes afectados, la huella de la entrada (SV-02) y la barata de todos los
 * grupos reales de esa entrada, movibles y fijos (`checked_group_ids`, `sessions_fingerprint`), que ChooseChangeAlternative
 * compara para decir «Esto cambió…». Por qué paró la búsqueda va en `stop` y su
 * motivo en `message` (PlacementStop).
 *
 * La foto de la modalidad se lee una sola vez por cálculo (la del impacto, AssessChangeImpact::state) para el impacto,
 * las dos entradas del motor y la revisión de cada alternativa. Guardar toma los candados de LocksChangeRequestScope, en
 * su orden. Pedirlas otra vez reemplaza las anteriores.
 */
final class ProposeChangeAlternatives
{
    /** Cuántas alternativas se guardan como mucho. */
    public const int ALTERNATIVES = 3;

    public const string CHAIN = 'chain';

    public const string ALONE = 'alone';

    public const string NONE_FOUND = 'El motor no encontró acomodos sin choques.';

    public const string NO_HARD_CLASHES = 'El cambio no deja choques duros: se puede aprobar como se pidió.';

    public const string TEXT_ONLY = 'La solicitud solo trae texto: el motor no tiene un horario o un salón que acomodar.';

    public const string DECIDED_MEANWHILE = 'La solicitud se decidió mientras se calculaban las alternativas.';

    public function __construct(
        private readonly AssessChangeImpact $assess,
        private readonly BuildSolverInput $build,
        private readonly ProposePlacements $placements,
        private readonly ContractEvaluator $evaluator,
        private readonly LocksChangeRequestScope $locks,
        private readonly GroupsScheduleFingerprintQuery $groupsFingerprint,
    ) {}

    /**
     * Pide el cálculo: deja `computing` y despacha el job.
     *
     * @throws ValidationException si la solicitud no está pendiente, la carga no está en asignación o ajustes, la
     *                             propuesta no se puede simular o no deja choques duros, o ya se están calculando
     */
    public function handle(ChangeRequest $request, User $actor): ChangeRequest
    {
        Gate::forUser($actor)->authorize('decide', $request);

        $request->refresh();
        self::ensureDecidable($request, AcademicLoad::query()->ofDepartment($request->term_id, $request->modality_id, $request->department_id)->first());
        self::ensureProposable($request);

        $calculationId = (string) Str::uuid7();

        $this->locks->guard(fn () => DB::transaction(function () use ($request, $actor, $calculationId): void {
            self::ensureDecidable($request, $this->locks->lock($request));
            self::ensureProposable($request);

            if (ChangeAlternativesStatus::of($request->alternatives) === ChangeAlternativesStatus::Computing) {
                throw ValidationException::withMessages(['alternatives' => 'Las alternativas de esta solicitud ya se están calculando.']);
            }

            $request->forceFill(['alternatives' => [
                'status' => ChangeAlternativesStatus::Computing->value,
                'calculation_id' => $calculationId,
                'requested_at' => now()->toIso8601String(),
                'requested_by' => $actor->getKey(),
            ]])->save();

            ComputeChangeAlternatives::dispatch($request->getKey(), $calculationId)->afterCommit();
        }));

        return $request;
    }

    /**
     * El cálculo de ComputeChangeAlternatives. No hace nada si `$calculationId` ya no es el cálculo en curso de la
     * solicitud (se volvió a pedir, o se dio por colgado y se pidió otro).
     */
    public function compute(string $requestId, string $calculationId): void
    {
        $request = ChangeRequest::query()->find($requestId);

        if ($request === null || ! $this->start($request, $calculationId)) {
            return;
        }

        try {
            self::ensureDecidable($request, AcademicLoad::query()->ofDepartment($request->term_id, $request->modality_id, $request->department_id)->first());
            $proposal = $this->assess->proposal($request);
            $impact = $this->assess->simulate($request);

            if ($proposal === [] || $impact['hard'] === []) {
                throw ValidationException::withMessages(['status' => $proposal === [] ? self::TEXT_ONLY : self::NO_HARD_CLASHES]);
            }

            $result = $this->alternatives($request, $proposal, $impact);
        } catch (ValidationException $exception) {
            $this->fail($requestId, $calculationId, (string) collect($exception->errors())->flatten()->first());

            return;
        } catch (Throwable $exception) {
            report($exception);
            $this->fail($requestId, $calculationId, ComputeChangeAlternatives::FAILED_MESSAGE);

            return;
        }

        $this->finish($requestId, $calculationId, [
            'status' => ($result['items'] === [] ? ChangeAlternativesStatus::None : ChangeAlternativesStatus::Ready)->value,
            ...$result,
        ]);
    }

    /**
     * Marca que el job empezó (`started_at`), con los candados: desde ahí cuenta el plazo de un cálculo colgado
     * (ChangeAlternativesStatus). False si el cálculo ya no es el vigente o ya venció en la cola.
     */
    private function start(ChangeRequest $request, string $calculationId): bool
    {
        return $this->locks->guard(fn (): bool => DB::transaction(function () use ($request, $calculationId): bool {
            $this->locks->lock($request);

            if (! self::isCalculation($request->alternatives, $calculationId)) {
                return false;
            }

            $request->forceFill(['alternatives' => [...$request->alternatives, 'started_at' => now()->toIso8601String()]])->save();

            return true;
        }));
    }

    /**
     * Deja fallido el cálculo, con su motivo, si sigue siendo el vigente.
     */
    public function fail(string $requestId, string $calculationId, string $message): void
    {
        $this->finish($requestId, $calculationId, ['status' => ChangeAlternativesStatus::Failed->value, 'computed_at' => now()->toIso8601String(), 'message' => $message]);
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function finish(string $requestId, string $calculationId, array $result): void
    {
        $request = ChangeRequest::query()->find($requestId);

        if ($request === null) {
            return;
        }

        $this->locks->guard(fn () => DB::transaction(function () use ($request, $calculationId, $result): void {
            $this->locks->lock($request);

            if (! self::isCalculation($request->alternatives, $calculationId)) {
                return;
            }

            if ($request->status !== ChangeRequestStatus::Pending && $result['status'] !== ChangeAlternativesStatus::Failed->value) {
                $result = ['status' => ChangeAlternativesStatus::Failed->value, 'computed_at' => now()->toIso8601String(), 'message' => self::DECIDED_MEANWHILE];
            }

            $request->forceFill(['alternatives' => [...array_intersect_key($request->alternatives, array_flip(['calculation_id', 'requested_at', 'requested_by', 'started_at'])), ...$result]])->save();

            activity()
                ->performedOn($request)
                ->causedBy(User::query()->find($request->alternatives['requested_by'] ?? null))
                ->event('alternatives_proposed')
                ->withProperties(['status' => $result['status'], 'engine' => $result['engine'] ?? null, 'alternatives' => array_column($result['items'] ?? [], 'kind', 'id'), 'message' => $result['message'] ?? null])
                ->log(match ($result['status']) {
                    ChangeAlternativesStatus::Ready->value => 'El motor calculó alternativas para la solicitud de cambio',
                    ChangeAlternativesStatus::None->value => 'El motor no encontró acomodos sin choques para la solicitud de cambio',
                    default => 'No se pudieron calcular las alternativas de la solicitud de cambio',
                });
        }));
    }

    /**
     * Si lo guardado es ese cálculo y sigue en curso: un cálculo vencido (ChangeAlternativesStatus::of()) ya no se guarda.
     *
     * @param  array<string, mixed>|null  $alternatives
     */
    private static function isCalculation(?array $alternatives, string $calculationId): bool
    {
        return ChangeAlternativesStatus::of($alternatives) === ChangeAlternativesStatus::Computing && ($alternatives['calculation_id'] ?? null) === $calculationId;
    }

    /**
     * Lo barato de CR-02, con lo guardado: una propuesta que se puede simular y que, según el impacto (CR-01, que el jefe
     * recalcula al abrirla), deja choques duros. El cálculo lo vuelve a revisar con el impacto del momento.
     *
     * @throws ValidationException
     */
    private static function ensureProposable(ChangeRequest $request): void
    {
        if (! AssessChangeImpact::isAssessable($request)) {
            throw ValidationException::withMessages(['status' => self::TEXT_ONLY]);
        }

        if ($request->impact !== null && ($request->impact['hard'] ?? []) === []) {
            throw ValidationException::withMessages(['status' => self::NO_HARD_CLASHES]);
        }
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
     * @return array{computed_at: string, engine: string, items: list<array<string, mixed>>, stop: string, message: ?string, chosen: null}
     */
    private function alternatives(ChangeRequest $request, array $proposal, array $impact): array
    {
        $group = Group::query()->findOrFail($request->group_id);
        $term = Term::query()->findOrFail($group->term_id);
        $modality = Modality::withTrashed()->findOrFail($group->modality_id);
        $state = $this->assess->state($group->term_id, $group->modality_id);
        $usesRooms = $state->profile($modality->id)->usesRooms;
        $pinned = array_map(fn (SessionCandidate $session): SessionSlot => new SessionSlot($session->day, Minutes::clock($session->startsAt), Minutes::clock($session->endsAt),
            ContractSessionKind::from($session->kind->value), $usesRooms ? $session->roomId : null), $proposal);

        $runs = [];
        $stops = [];
        $chain = $this->chainGroups($state, $group, $impact);

        if ($chain !== []) {
            $base = $this->build->forGroups($term, $modality, [$group->id, ...$chain], [], null, ProposePlacements::TIME_LIMIT_SECONDS, $state);
            $mine = collect($base->groups)->firstWhere('id', $group->id);

            if (ProposePlacements::pinnedProblem($mine, $pinned, $base->weeklyHoursTolerance) === null) {
                $proposals = $this->placements->propose($base, PhpHeuristicEngine::KEY, 1, [$group->id => $pinned]);
                $stops[] = $proposals->stop;

                foreach ($proposals as $output) {
                    $runs[] = [self::CHAIN, $base, $output];
                }
            }
        }

        $base = $this->build->forGroups($term, $modality, [$group->id], [], null, ProposePlacements::TIME_LIMIT_SECONDS, $state);
        $proposals = $this->placements->propose($base, PhpHeuristicEngine::KEY, self::ALTERNATIVES - count($runs));
        $stops[] = $proposals->stop;

        foreach ($proposals as $output) {
            $runs[] = [self::ALONE, $base, $output];
        }

        $items = [];
        $before = [];

        foreach ($runs as [$kind, $input, $output]) {
            $before[spl_object_id($input)] ??= $this->evaluator->evaluate($input, ContractState::currentSessions($input))->softScore->total;
            $item = $this->item($group, $input, $output, $before[spl_object_id($input)]);

            if ($item !== null) {
                $items[] = ['id' => (string) Str::uuid7(), 'kind' => $kind, ...$item];
            }
        }

        $stop = match (true) {
            in_array(PlacementStop::TimeLimit, $stops, true) => PlacementStop::TimeLimit,
            count($items) >= self::ALTERNATIVES => PlacementStop::Complete,
            default => PlacementStop::NoPlacement,
        };

        return [
            'computed_at' => now()->toIso8601String(),
            'engine' => PhpHeuristicEngine::KEY,
            'items' => $items,
            'stop' => $stop->value,
            'message' => $stop->message(count($items)),
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
        [$inputHard, $blocking] = collect($check['hard'])->partition(fn (array $rule): bool => in_array($rule['rule'], ApplyPlan::INPUT_RULES, true));

        if ($blocking->isNotEmpty()) {
            return null;
        }

        $inputProblems = [];
        $previousProblems = [];

        foreach ($output->hardViolations as $violation) {
            $entry = ['rule' => $violation->rule, 'message' => $violation->message, 'groups' => array_values($violation->groupIds)];

            if (in_array($violation->rule, ApplyPlan::INPUT_RULES, true)) {
                $inputProblems["{$violation->rule}|{$violation->message}"] = $entry;
            } else {
                $previousProblems["{$violation->rule}|{$violation->message}"] = $entry;
            }
        }

        foreach ($inputHard as $rule) {
            foreach ($rule['messages'] as $message) {
                $inputProblems["{$rule['rule']}|{$message}"] ??= ['rule' => $rule['rule'], 'message' => $message, 'groups' => $rule['groups']];
            }
        }

        usort($entries, fn (array $a, array $b): int => [! $a['requested'], $a['code']] <=> [! $b['requested'], $b['code']]);
        $movableIds = array_values(array_map(fn ($group): string => $group->id, array_filter($input->groups, fn ($group): bool => $group->movable)));
        $checkedIds = array_values(array_map(fn ($group): string => $group->id, array_filter($input->groups, fn ($group): bool => ! str_starts_with($group->id, BuildSolverInput::VIRTUAL_PREFIX))));
        $others = array_values(array_filter($entries, fn (array $entry): bool => ! $entry['requested']));
        $teacherIds = array_values(array_unique(array_filter(array_column($entries, 'teacher_id'))));

        return [
            'movable_group_ids' => $movableIds,
            'fingerprint' => InputFingerprint::of($input),
            'checked_group_ids' => $checkedIds,
            'sessions_fingerprint' => $this->groupsFingerprint->of($checkedIds),
            'groups' => $entries,
            'soft' => $check['soft'],
            'input_problems' => array_values($inputProblems),
            'previous_problems' => array_values($previousProblems),
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
