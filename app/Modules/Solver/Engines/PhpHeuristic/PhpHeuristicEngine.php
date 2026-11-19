<?php

namespace App\Modules\Solver\Engines\PhpHeuristic;

use App\Modules\Solver\Contract\HardViolation;
use App\Modules\Solver\Contract\ProposedSession;
use App\Modules\Solver\Contract\RunMetrics;
use App\Modules\Solver\Contract\RunStatus;
use App\Modules\Solver\Contract\SoftScore;
use App\Modules\Solver\Contract\SoftWeights;
use App\Modules\Solver\Contract\SolverInput;
use App\Modules\Solver\Contract\SolverOutput;
use App\Modules\Solver\Contract\UnscheduledGroup;
use App\Modules\Solver\Engines\AcceptsClock;
use App\Modules\Solver\Engines\SolverEngine;
use App\Modules\Solver\Evaluation\ContractEvaluation;
use Closure;

/**
 * La heurística en PHP (tareas 6.6 y 6.7; docs/arquitectura/solver.md, «Heurística PHP: construcción» y «Heurística PHP:
 * búsqueda local»): coloca los grupos movibles con la construcción voraz (Builder), valida el resultado con el evaluador
 * en memoria, que es la autoridad (FinalValidation), lo mejora con la búsqueda local (LocalSearch) sobre los mismos
 * índices y vuelve a validar. Un grupo movible al que le quede una violación dura se queda sin programar con la regla
 * como motivo, así que nunca devuelve choques duros en los movibles. La búsqueda nunca empeora el puntaje ni agrega una
 * violación dura: si la segunda validación encontrara una o un puntaje peor, se devuelve lo validado antes de buscar.
 *
 * HC-08 es la excepción, porque no depende del horario: un docente cuyos grupos movibles suman más horas de las que le
 * quedan se pasa igual con o sin sesiones. Sus grupos se colocan, la violación queda en `hard_violations` como un
 * problema de la entrada y la corrida sale `partial` (PS-08, supuesto por confirmar).
 *
 * Sale `feasible` si todo cupo sin violaciones, `partial` si algo quedó. Reporta el avance con el puntaje que lleva,
 * sin bajar nunca (al buscar, en cada iteración): hasta 50 al construir, de 51 a 59 al validar lo construido, de 60 a
 * 90 al buscar, de 91 a 99 en cada pasada de la validación final y 100 al terminar. Respeta `time_limit_seconds`:
 * construye hasta el límite menos lo que guarda para validar (0.25 s más 0.2 ms por grupo de la entrada, hasta la mitad
 * del límite), igual que sin búsqueda, para que la búsqueda nunca le quite tiempo a la construcción, y valida. Solo si
 * la construcción terminó y todavía no llega la reserva de la búsqueda (esa misma, o dos pasadas de la validación que
 * acaba de medir si son más), busca hasta ella y valida otra vez; esa validación solo empieza si una pasada cabe en lo
 * que queda. Si algo no cabe, se devuelve lo construido, validado, y lo dice en el `log`. Lo que lleva al vencer se
 * valida y se devuelve. El puntaje que reporta mientras busca es el de LocalScore, antes de la validación final: si esa
 * validación revierte, el puntaje del 100 es el de antes de buscar. En las métricas, `iterations` son las iteraciones
 * de la búsqueda (los grupos revisados) e `intermediate_solutions` las soluciones que encontró: la construida más una
 * por movimiento aceptado. Sin búsqueda (`search: null`, o si no quedó tiempo), las de la construcción: candidatos
 * revisados y validaciones. El `log` trae además el puntaje inicial y final, los movimientos por tipo y por qué paró.
 *
 * Sin estado entre corridas (Octane): todo vive en solve().
 */
final class PhpHeuristicEngine implements AcceptsClock, SolverEngine
{
    public const string KEY = 'heuristic';

    public const string VERSION = '1.0';

    /**
     * @param  (Closure(): float)|null  $clock  segundos de un reloj monótono; las pruebas dan uno propio
     * @param  LocalSearch|null  $search  la búsqueda local; null, solo la construcción
     */
    public function __construct(
        private readonly GroupOrder $order = new GroupOrder,
        private readonly ?Closure $clock = null,
        private readonly ?LocalSearch $search = new LocalSearch,
    ) {}

