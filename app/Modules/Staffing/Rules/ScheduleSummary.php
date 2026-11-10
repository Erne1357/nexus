<?php

namespace App\Modules\Staffing\Rules;

use App\Modules\Scheduling\Validation\Minutes;

/**
 * El horario de un grupo en una línea, para la pantalla de asignación: las sesiones con las mismas horas se juntan
 * en un bloque con sus días («L–V 12:00–13:00», «Ma/Ju 9:00–11:00»); tres o más días seguidos van con guion y los
 * demás con diagonal; varios bloques se separan con « · », por su primer día. También da las horas por semana. Puro.
 */
final readonly class ScheduleSummary
{
    private const array DAYS = [1 => 'L', 2 => 'Ma', 3 => 'Mi', 4 => 'Ju', 5 => 'V', 6 => 'S'];

    /**
     * @param  list<array{days: list<int>, starts_at: string, ends_at: string}>  $blocks
     */
    private function __construct(private array $blocks, private int $minutes) {}

    /**
     * @param  iterable<array{day: int, starts_at: string, ends_at: string}>  $sessions
     */
    public static function of(iterable $sessions): self
    {
        $blocks = [];
        $minutes = 0;

        foreach ($sessions as $session) {
            $startsAt = substr((string) $session['starts_at'], 0, 5);
            $endsAt = substr((string) $session['ends_at'], 0, 5);
            $minutes += self::minutes($endsAt) - self::minutes($startsAt);
            $blocks["{$startsAt}-{$endsAt}"] ??= ['days' => [], 'starts_at' => $startsAt, 'ends_at' => $endsAt];
            $blocks["{$startsAt}-{$endsAt}"]['days'][] = (int) $session['day'];
        }

        $blocks = array_map(function (array $block): array {
            $block['days'] = array_values(array_unique($block['days']));
            sort($block['days']);

            return $block;
        }, array_values($blocks));

        usort($blocks, fn (array $a, array $b): int => [$a['days'][0], $a['starts_at']] <=> [$b['days'][0], $b['starts_at']]);

        return new self($blocks, $minutes);
    }

    public function label(): string
    {
        if ($this->blocks === []) {
            return 'Sin horario';
        }

        return implode(' · ', array_map(
            fn (array $block): string => self::days($block['days']).' '.Minutes::labelOf($block['starts_at']).'–'.Minutes::labelOf($block['ends_at']),
            $this->blocks,
        ));
    }

    public function hours(): float
    {
        return $this->minutes / 60;
    }

    /**
     * Las horas por semana sin ceros de sobra: «5», «4.5».
     */
    public function hoursLabel(): string
    {
        return rtrim(rtrim(number_format($this->hours(), 1, '.', ''), '0'), '.');
    }

    /**
     * @param  list<int>  $days
     */
    private static function days(array $days): string
    {
        $count = count($days);

        if ($count >= 3 && $count - 1 === $days[$count - 1] - $days[0]) {
            return self::DAYS[$days[0]].'–'.self::DAYS[$days[$count - 1]];
        }

        return implode('/', array_map(fn (int $day): string => self::DAYS[$day] ?? (string) $day, $days));
    }

    private static function minutes(string $time): int
    {
        [$hours, $minutes] = array_map('intval', explode(':', $time) + [1 => 0]);

        return $hours * 60 + $minutes;
    }
}
