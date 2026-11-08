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

    /**
     * La foto de las sesiones que guarda una solicitud de cambio (`change_requests.current`: día, inicio, fin y salón),
     * como «Lu Mi 7:00–9:00 · A-14»: los días con la misma hora y el mismo salón van juntos. La foto guarda `HH:MM`;
     * solo se escribe para leerse al mostrarla.
     *
     * @param  list<array{day?: int, starts_at?: string, ends_at?: string, room?: ?string}>  $sessions
     * @return list<array{days: string, time: string, room: ?string}>
     */
    public static function snapshot(array $sessions): array
    {
        usort($sessions, fn (array $a, array $b): int => [$a['day'] ?? 0, $a['starts_at'] ?? ''] <=> [$b['day'] ?? 0, $b['starts_at'] ?? '']);

        $lines = [];

        foreach ($sessions as $session) {
            $time = self::clock($session['starts_at'] ?? '').'–'.self::clock($session['ends_at'] ?? '');
            $room = $session['room'] ?? null;
            $key = $time.'|'.$room;
            $lines[$key] ??= ['days' => [], 'time' => $time, 'room' => $room];
            $lines[$key]['days'][] = self::DAYS[$session['day'] ?? 0] ?? (string) ($session['day'] ?? '');
        }

        return array_values(array_map(fn (array $line): array => ['days' => implode(' ', $line['days']), 'time' => $line['time'], 'room' => $line['room']], $lines));
    }

    public static function time(GroupSession $session): string
    {
        return Minutes::labelOf($session->starts_at).'–'.Minutes::labelOf($session->ends_at);
    }

    /**
     * Una hora de la foto para leerla («07:00» → «7:00»); vacía si la foto no la trae.
     */
    private static function clock(string $time): string
    {
        return $time === '' ? '' : Minutes::labelOf($time);
    }
}