    public function withClock(Closure $clock): static
    {
        return new self($this->order, $clock, $this->search);
    }

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'Heurística (PHP)';
    }

    public function supportedVersions(): array
    {
        return [SolverInput::VERSION];
    }

    public function checksHardRules(): bool
    {
        return true;
    }

    public function solve(SolverInput $input, ?Closure $progress = null): SolverOutput
    {
        $clock = $this->clock ?? fn (): float => hrtime(true) / 1e9;
        $started = $clock();

        if (! in_array($input->version, $this->supportedVersions(), true)) {
            return $this->output($input, RunStatus::Error, [], [], [], new SoftScore(0, array_fill_keys(SoftWeights::RULES, 0)), $clock() - $started, 0, 0,
                ["La heurística no entiende la versión {$input->version} del contrato; entiende la ".SolverInput::VERSION.'.']);
        }

        $reserve = min($input->timeLimitSeconds / 2, 0.25 + 0.0002 * count($input->groups));
        $end = $started + $input->timeLimitSeconds;
        $reported = -1;
        $scaled = function (int $percent, ?int $score) use ($progress, &$reported): void {
            if ($progress !== null && $percent >= $reported) {
                $progress($percent, $score);
                $reported = $percent;
            }
        };

        $builder = new Builder($input, $this->order);
        $build = $builder->run($end - $reserve, $clock,
            $progress === null ? null : fn (int $percent) => $scaled(intdiv($percent * ($this->search === null ? 9 : 5), 10), null));
        $validating = $clock();
        [$sessions, $removed, $evaluation, $evaluations] = (new FinalValidation)->clean($input, $build->sessions, $build->placementOrder,
            $clock, $end - $reserve / 2, $progress === null ? null : fn (int $percent, ?int $score) => $scaled($this->search === null ? $percent : min(59, $percent - 40), $score));
        $pass = ($clock() - $validating) / max(1, $evaluations);
        $search = null;
        $skipped = $this->search !== null && ($build->timedOut || $clock() >= $end - max($reserve, 2 * $pass));

        if ($this->search !== null && ! $skipped) {
            [$sessions, $evaluation, $evaluations, $search] = $this->improve($input, $builder, $build, $sessions, $removed, $evaluation, $evaluations,
                $clock, $end, max($reserve, 2 * $pass), $pass, $progress === null ? null : $scaled);
        }

        $unscheduled = $build->unscheduled + $removed;
        $status = $unscheduled === [] && $evaluation->hardViolations === [] ? RunStatus::Feasible : RunStatus::Partial;
        $progress?->__invoke(100, $evaluation->softScore->total);

        return $this->output($input, $status, $sessions, $unscheduled, $evaluation->hardViolations, $evaluation->softScore, $clock() - $started,
            $search === null ? $build->candidates : $search['result']->iterations,
            $search === null ? $evaluations : 1 + ($search['reverted'] ? 0 : $search['result']->acceptedMoves()),
            $this->log($input, $build, $sessions, $unscheduled, $evaluation->hardViolations, $evaluations, $search, $evaluation->softScore->total, $skipped));
    }

    /**
     * La búsqueda local sobre lo construido y validado (hasta la reserva para validar), y la validación final de lo que
     * propone. Esa validación solo empieza si su primera pasada cabe en lo que queda del límite (`pass`: lo que tardó una
     * pasada sobre lo construido); si no, o si quitara un grupo o diera un puntaje peor (no debería: los movimientos solo
     * entran si caben en los índices y bajan el puntaje), se queda lo validado antes de buscar.
     *
     * @param  array<string, list<ProposedSession>>  $sessions
     * @param  array<string, list<string>>  $removed
     * @param  Closure(): float  $clock
     * @param  (Closure(int, ?int): void)|null  $progress
     * @return array{array<string, list<ProposedSession>>, ContractEvaluation, int, array{result: SearchResult, initial: int, reverted: bool, late: bool, detours: array{int, int}}}
     */
    private function improve(SolverInput $input, Builder $builder, BuildResult $build, array $sessions, array $removed, ContractEvaluation $evaluation,
        int $evaluations, Closure $clock, float $end, float $reserve, float $pass, ?Closure $progress): array
    {
        foreach (array_keys($removed) as $groupId) {
            $builder->remove((string) $groupId);
        }

        $result = $this->search->run($input, $builder, $end - $reserve, $clock,
            $progress === null ? null : fn (int $percent, int $score) => $progress(60 + intdiv($percent * 30, 100), $score));
        $search = ['result' => $result, 'initial' => $evaluation->softScore->total, 'reverted' => true, 'late' => $clock() + $pass > $end, 'detours' => $builder->detours()];

        if ($search['late']) {
            return [$sessions, $evaluation, $evaluations, $search];
        }

        [$searched, $lost, $after, $more] = (new FinalValidation)->clean($input, $result->sessions, $build->placementOrder, $clock, $end - $pass, $progress);
        $search['reverted'] = $lost !== [] || $after->softScore->total > $evaluation->softScore->total;

        return $search['reverted'] ? [$sessions, $evaluation, $evaluations + $more, $search] : [$searched, $after, $evaluations + $more, $search];
    }

    /**
     * @param  array<string, list<ProposedSession>>  $sessions
     * @param  array<string, list<string>>  $unscheduled
     * @param  list<HardViolation>  $violations
     * @param  array{result: SearchResult, initial: int, reverted: bool, late: bool, detours: array{int, int}}|null  $search
     * @return list<string>
     */
    private function log(SolverInput $input, BuildResult $build, array $sessions, array $unscheduled, array $violations, int $evaluations, ?array $search, int $score, bool $skipped): array
    {
        $movable = count(array_filter($input->groups, fn ($group): bool => $group->movable));
        $log = [
            'Heurística (PHP), construcción voraz: colocó '.count($sessions)." de {$movable} grupos movibles; ".count($unscheduled).' quedaron sin programar.',
            "Revisó {$build->candidates} candidatos y validó la solución con el evaluador {$evaluations} ".($evaluations === 1 ? 'vez.' : 'veces.'),
        ];

        [$otherDays, $doubleHours] = $search === null || $search['reverted'] ? [$build->otherDays, $build->doubleHours] : $search['detours'];

        if ($otherDays > 0) {
            $log[] = $otherDays === 1
                ? '1 grupo quedó en otros días porque su patrón no cupo (supuesto por confirmar).'
                : "{$otherDays} grupos quedaron en otros días porque su patrón no cupo (supuesto por confirmar).";
        }

        if ($doubleHours > 0) {
            $log[] = $doubleHours === 1
                ? '1 grupo quedó con dos horas el mismo día porque su patrón no cupo (supuesto por confirmar).'
                : "{$doubleHours} grupos quedaron con dos horas el mismo día porque su patrón no cupo (supuesto por confirmar).";
        }

        if ($build->timedOut) {
            $log[] = "Se acabó el tiempo límite ({$input->timeLimitSeconds} s) antes de terminar: lo colocado se validó y lo demás queda sin programar.";
        }

        $overloaded = array_unique(array_map(fn (HardViolation $violation): ?string => $violation->teacherId,
            array_filter($violations, fn (HardViolation $violation): bool => $violation->rule === 'HC-08')));

        if ($overloaded !== []) {
            $log[] = 'HC-08: '.count($overloaded).' docente(s) con más horas asignadas de las que les quedan. No depende del horario: corrígelo en la asignación de docentes.';
        }

        if ($skipped) {
            $log[] = 'No quedó tiempo para la búsqueda local: se devuelve lo construido, validado.';
        }

        if ($search !== null) {
            $result = $search['result'];
            $log[] = sprintf('Búsqueda local: %d %s y %d movimientos revisados; aceptó %d (%d de mover, %d de intercambiar, %d de cambiar salón y %d de desplazar docente). %s',
                $result->iterations, $result->iterations === 1 ? 'iteración' : 'iteraciones', $result->evaluated, $result->acceptedMoves(),
                $result->accepted['move'], $result->accepted['swap'], $result->accepted['room'], $result->accepted['shift'],
                $score < $search['initial'] ? "El puntaje blando bajó de {$search['initial']} a {$score}." : "El puntaje blando se quedó en {$score}.");
            $log[] = match (true) {
                $search['late'] => 'No quedó tiempo para validar lo que propuso la búsqueda local; se devuelve lo construido, validado.',
                $search['reverted'] => 'La validación final encontró un problema en lo que propuso la búsqueda local; se devuelve lo construido, validado.',
                $result->timedOut => 'La búsqueda local paró por el tiempo límite; se devuelve lo mejor que llevaba, validado.',
                $result->iterations === 0 => 'La búsqueda local no tenía grupos colocados que revisar.',
                default => "La búsqueda local paró porque {$result->idleLimit} ".($result->idleLimit === 1 ? 'iteración seguida no mejoró' : 'iteraciones seguidas no mejoraron').' el puntaje.',
            };
        }

        return $log;
    }

    /**
     * @param  array<string, list<ProposedSession>>  $sessions
     * @param  array<string, list<string>>  $unscheduled
     * @param  list<HardViolation>  $violations
     * @param  list<string>  $log
     */
    private function output(SolverInput $input, RunStatus $status, array $sessions, array $unscheduled, array $violations, SoftScore $score,
        float $elapsed, int $candidates, int $evaluations, array $log): SolverOutput
    {
        $ordered = [];
        $pending = [];

        foreach ($input->groups as $group) {
            foreach ($sessions[$group->id] ?? [] as $session) {
                $ordered[] = $session;
            }

            if (isset($unscheduled[$group->id])) {
                $pending[] = new UnscheduledGroup($group->id, $unscheduled[$group->id]);
            }
        }

        return new SolverOutput(
            version: SolverInput::VERSION,
            runId: $input->runId,
            status: $status,
            sessions: $ordered,
            teacherSuggestions: [],
            unscheduled: $pending,
            hardViolations: $violations,
            softScore: $score,
            relaxedRules: [],
            metrics: new RunMetrics(
                elapsedSeconds: round(max(0.0, $elapsed), 3),
                engine: self::KEY,
                engineVersion: self::VERSION,
                iterations: $candidates,
                intermediateSolutions: $evaluations,
            ),
            log: $log,
        );
    }
}
