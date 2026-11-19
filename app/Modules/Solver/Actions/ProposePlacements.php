<?php

namespace App\Modules\Solver\Actions;

use App\Modules\Core\Models\Modality;
use App\Modules\Core\Models\Term;
use App\Modules\Solver\Contract\GroupInput;
use App\Modules\Solver\Contract\HardViolation;
use App\Modules\Solver\Contract\ProposedSession;
use App\Modules\Solver\Contract\RunStatus;
use App\Modules\Solver\Contract\SessionSlot;
use App\Modules\Solver\Contract\SoftScore;
use App\Modules\Solver\Contract\SolverInput;
use App\Modules\Solver\Contract\SolverOutput;
use App\Modules\Solver\Engines\AcceptsClock;
use App\Modules\Solver\Engines\EngineRegistry;
use App\Modules\Solver\Enums\PlacementStop;
use App\Modules\Solver\Evaluation\ContractEvaluator;
use App\Modules\Solver\Evaluation\ContractState;
use App\Modules\Solver\Queries\BuildSolverInput;
use App\Modules\Solver\Rules\ApplyPlan;
use Closure;
use InvalidArgumentException;

/**
 * Pide al motor, directo y con un tope chico, acomodos alternativos para unos grupos con todo lo demás fijo (tareas 8.7 y 8.3b;
 * docs/arquitectura/solver.md, «Acomodos de unos grupos»). Es la puerta única de las alternativas de una solicitud de
 * cambio (Staffing, ADR-0029; las calcula en un job) y de abrir un grupo en el re-análisis. No escribe nada: ni sesiones
 * ni `solver_runs`.
 *
 * - La entrada es la de BuildSolverInput::forGroups(): los grupos pedidos y los virtuales se mueven; lo demás, fijo.
 * - `$pinnedSessions` fija las sesiones de algunos de esos grupos (la propuesta del coordinador en una cadena de
 *   movimientos): el motor los recibe como fijos con esas sesiones, y la salida los trae con ellas. Lo fijado no viene
 *   vacío y suma las horas semanales del grupo, con la tolerancia de HC-12 de la entrada.
 * - Cada alternativa se valida con el evaluador sobre la entrada sin modificar, con los grupos fijados como movibles:
 *   solo cuenta si todos los grupos quedan programados, sin violaciones duras **nuevas** (las que la base ya tenía con
 *   las sesiones actuales no cuentan) y si cambia algo respecto de las sesiones actuales, comparadas unidas (partir una
 *   sesión en horas sueltas a las mismas horas y en el mismo salón no es un cambio). HC-07, HC-08 y HC-13 no
 *   dependen del acomodo (ApplyPlan::INPUT_RULES): no la descartan y salen en `hard_violations` como problemas de la
 *   entrada, igual que las previas (entonces la salida es `partial`). Su puntaje blando es el de esa evaluación.
 * - Diversificar: la heurística es determinista (la misma entrada da siempre la misma salida; `options.seed` no se usa),
 *   así que la siguiente vuelta aparta las horas ya propuestas sin cambiar el contrato ni el motor. Por cada grupo
 *   (no fijado) que cambió en una vuelta anterior, entran grupos fijos sin salón ni docente con esas sesiones (ids
 *   `avoid:<grupo>:<n>`) que comparten con el grupo una cohorte propia (`avoid:<grupo>`), así que el grupo ya no puede
 *   empalmarse con ninguna de sus horas anteriores (las cohortes son HC-03, que tiene que estar activa para pedir más
 *   de una). Si una vuelta no cambió nada, se apartan las horas de todos los grupos no fijados. Así, un grupo que cambió
 *   en una alternativa no vuelve a ninguna de esas horas en las siguientes; uno que no cambió en ella sí puede quedar
 *   igual en otra. De paso, el hueco de SC-01 en esa cohorte acerca la siguiente hora a la anterior. Además se quitan
 *   las repetidas por colocación (día, horas y salón de las sesiones de los movibles). Nada de `avoid:` sale de aquí.
 * - Plazo: todas las vueltas comparten TIME_LIMIT_SECONDS desde que empieza propose(), con el reloj de la Action, que
 *   también recibe el motor si lo acepta (AcceptsClock). Cada vuelta le da al motor los segundos enteros que quedan y
 *   no empieza si queda menos de uno. Hay a lo más `$alternatives + 1` vueltas (una que no cambió nada no cuenta) y se
 *   para en cuanto una no da un acomodo completo y sin duras nuevas: con más horas apartadas no puede salir mejor. Por
 *   qué paró va en el resultado (PlacementStop): todas las pedidas, sin más acomodos, o el tiempo (no quedaba un segundo,
 *   o la vuelta que no dio acomodo usó al menos la mitad de su plazo: el motor se quedó sin tiempo, no sin lugar).
 *
 * Sin estado entre llamadas (Octane).
 */
