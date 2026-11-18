<?php

namespace App\Modules\Solver\Engines\PhpHeuristic;

use App\Modules\Solver\Contract\SolverInput;
use Closure;

/**
 * La búsqueda local de la heurística en PHP (tarea 6.7; docs/arquitectura/solver.md, «Heurística PHP: búsqueda local»).
 * Corre después de la construcción (Builder) sobre sus mismos índices y baja el puntaje blando sin arriesgar la
 * factibilidad. Siempre mueve grupos movibles enteros, con su patrón de sesiones (PS-01 a PS-05 y PS-09): una hora por
 * día, a la misma hora todos sus días.
 *
 * **Movimientos**, para cada grupo:
 *
 * - *mover*: el grupo a otra hora de inicio o a otro juego de días de su nivel o de uno mejor, con los salones que elige
 *   la construcción en esa hora. Un grupo en su patrón no sale de él; uno que fue a otros días puede volver hacia el
 *   patrón (PS-09, supuesto por confirmar).
 * - *intercambiar*: con otro grupo colocado del mismo campus que ocupa (por docente o por cohorte) una hora a la que el
 *   grupo no puede ir: cada uno toma la hora de inicio del otro y conserva sus días.
 * - *cambiar salón*: en un día o en todos, por uno por defecto de la materia (SC-03) o del edificio de la sesión pegada
 *   del docente (SC-06), libre y que le sirva.
 * - *desplazar al docente*: en la iteración del primer grupo de un docente con varios, todos sus grupos las mismas horas
 *   antes o después, cada uno en sus días. Mueve su bloque de clases sin abrirle huecos, que es lo que necesita SC-04
 *   cuando mover un solo grupo a sus preferidos le abre huecos que cuestan más (SC-05).
 *
 * **Aceptación.** Un movimiento solo se considera si cabe en los índices del constructor (HC-01 a HC-13, como al
 * construir: no crea choques duros) y entra solo si baja el puntaje blando (LocalScore, el mismo del evaluador calculado
 * por pedazos). Es *mejor mejora por grupo y primera mejora entre grupos*: de los movimientos de un grupo toma el que
 * más baja el puntaje y lo aplica en seguida, antes de pasar al siguiente grupo. Revisar todos los movimientos de un
 * grupo cuesta poco (unas decenas de horas y salones) y escoger el mejor evita gastar movimientos en mejoras chicas que
 * luego estorban; esperar a recorrer todos los grupos para aplicar uno solo sería muy lento con miles de grupos. Los
 * empates se quedan con el primero que encontró (nivel, juego de días y hora más temprana), y los grupos se recorren en
 * el orden en que se colocaron. No hay nada aleatorio: la misma entrada da siempre la misma salida, así que
 * `options.seed` no se usa.
 *
 * **Paro.** Por el plazo (lo que queda de `time_limit_seconds`, que da el motor) o por `maxIdleIterations` iteraciones
 * seguidas sin mejora; por omisión, una vuelta completa a los grupos, que es un óptimo local de estos movimientos: otra
 * vuelta daría lo mismo. El contrato no trae un campo para cambiarlo (`options` solo trae `threads`, `seed`,
 * `suggest_teachers` y `return_intermediate`), así que es un parámetro del constructor.
 *
 * **Avance.** Reporta de 0 a 100 con el puntaje que lleva: lo que más haya avanzado entre el reloj y las iteraciones sin
 * mejora. Vive lo que dura una corrida (Octane).
 */
