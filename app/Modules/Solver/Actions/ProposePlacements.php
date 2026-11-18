<?php

namespace App\Modules\Solver\Actions;

use App\Modules\Core\Models\Modality;
use App\Modules\Core\Models\Term;
use App\Modules\Solver\Contract\GroupInput;
use App\Modules\Solver\Contract\ProposedSession;
use App\Modules\Solver\Contract\RunStatus;
use App\Modules\Solver\Contract\SessionSlot;
use App\Modules\Solver\Contract\SoftScore;
use App\Modules\Solver\Contract\SolverInput;
use App\Modules\Solver\Contract\SolverOutput;
use App\Modules\Solver\Engines\EngineRegistry;
use App\Modules\Solver\Evaluation\ContractEvaluator;
use App\Modules\Solver\Queries\BuildSolverInput;
use Closure;
use InvalidArgumentException;

/**
 * Pide al motor, en la misma petición, acomodos alternativos para unos grupos con todo lo demás fijo (tareas 8.7 y 8.3b;
 * docs/arquitectura/solver.md, «Acomodos en la petición»). Es la puerta única de las alternativas de una solicitud de
 * cambio (Staffing, ADR-0029) y de abrir un grupo en el re-análisis. No escribe nada: ni sesiones ni `solver_runs`.
 *
 * - La entrada es la de BuildSolverInput::forGroups(): los grupos pedidos y los virtuales se mueven; lo demás, fijo.
 * - `$pinnedSessions` fija las sesiones de algunos de esos grupos (la propuesta del coordinador en una cadena de
 *   movimientos): el motor los recibe como fijos con esas sesiones, y la salida los trae con ellas.
 * - Cada alternativa se valida con el evaluador sobre la entrada sin modificar, con los grupos fijados como movibles:
 *   solo cuenta si todos los grupos quedan programados, sin una sola violación dura (también las de los fijados) y si
 *   cambia algo respecto de las sesiones actuales. Su puntaje blando es el de esa evaluación.
 * - Diversificar: la heurística es determinista (la misma entrada da siempre la misma salida; `options.seed` no se usa),
 *   así que la siguiente vuelta aparta las horas ya propuestas sin cambiar el contrato ni el motor. Por cada grupo
 *   (no fijado) que cambió en una vuelta anterior, entran grupos fijos sin salón ni docente con esas sesiones (ids
 *   `avoid:<grupo>:<n>`) que comparten con el grupo una cohorte propia (`avoid:<grupo>`), así que el grupo ya no puede
 *   empalmarse con ninguna de sus horas anteriores (HC-03 de las cohortes, que el constructor siempre respeta). Si una
 *   vuelta no cambió nada, se apartan las horas de todos los grupos no fijados. Por eso dos alternativas nunca repiten
 *   la hora de un mismo grupo; de paso, el hueco de SC-01 en esa cohorte acerca la siguiente a la anterior. Además se
 *   quitan las repetidas por colocación (día, horas y salón de las sesiones de los grupos que se mueven).
 * - Plazo: todas las vueltas comparten TIME_LIMIT_SECONDS desde que empieza propose(); cada vuelta le da al motor los
 *   segundos enteros que quedan y no empieza si queda menos de uno. Hay a lo más `$alternatives + 1` vueltas (una que
 *   no cambió nada no cuenta) y se para en cuanto una no da un acomodo completo y sin duras: con más horas apartadas
 *   no puede salir mejor.
 *
 * Sin estado entre llamadas (Octane).
 */
final class ProposePlacements
{
    /**
     * Segundos de motor para todas las alternativas de una llamada (supuesto por confirmar): corre en la petición, así
     * que es chico. La heurística en PHP resuelve el alcance de una solicitud (decenas de grupos) en milisegundos;
     * armar la entrada (la foto de la modalidad) va aparte.
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
     * @return list<SolverOutput>
     *
     * @throws InvalidArgumentException si los grupos, el motor o lo fijado no sirven
     */
    public function handle(Term $term, Modality $modality, array $movableGroupIds, string $engineKey, int $alternatives = 1,
        array $extraGroups = [], ?array $pinnedSessions = null): array
    {
        $base = $this->build->forGroups($term, $modality, $movableGroupIds, $extraGroups, timeLimitSeconds: self::TIME_LIMIT_SECONDS);

        return $this->propose($base, $engineKey, $alternatives, $pinnedSessions);
    }