final class ProposePlacements
{
    /**
     * Segundos de motor para todas las alternativas de una llamada (supuesto por confirmar): es chico porque quien llama
     * espera la respuesta (la 8.7, en su job). Medido el 18 de noviembre de 2026 (docs/arquitectura/solver.md, «Acomodos de unos grupos»): tres
     * alternativas de un grupo de la demo masiva toman de 0.08 a 0.17 s de motor. Armar la entrada (la foto de la
     * modalidad) va aparte y no cuenta en este plazo.
     */
    public const int TIME_LIMIT_SECONDS = 5;

    /** A lo más estas alternativas por llamada. */
    public const int MAX_ALTERNATIVES = 5;

    /** El prefijo de los grupos que apartan horas ya propuestas y de su cohorte. */
    public const string AVOID_PREFIX = 'avoid:';

    /**
     * @param  (Closure(): float)|null  $clock  segundos de un reloj monótono; las pruebas dan uno propio (y el mismo al motor)
     */
    public function __construct(
        private readonly BuildSolverInput $build,
        private readonly EngineRegistry $engines,
        private readonly ContractEvaluator $evaluator,
        private readonly ?Closure $clock = null,
    ) {}

    /**
     * Arma la entrada con forGroups() y llama a propose().
     *
     * @param  list<string>  $movableGroupIds
     * @param  list<GroupInput>  $extraGroups  grupos virtuales (`new:<materia>:<campus>:<carrera>`)
     * @param  array<string, list<SessionSlot>>|null  $pinnedSessions  por id de grupo movible
     *
     * @throws InvalidArgumentException si los grupos, el motor o lo fijado no sirven
     */
    public function handle(Term $term, Modality $modality, array $movableGroupIds, string $engineKey, int $alternatives = 1,
        array $extraGroups = [], ?array $pinnedSessions = null): PlacementProposals
    {
        $base = $this->build->forGroups($term, $modality, $movableGroupIds, $extraGroups, timeLimitSeconds: self::TIME_LIMIT_SECONDS);

        return $this->propose($base, $engineKey, $alternatives, $pinnedSessions);
    }

