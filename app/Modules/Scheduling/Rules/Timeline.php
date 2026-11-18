<?php

namespace App\Modules\Scheduling\Rules;

use App\Modules\Scheduling\Validation\State\StateSession;

/**
 * Las cuentas de tiempo que comparten las reglas: sesiones por día en orden (el traslado de SC-09 y los huecos y cambios
 * de edificio de las blandas), huecos entre sesiones, minutos fuera de unos bloques y horas de penalización.
 */
final class Timeline
{
    /**
     * Las horas de penalización de unos minutos: una fracción de hora cuenta como hora (supuesto por confirmar).
     */
    public static function hours(int $minutes): int
    {
        return $minutes <= 0 ? 0 : intdiv($minutes + 59, 60);
    }

    /**
     * Las sesiones por día, cada día en orden de inicio.
     *
     * @param  iterable<StateSession>  $sessions
     * @return array<int, list<StateSession>>
     */
    public static function byDay(iterable $sessions): array
    {
        $days = [];

        foreach ($sessions as $session) {
            $days[$session->day][] = $session;
        }

        foreach ($days as &$day) {
            usort($day, fn (StateSession $a, StateSession $b): int => [$a->start, $a->end] <=> [$b->start, $b->end]);
        }

        return $days;
    }

    /**
     * Los huecos entre las sesiones de un día (en orden de inicio): la sesión que termina más tarde antes del hueco, la
     * siguiente y los minutos libres entre ellas. Las sesiones que se empalman o se tocan no dejan hueco.
     *
     * @param  list<StateSession>  $sessions
     * @return iterable<array{StateSession, StateSession, int}>
     */
    public static function gaps(array $sessions): iterable
    {
        $latest = null;

        foreach ($sessions as $session) {
            if ($latest !== null && $session->start > $latest->end) {
                yield [$latest, $session, $session->start - $latest->end];
            }

            if ($latest === null || $session->end > $latest->end) {
                $latest = $session;
            }
        }
    }

    /**
     * Los minutos de la sesión que ningún bloque de su día cubre.
     *
     * @param  list<array{int, int, int}>  $blocks  [día, inicio, fin]
     */
    public static function uncovered(StateSession $session, array $blocks): int
    {
        $covered = [];

        foreach ($blocks as [$day, $start, $end]) {
            if ($day === $session->day && $start < $session->end && $end > $session->start) {
                $covered[] = [max($start, $session->start), min($end, $session->end)];
            }
        }

        usort($covered, fn (array $a, array $b): int => $a <=> $b);

        $minutes = 0;
        $reached = $session->start;

        foreach ($covered as [$start, $end]) {
            $minutes += max(0, $start - $reached);
            $reached = max($reached, $end);
        }

        return $minutes + max(0, $session->end - $reached);
    }

    /**
     * Los minutos en que dos sesiones se empalman (cero si son de días distintos).
     */
    public static function overlap(StateSession $a, StateSession $b): int
    {
        return $a->day === $b->day ? max(0, min($a->end, $b->end) - max($a->start, $b->start)) : 0;
    }
}
