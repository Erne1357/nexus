<?php

namespace App\Modules\Staffing\Support;

use App\Modules\AcademicLoad\Queries\PublishedSession;
use App\Modules\Scheduling\Models\GroupSession;
use App\Modules\Scheduling\Validation\Minutes;
use Illuminate\Support\Collection;

/**
 * Cómo se escribe el horario de un grupo para el docente: «Ma Vi 11:00–13:00» (los días que comparten horas van juntos),
 * de sus sesiones vivas o de las que congeló la versión publicada de su carga.
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
        return self::lines($sessions->map(fn (GroupSession $session): array => [$session->day, (string) $session->starts_at, (string) $session->ends_at])->values()->all());
    }

    /**
     * schedule() de las sesiones de un grupo publicado, tal como las congeló el snapshot.
     *
     * @param  list<PublishedSession>  $sessions
     * @return list<array{days: string, time: string}>
     */
    public static function published(array $sessions): array
    {
        return self::lines(array_map(fn (PublishedSession $session): array => [$session->day, $session->startsAt, $session->endsAt], $sessions));
    }

    /**
     * «7:00–9:00» de dos horas guardadas (`HH:MM` o `HH:MM:SS`).
     */
    public static function span(string $startsAt, string $endsAt): string
    {
        return Minutes::labelOf($startsAt).'–'.Minutes::labelOf($endsAt);
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

    /**
     * Una hora de la foto para leerla («07:00» → «7:00»); vacía si la foto no la trae.
     */
    private static function clock(string $time): string
    {
        return $time === '' ? '' : Minutes::labelOf($time);
    }

    /**
     * Las sesiones agrupadas por horas, por día y hora de inicio: los días que comparten horas van juntos.
     *
     * @param  list<array{0: int, 1: string, 2: string}>  $sessions  día, inicio y fin
     * @return list<array{days: string, time: string}>
     */
    private static function lines(array $sessions): array
    {
        usort($sessions, fn (array $a, array $b): int => [$a[0], Minutes::of($a[1])] <=> [$b[0], Minutes::of($b[1])]);

        $byTime = [];

        foreach ($sessions as [$day, $startsAt, $endsAt]) {
            $byTime[self::span($startsAt, $endsAt)][] = self::DAYS[$day] ?? (string) $day;
        }

        $labels = [];

        foreach ($byTime as $time => $days) {
            $labels[] = ['days' => implode(' ', $days), 'time' => $time];
        }

        return $labels;
    }
}