    /**
     * Hasta `$alternatives` acomodos distintos, completos y sin violaciones duras nuevas para los movibles de una entrada
     * ya armada (la de forGroups()), en el orden en que salieron, y por qué dejó de buscar.
     *
     * @param  array<string, list<SessionSlot>>|null  $pinnedSessions  por id de grupo movible
     *
     * @throws InvalidArgumentException si el motor no existe o no revisa reglas duras, si se piden varias sin HC-03, o si
     *                                  lo fijado no es de un grupo movible, viene vacío o no suma sus horas semanales
     */
    public function propose(SolverInput $base, string $engineKey, int $alternatives = 1, ?array $pinnedSessions = null): PlacementProposals
    {
        $engine = $this->engines->get($engineKey);

        if (! $engine->checksHardRules()) {
            throw new InvalidArgumentException("El motor «{$engine->label()}» no revisa las reglas duras: no sirve para proponer acomodos.");
        }

        if ($alternatives > 1 && ! in_array('HC-03', $base->hardRules, true)) {
            throw new InvalidArgumentException('Para pedir varias alternativas, HC-03 tiene que estar activa: las horas ya propuestas se apartan con cohortes.');
        }

        if ($this->clock !== null && $engine instanceof AcceptsClock) {
            $engine = $engine->withClock($this->clock);
        }

        $pinned = $pinnedSessions ?? [];
        $movable = [];

        foreach ($base->groups as $group) {
            if ($group->movable) {
                $movable[$group->id] = $group;
            }
        }

        foreach ($pinned as $groupId => $slots) {
            $problem = isset($movable[$groupId])
                ? self::pinnedProblem($movable[$groupId], $slots, $base->weeklyHoursTolerance)
                : "Solo se fijan sesiones de un grupo que se mueve; {$groupId} no lo es.";

            if ($problem !== null) {
                throw new InvalidArgumentException($problem);
            }
        }

        $clock = $this->clock ?? fn (): float => hrtime(true) / 1e9;
        $deadline = $clock() + self::TIME_LIMIT_SECONDS;
        $wanted = max(1, min(self::MAX_ALTERNATIVES, $alternatives));
        $free = array_values(array_diff(array_keys($movable), array_keys($pinned)));
        $pinnedProposals = $this->pinnedProposals($movable, $pinned);

        $previous = [];

        foreach ($this->evaluator->evaluate($base, ContractState::currentSessions($base))->hardViolations as $violation) {
            $previous[self::violationKey($violation)] = true;
        }

        $found = [];
        $seen = [];
        $avoid = [];
        $stop = PlacementStop::NoPlacement;

        for ($round = 0; count($found) < $wanted && $round <= $wanted; $round++) {
            $started = $clock();
            $seconds = (int) floor($deadline - $started);

            if ($seconds < 1) {
                $stop = PlacementStop::TimeLimit;

                break;
            }

            $output = $engine->solve($this->engineInput($base, $pinned, $avoid, $seconds));
            $placement = $this->placement($output, $movable, $pinned, $pinnedProposals);

            if ($placement === null) {
                $stop = $clock() - $started >= $seconds / 2 ? PlacementStop::TimeLimit : PlacementStop::NoPlacement;

                break;
            }

            $verdict = $this->evaluator->evaluate($base, $placement);
            $tolerated = array_values(array_filter($verdict->hardViolations,
                fn (HardViolation $violation): bool => in_array($violation->rule, ApplyPlan::INPUT_RULES, true) || isset($previous[self::violationKey($violation)])));
            $key = self::key($placement);

            if (count($tolerated) !== count($verdict->hardViolations) || isset($seen[$key])) {
                break;
            }

            $seen[$key] = true;
            $changed = $this->changedGroups($placement, $movable);

            if ($changed !== []) {
                $found[] = $this->output($base, $output, $placement, $verdict->softScore, $tolerated);
            }

            if ($free === []) {
                break;
            }

            $moved = array_values(array_intersect($free, $changed));

            foreach ($moved === [] ? $free : $moved as $groupId) {
                $avoid[$groupId][] = array_values(array_filter($placement, fn (ProposedSession $session): bool => $session->groupId === $groupId));
            }
        }

        return new PlacementProposals($found, count($found) >= $wanted ? PlacementStop::Complete : $stop);
    }

    /**
     * Por qué no se pueden fijar esas sesiones al grupo, o null si se puede: no vienen vacías y suman sus horas
     * semanales con la tolerancia de HC-12 (en minutos). La cadena de la 8.7 lo pregunta antes de fijar una propuesta.
     *
     * @param  list<SessionSlot>  $slots
     */
    public static function pinnedProblem(GroupInput $group, array $slots, int $toleranceMinutes = 0): ?string
    {
        if ($slots === []) {
            return "Las sesiones fijadas del grupo {$group->id} vienen vacías.";
        }

        $minutes = array_sum(array_map(fn (SessionSlot $slot): int => self::minutes($slot->endsAt) - self::minutes($slot->startsAt), $slots));

        return abs($minutes - $group->weeklyHours * 60) > $toleranceMinutes
            ? "Las sesiones fijadas del grupo {$group->id} suman ".($minutes / 60)." h y el grupo lleva {$group->weeklyHours} h a la semana (HC-12)."
            : null;
    }

    private static function minutes(string $clock): int
    {
        [$hours, $minutes] = array_map('intval', explode(':', $clock));

        return $hours * 60 + $minutes;
    }

    private static function violationKey(HardViolation $violation): string
    {
        $groups = $violation->groupIds;
        sort($groups);

        return implode('|', [$violation->rule, implode(',', $groups), $violation->teacherId ?? '', $violation->roomId ?? '', $violation->message]);
    }