    /**
     * Hasta `$alternatives` acomodos distintos, completos y sin violaciones duras para los movibles de una entrada ya
     * armada (la de forGroups()), del mejor hallazgo al último; lista vacía si el motor no encontró ninguno.
     *
     * @param  array<string, list<SessionSlot>>|null  $pinnedSessions  por id de grupo movible
     * @return list<SolverOutput>
     *
     * @throws InvalidArgumentException si el motor no existe o no revisa reglas duras, o si se fija un grupo que no es movible
     */
    public function propose(SolverInput $base, string $engineKey, int $alternatives = 1, ?array $pinnedSessions = null): array
    {
        $engine = $this->engines->get($engineKey);

        if (! $engine->checksHardRules()) {
            throw new InvalidArgumentException("El motor «{$engine->label()}» no revisa las reglas duras: no sirve para proponer acomodos.");
        }

        $pinned = $pinnedSessions ?? [];
        $movable = [];

        foreach ($base->groups as $group) {
            if ($group->movable) {
                $movable[$group->id] = $group;
            }
        }

        foreach (array_keys($pinned) as $groupId) {
            if (! isset($movable[$groupId])) {
                throw new InvalidArgumentException("Solo se fijan sesiones de un grupo que se mueve; {$groupId} no lo es.");
            }
        }

        $clock = $this->clock ?? fn (): float => hrtime(true) / 1e9;
        $deadline = $clock() + self::TIME_LIMIT_SECONDS;
        $wanted = max(1, min(self::MAX_ALTERNATIVES, $alternatives));
        $free = array_values(array_diff(array_keys($movable), array_keys($pinned)));
        $pinnedProposals = $this->pinnedProposals($movable, $pinned);

        $found = [];
        $seen = [];
        $avoid = [];

        for ($round = 0; count($found) < $wanted && $round <= $wanted; $round++) {
            $seconds = (int) floor($deadline - $clock());

            if ($seconds < 1) {
                break;
            }

            $output = $engine->solve($this->engineInput($base, $pinned, $avoid, $seconds));
            $placement = $this->placement($output, $movable, $pinned, $pinnedProposals);

            if ($placement === null) {
                break;
            }

            $verdict = $this->evaluator->evaluate($base, $placement);
            $key = self::key($placement);

            if ($verdict->hardViolations !== [] || isset($seen[$key])) {
                break;
            }

            $seen[$key] = true;
            $changed = $this->changedGroups($placement, $movable);

            if ($changed !== []) {
                $found[] = $this->output($base, $output, $placement, $verdict->softScore);
            }

            if ($free === []) {
                break;
            }

            $moved = array_values(array_intersect($free, $changed));

            foreach ($moved === [] ? $free : $moved as $groupId) {
                $avoid[$groupId][] = array_values(array_filter($placement, fn (ProposedSession $session): bool => $session->groupId === $groupId));
            }
        }

        return $found;
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
     * Los movibles cuya colocación no es la actual.
     *
     * @param  list<ProposedSession>  $placement
     * @param  array<string, GroupInput>  $movable
     * @return list<string>
     */
    private function changedGroups(array $placement, array $movable): array
    {
        $proposed = [];

        foreach ($placement as $session) {
            $proposed[$session->groupId][self::slotKey($session->day, $session->startsAt, $session->endsAt, $session->roomId)] = true;
        }

        $changed = [];

        foreach ($movable as $groupId => $group) {
            $current = self::slotKeys($group->currentSessions);
            $mine = $proposed[$groupId] ?? [];
            ksort($current);
            ksort($mine);

            if ($current !== $mine) {
                $changed[] = $groupId;
            }
        }

        return $changed;
    }

    /**
     * La alternativa con la forma de la salida del contrato: sesiones de todos los movibles en el orden de la entrada,
     * sin violaciones duras y con el puntaje de la evaluación sobre la entrada sin modificar.
     *
     * @param  list<ProposedSession>  $placement
     */
    private function output(SolverInput $base, SolverOutput $engineOutput, array $placement, SoftScore $score): SolverOutput
    {
        return new SolverOutput(
            version: SolverInput::VERSION,
            runId: $base->runId,
            status: RunStatus::Feasible,
            sessions: $placement,
            teacherSuggestions: [],
            unscheduled: [],
            hardViolations: [],
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
