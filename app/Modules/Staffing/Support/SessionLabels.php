<?php

namespace App\Modules\Staffing\Support;

use App\Modules\Scheduling\Models\GroupSession;
use App\Modules\Scheduling\Validation\Minutes;
use Illuminate\Support\Collection;

/**
 * Cómo se escribe el horario de un grupo para el docente: «Ma Vi 11:00–13:00» (los días que comparten horas van juntos).
 */
final class SessionLabels
{
    /** @var array<int, string> */
    public const array DAYS = [1 => 'Lu', 2 => 'Ma', 3 => 'Mi', 4 => 'Ju', 5 => 'Vi', 6 => 'Sá'];

    /** @var array<int, string> */
    public const array DAY_NAMES = [1 => 'Lunes', 2 => 'Martes', 3 => 'Miércoles', 4 => 'Jueves', 5 => 'Viernes', 6 => 'Sábado'];

    /**
     * @param  Collection<int, GroupSession>  $sessions
     * @return list<array{days: string, time: string}>
     */
    public static function schedule(Collection $sessions): array
    {
        $byTime = [];

        foreach ($sessions->sortBy([['day', 'asc'], ['starts_at', 'asc']]) as $session) {
            $byTime[self::time($session)][] = self::DAYS[$session->day] ?? (string) $session->day;
        }

        $labels = [];

        foreach ($byTime as $time => $days) {
            $labels[] = ['days' => implode(' ', $days), 'time' => $time];
        }

        return $labels;
    }

    public static function time(GroupSession $session): string
    {
        return Minutes::format(Minutes::of($session->starts_at)).'–'.Minutes::format(Minutes::of($session->ends_at));
    }
}