    /**
     * Las sesiones fijadas como propuestas, con `changed` contra las actuales.
     *
     * @param  array<string, GroupInput>  $movable
     * @param  array<string, list<SessionSlot>>  $pinned
     * @return list<ProposedSession>
     */
    private function pinnedProposals(array $movable, array $pinned): array
    {
        $sessions = [];

        foreach ($pinned as $groupId => $slots) {
            $current = self::slotKeys($movable[$groupId]->currentSessions);

            foreach ($slots as $slot) {
                $sessions[] = new ProposedSession($groupId, $slot->day, $slot->startsAt, $slot->endsAt, $slot->roomId, $slot->kind,
                    ! isset($current[self::slotKey($slot->day, $slot->startsAt, $slot->endsAt, $slot->roomId)]));
            }
        }

        return $sessions;
    }

    /**
     * La entrada de una vuelta: los fijados como grupos fijos con sus sesiones y, por cada grupo con horas apartadas, su
     * cohorte `avoid:<grupo>` y un grupo fijo por cada colocación anterior.
     *
     * @param  array<string, list<SessionSlot>>  $pinned
     * @param  array<string, list<list<ProposedSession>>>  $avoid
     */
    private function engineInput(SolverInput $base, array $pinned, array $avoid, int $seconds): SolverInput
    {
        $data = $base->toArray();
        $data['time_limit_seconds'] = $seconds;
        $phantoms = [];

        foreach ($data['groups'] as $i => $group) {
            $id = $group['id'];

            if (isset($pinned[$id])) {
                $data['groups'][$i]['movable'] = false;
                $data['groups'][$i]['fixed_sessions'] = array_map(fn (SessionSlot $slot): array => $slot->toArray(), $pinned[$id]);
                $data['groups'][$i]['current_sessions'] = [];

                continue;
            }

            if (! isset($avoid[$id])) {
                continue;
            }

            $cohort = self::AVOID_PREFIX.$id;
            $data['groups'][$i]['cohorts'][] = $cohort;

            foreach ($avoid[$id] as $n => $sessions) {
                $phantoms[] = [
                    'id' => "{$cohort}:{$n}",
                    'campus_code' => $group['campus_code'],
                    'subject_code' => $cohort,
                    'department_id' => $group['department_id'],
                    'owner_career_id' => $cohort,
                    'study_plan_id' => $cohort,
                    'semester' => 1,
                    'weekly_hours' => max(1, count($sessions)),
                    'practice_hours' => 0,
                    'needs_lab' => false,
                    'capacity' => 1,
                    'cohorts' => [$cohort],
                    'fixed_sessions' => array_map(fn (ProposedSession $session): array => [
                        'day' => $session->day, 'starts_at' => $session->startsAt, 'ends_at' => $session->endsAt, 'kind' => $session->kind->value,
                    ], $sessions),
                    'movable' => false,
                ];
            }
        }

        $data['groups'] = [...$data['groups'], ...$phantoms];

        return SolverInput::fromArray($data);
    }

    /**
     * Las sesiones de la vuelta para todos los movibles (las del motor más las fijadas), o null si quedó alguno sin
     * programar.
     *
     * @param  array<string, GroupInput>  $movable
     * @param  array<string, list<SessionSlot>>  $pinned
     * @param  list<ProposedSession>  $pinnedProposals
     * @return list<ProposedSession>|null
     */
    private function placement(SolverOutput $output, array $movable, array $pinned, array $pinnedProposals): ?array
    {
        if ($output->unscheduled !== [] || ! in_array($output->status, [RunStatus::Optimal, RunStatus::Feasible, RunStatus::Partial], true)) {
            return null;
        }

        $byGroup = [];

        foreach ($pinnedProposals as $session) {
            $byGroup[$session->groupId][] = $session;
        }

        foreach ($output->sessions as $session) {
            if (isset($movable[$session->groupId]) && ! isset($pinned[$session->groupId])) {
                $current = self::slotKeys($movable[$session->groupId]->currentSessions);
                $byGroup[$session->groupId][] = new ProposedSession($session->groupId, $session->day, $session->startsAt, $session->endsAt, $session->roomId, $session->kind,
                    ! isset($current[self::slotKey($session->day, $session->startsAt, $session->endsAt, $session->roomId)]));
            }
        }

        $sessions = [];

        foreach (array_keys($movable) as $groupId) {
            if (($byGroup[$groupId] ?? []) === []) {
                return null;
            }

            foreach ($byGroup[$groupId] as $session) {
                $sessions[] = $session;
            }
        }

        return $sessions;
    }