final readonly class LocalSearch
{
    /**
     * @param  int|null  $maxIdleIterations  iteraciones seguidas sin mejora antes de parar; null: una vuelta completa a
     *                                       los grupos
     */
    public function __construct(public ?int $maxIdleIterations = null) {}

    /**
     * Mejora lo que el constructor ya colocó hasta el plazo y devuelve las sesiones, que el motor valida con el
     * evaluador.
     *
     * @param  Closure(): float  $clock
     * @param  (Closure(int $percent, int $score): void)|null  $progress
     */
    public function run(SolverInput $input, Builder $builder, float $deadline, Closure $clock, ?Closure $progress = null): SearchResult
    {
        $score = new LocalScore($input, $builder->sessions());
        $initial = $score->total();
        $current = $initial;
        $order = array_map('strval', array_keys($builder->placements()));
        $limit = max(1, $this->maxIdleIterations ?? count($order));
        $accepted = ['move' => 0, 'swap' => 0, 'room' => 0, 'shift' => 0];
        $teams = [];

        foreach ($order as $groupId) {
            $teacherId = $builder->teacherOf($groupId);

            if ($teacherId !== null) {
                $teams[$teacherId][] = $groupId;
            }
        }

        $anchors = [];

        foreach ($teams as $team) {
            if (count($team) > 1) {
                $anchors[$team[0]] = $team;
            }
        }

        $iterations = 0;
        $evaluated = 0;
        $idle = 0;
        $timedOut = false;
        $started = $clock();
        $reported = 0;
        $progress?->__invoke(0, $initial);

        while ($order !== []) {
            foreach ($order as $groupId) {
                $now = $clock();

                if ($now >= $deadline) {
                    $timedOut = true;

                    break 2;
                }

                $iterations++;
                $move = $this->improve($builder, $score, $groupId, $anchors[$groupId] ?? [], $evaluated);

                if ($move === null) {
                    $idle++;
                } else {
                    $accepted[$move[0]]++;
                    $current += $move[1];
                    $idle = 0;
                }

                $percent = min(100, (int) floor(100 * max($idle / $limit, ($now - $started) / max(1e-9, $deadline - $started))));

                if ($progress !== null && $percent > $reported) {
                    $progress($percent, $current);
                    $reported = $percent;
                }

                if ($idle >= $limit) {
                    break 2;
                }
            }
        }

        return new SearchResult($builder->sessions(), $iterations, $evaluated, $accepted, $initial, $current, $timedOut, $limit);
    }

    /**
     * Revisa los movimientos de un grupo y aplica el que más baja el puntaje; si ninguno lo baja, lo deja como estaba.
     * Devuelve el tipo y el delta del movimiento aplicado, o null.
     *
     * @param  list<string>  $team  si el grupo es el primero de su docente, todos los de ese docente (para desplazarlos)
     * @return array{'move'|'swap'|'room'|'shift', int}|null
     */
    private function improve(Builder $builder, LocalScore $score, string $groupId, array $team, int &$evaluated): ?array
    {
        $current = $builder->placementOf($groupId);
        $builder->release($groupId);
        [$moves, $blocked] = $builder->alternatives($groupId);
        $best = null;
        $bestDelta = 0;

        foreach ([...$moves, ...$builder->roomAlternatives($groupId, $current)] as $placement) {
            if ([$placement['option'], $placement['start'], $placement['rooms']] === [$current['option'], $current['start'], $current['rooms']]) {
                continue;
            }

            $evaluated++;
            $delta = $score->delta([$groupId => $builder->sessionsFor($groupId, $placement)]);

            if ($delta < $bestDelta) {
                $kind = [$placement['option'], $placement['start']] === [$current['option'], $current['start']] ? 'room' : 'move';
                [$best, $bestDelta] = [[$kind, [$groupId => $placement]], $delta];
            }
        }

        foreach (array_unique(array_merge([], ...array_values($blocked))) as $other) {
            $theirs = $builder->placementOf($other);

            if ($theirs['start'] === $current['start'] || $builder->campusOf($other) !== $builder->campusOf($groupId)) {
                continue;
            }

            $builder->release($other);
            $mine = $builder->placementAt($groupId, $current['option'], $theirs['start'], $current['tier']);
            $swapped = null;

            if ($mine !== null) {
                $builder->assign($groupId, $mine);
                $swapped = $builder->placementAt($other, $theirs['option'], $current['start'], $theirs['tier']);
                $builder->release($groupId);
            }

            $builder->assign($other, $theirs);

            if ($mine === null || $swapped === null) {
                continue;
            }

            $evaluated++;
            $delta = $score->delta([$groupId => $builder->sessionsFor($groupId, $mine), $other => $builder->sessionsFor($other, $swapped)]);

            if ($delta < $bestDelta) {
                [$best, $bestDelta] = [['swap', [$groupId => $mine, $other => $swapped]], $delta];
            }
        }

        if ($team !== []) {
            [$best, $bestDelta] = $this->shiftTeam($builder, $score, $team, $current, $best, $bestDelta, $evaluated);
        }

        if ($best === null) {
            $builder->assign($groupId, $current);

            return null;
        }

        [$kind, $placements] = $best;
        $changes = [];

        foreach (array_keys($placements) as $id) {
            if ((string) $id !== $groupId) {
                $builder->release((string) $id);
            }
        }

        foreach ($placements as $id => $placement) {
            $builder->assign((string) $id, $placement);
            $changes[(string) $id] = $builder->sessionsFor((string) $id, $placement);
        }

        $score->apply($changes);

        return [$kind, $bestDelta];
    }

    /**
     * Desplaza a todos los grupos movibles colocados de un docente las mismas horas, antes o después, cada uno en sus
     * días: mueve su bloque de clases sin abrirle huecos. El primero del equipo ya está liberado y su colocación es
     * `first`.
     *
     * @param  list<string>  $team
     * @param  array{option: array<int, int>, start: int, rooms: array<int, string|null>, practice: array<int, int>, tier: int}  $first
     * @param  array{string, array<string, array<string, mixed>>}|null  $best
     * @return array{array{string, array<string, array<string, mixed>>}|null, int}
     */
    private function shiftTeam(Builder $builder, LocalScore $score, array $team, array $first, ?array $best, int $bestDelta, int &$evaluated): array
    {
        $originals = [$team[0] => $first];

        foreach (array_slice($team, 1) as $id) {
            $originals[$id] = $builder->placementOf($id);
        }

        foreach (array_slice($team, 1) as $id) {
            $builder->release($id);
        }

        $reach = $builder->dayUnits($team[0]);

        for ($units = -$reach; $units <= $reach; $units++) {
            $placed = [];

            foreach ($units === 0 ? [] : $team as $id) {
                $placement = $builder->shifted($id, $originals[$id], $units);

                if ($placement === null) {
                    break;
                }

                $builder->assign($id, $placement);
                $placed[$id] = $placement;
            }

            if ($placed !== [] && count($placed) === count($team)) {
                $evaluated++;
                $changes = [];

                foreach ($placed as $id => $placement) {
                    $changes[(string) $id] = $builder->sessionsFor((string) $id, $placement);
                }

                $delta = $score->delta($changes);

                if ($delta < $bestDelta) {
                    [$best, $bestDelta] = [['shift', $placed], $delta];
                }
            }

            foreach (array_keys($placed) as $id) {
                $builder->release((string) $id);
            }
        }

        foreach (array_slice($team, 1) as $id) {
            $builder->assign($id, $originals[$id]);
        }

        return [$best, $bestDelta];
    }
}
