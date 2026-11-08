<?php

namespace App\Modules\Scheduling\Rules;

use App\Modules\Scheduling\Validation\State\StateSession;

/**
 * Los pares de sesiones de grupos distintos que se empalman (el mismo día, con horas que se cruzan; dos horas contiguas
 * no), en las dos direcciones: [la mía, la otra] y [la otra, la mía], para reportar el choque en cada grupo. Lo
 * comparten los empalmes de docente (HC-01) y de salón (HC-02).
 */
final class OverlappingPairs
{
    /**
     * @param  list<StateSession>  $sessions
     * @param  callable(StateSession, StateSession): bool  $touches  si el par toca lo que se valida
     * @return iterable<array{StateSession, StateSession}>
     */
    public static function of(array $sessions, callable $touches): iterable
    {
        $count = count($sessions);

        for ($i = 0; $i < $count; $i++) {
            for ($j = $i + 1; $j < $count; $j++) {
                [$a, $b] = [$sessions[$i], $sessions[$j]];

                if ($a->groupId === $b->groupId || ! $a->overlaps($b) || ! $touches($a, $b)) {
                    continue;
                }

                yield [$a, $b];
                yield [$b, $a];
            }
        }
    }
}