    /**
     * Los movibles cuya colocación no es la actual (CR-02: una que deja el grupo como está no cuenta). Se compara con
     * las sesiones unidas, las contiguas del mismo día y salón, así que partir una sesión de 2 h en dos de 1 h a las
     * mismas horas no es un cambio.
     *
     * @param  list<ProposedSession>  $placement
     * @param  array<string, GroupInput>  $movable
     * @return list<string>
     */
    private function changedGroups(array $placement, array $movable): array
    {
        $proposed = [];

        foreach ($placement as $session) {
            $proposed[$session->groupId][] = $session;
        }

        $changed = [];

        foreach ($movable as $groupId => $group) {
            if (self::joinedKeys($group->currentSessions) !== self::joinedKeys($proposed[$groupId] ?? [])) {
                $changed[] = $groupId;
            }
        }

        return $changed;
    }

    /**
     * Las llaves de las sesiones con las contiguas del mismo día y salón unidas, ordenadas.
     *
     * @param  list<SessionSlot|ProposedSession>  $slots
     * @return list<string>
     */
    private static function joinedKeys(array $slots): array
    {
        usort($slots, fn (SessionSlot|ProposedSession $a, SessionSlot|ProposedSession $b): int => [$a->day, self::minutes($a->startsAt)] <=> [$b->day, self::minutes($b->startsAt)]);
        $joined = [];

        foreach ($slots as $slot) {
            $last = array_key_last($joined);

            if ($last !== null && $joined[$last][0] === $slot->day && $joined[$last][3] === $slot->roomId && $joined[$last][2] === self::minutes($slot->startsAt)) {
                $joined[$last][2] = self::minutes($slot->endsAt);

                continue;
            }

            $joined[] = [$slot->day, self::minutes($slot->startsAt), self::minutes($slot->endsAt), $slot->roomId];
        }

        $keys = array_map(fn (array $slot): string => implode('|', [$slot[0], $slot[1], $slot[2], $slot[3] ?? '']), $joined);
        sort($keys);

        return $keys;
    }

    /**
     * La alternativa con la forma de la salida del contrato: sesiones de todos los movibles en el orden de la entrada,
     * el puntaje de la evaluación sobre la entrada sin modificar y, en `hard_violations`, solo lo que no depende del
     * acomodo o la base ya tenía (entonces es `partial`).
     *
     * @param  list<ProposedSession>  $placement
     * @param  list<HardViolation>  $tolerated
     */
    private function output(SolverInput $base, SolverOutput $engineOutput, array $placement, SoftScore $score, array $tolerated): SolverOutput
    {
        return new SolverOutput(
            version: SolverInput::VERSION,
            runId: $base->runId,
            status: $tolerated === [] ? RunStatus::Feasible : RunStatus::Partial,
            sessions: $placement,
            teacherSuggestions: [],
            unscheduled: [],
            hardViolations: $tolerated,
            softScore: $score,
            relaxedRules: [],
            metrics: $engineOutput->metrics,
            log: $engineOutput->log,
        );
    }

    /**
     * @param  list<ProposedSession>  $placement
     */
    private static function key(array $placement): string
    {
        $keys = array_map(fn (ProposedSession $session): string => $session->groupId.'|'.self::slotKey($session->day, $session->startsAt, $session->endsAt, $session->roomId), $placement);
        sort($keys);

        return implode(';', $keys);
    }

    /**
     * @param  list<SessionSlot>  $slots
     * @return array<string, true>
     */
    private static function slotKeys(array $slots): array
    {
        $keys = [];

        foreach ($slots as $slot) {
            $keys[self::slotKey($slot->day, $slot->startsAt, $slot->endsAt, $slot->roomId)] = true;
        }

        return $keys;
    }

    private static function slotKey(int $day, string $startsAt, string $endsAt, ?string $roomId): string
    {
        return "{$day}|{$startsAt}|{$endsAt}|".($roomId ?? '');
    }
}
