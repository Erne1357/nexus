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
use App\Modules\Solver\Engines\SolverEngine;
use Closure;

/**
 * La heurística en PHP (tarea 6.6; docs/arquitectura/solver.md, «Heurística PHP: construcción»): coloca los grupos
 * movibles con la construcción voraz (Builder) y valida el resultado con el evaluador en memoria, que es la autoridad
 * (FinalValidation): un grupo movible al que le quede una violación dura se queda sin programar con la regla como motivo,
 * así que nunca devuelve choques duros en los movibles.
 *
 * HC-08 es la excepción, porque no depende del horario: un docente cuyos grupos movibles suman más horas de las que le
 * quedan se pasa igual con o sin sesiones. Sus grupos se colocan, la violación queda en `hard_violations` como un
 * problema de la entrada y la corrida sale `partial` (supuesto por confirmar). La búsqueda local que mejora el puntaje
 * llega con la 6.7.
 *
 * Sale `feasible` si todo cupo sin violaciones, `partial` si algo quedó. Reporta el avance (hasta 90 al construir, 100
 * con el puntaje del evaluador) y respeta `time_limit_seconds`: construye hasta el límite menos lo que guarda para
 * validar y devuelve lo que lleva, validado. En las métricas, `iterations` son los candidatos revisados y
 * `intermediate_solutions` las veces que validó una solución con el evaluador.
 *
 * Sin estado entre corridas (Octane): todo vive en solve().
 */
final class PhpHeuristicEngine implements SolverEngine
{
    public const string KEY = 'heuristic';

    public const string VERSION = '1.0';

    /**
     * @param  (Closure(): float)|null  $clock  segundos de un reloj monótono; las pruebas dan uno propio
     */
    public function __construct(
        private readonly GroupOrder $order = new GroupOrder,
        private readonly ?Closure $clock = null,
    ) {}

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
        $reported = -1;
        $build = (new Builder($input, $this->order))->run($started + $input->timeLimitSeconds - $reserve, $clock,
            $progress === null ? null : function (int $percent) use ($progress, &$reported): void {
                $scaled = intdiv($percent * 9, 10);

                if ($scaled !== $reported) {
                    $progress($scaled, null);
                    $reported = $scaled;
                }
            });

        [$sessions, $removed, $evaluation, $evaluations] = (new FinalValidation)->clean($input, $build->sessions, $build->placementOrder);
        $unscheduled = $build->unscheduled + $removed;
        $status = $unscheduled === [] && $evaluation->hardViolations === [] ? RunStatus::Feasible : RunStatus::Partial;
        $progress?->__invoke(100, $evaluation->softScore->total);

        return $this->output($input, $status, $sessions, $unscheduled, $evaluation->hardViolations, $evaluation->softScore, $clock() - $started,
            $build->candidates, $evaluations, $this->log($input, $build, $sessions, $unscheduled, $evaluation->hardViolations, $evaluations));
    }

    /**
     * @param  array<string, list<ProposedSession>>  $sessions
     * @param  array<string, list<string>>  $unscheduled
     * @param  list<HardViolation>  $violations
     * @return list<string>
     */
    private function log(SolverInput $input, BuildResult $build, array $sessions, array $unscheduled, array $violations, int $evaluations): array
    {
        $movable = count(array_filter($input->groups, fn ($group): bool => $group->movable));
        $log = [
            'Heurística (PHP), construcción voraz: colocó '.count($sessions)." de {$movable} grupos movibles; ".count($unscheduled).' quedaron sin programar.',
            "Revisó {$build->candidates} candidatos y validó la solución con el evaluador {$evaluations} ".($evaluations === 1 ? 'vez.' : 'veces.'),
        ];

        if ($build->otherDays > 0) {
            $log[] = $build->otherDays === 1
                ? '1 grupo quedó en otros días porque su patrón no cupo (supuesto por confirmar).'
                : "{$build->otherDays} grupos quedaron en otros días porque su patrón no cupo (supuesto por confirmar).";
        }

        if ($build->doubleHours > 0) {
            $log[] = $build->doubleHours === 1
                ? '1 grupo quedó con dos horas el mismo día porque su patrón no cupo (supuesto por confirmar).'
                : "{$build->doubleHours} grupos quedaron con dos horas el mismo día porque su patrón no cupo (supuesto por confirmar).";
        }

        if ($build->timedOut) {
            $log[] = "Se acabó el tiempo límite ({$input->timeLimitSeconds} s) antes de terminar: lo colocado se validó y lo demás queda sin programar.";
        }

        if ($build->overloadedTeachers !== []) {
            $log[] = 'HC-08: '.count($build->overloadedTeachers).' docente(s) con más horas asignadas de las que les quedan. No depende del horario: corrígelo en la asignación de docentes.';
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
