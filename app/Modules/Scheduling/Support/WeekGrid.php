<?php

namespace App\Modules\Scheduling\Support;

use App\Modules\Core\Models\CampusProfile;
use App\Modules\Scheduling\Validation\Minutes;

/**
 * La semana del editor de horarios (tarea 3.5; DESIGN.md, "Editor de horarios"): una columna por día del perfil del
 * campus y un renglón por bloque, de `day_start` a `day_end`. Acomoda cada sesión en su día: desde qué renglón empieza
 * (`top`) y cuántos abarca (`span`), en bloques del perfil, y en qué carril va (`lane` de `lanes`) cuando otras del
 * mismo día coinciden con ella, para que se vean lado a lado. Es puro: no lee la base.
 */
final readonly class WeekGrid
{
    private const array NAMES = [1 => 'Lunes', 2 => 'Martes', 3 => 'Miércoles', 4 => 'Jueves', 5 => 'Viernes', 6 => 'Sábado'];

    private const array SHORT = [1 => 'L', 2 => 'M', 3 => 'Mi', 4 => 'J', 5 => 'V', 6 => 'S'];

    /**
     * @param  list<array{day: int, name: string, short: string}>  $days
     * @param  list<array{starts_at: string, ends_at: string, label: string}>  $blocks
     */
    private function __construct(
        public array $days,
        public array $blocks,
        private int $start,
        private int $blockMinutes,
    ) {}

    public static function of(CampusProfile $profile): self
    {
        $start = Minutes::of($profile->dayStart);
        $blocks = [];

        for ($minutes = $start; $minutes + $profile->blockMinutes <= Minutes::of($profile->dayEnd); $minutes += $profile->blockMinutes) {
            $blocks[] = [
                'starts_at' => Minutes::format($minutes),
                'ends_at' => Minutes::format($minutes + $profile->blockMinutes),
                'label' => self::clock(Minutes::format($minutes)),
            ];
        }

        $days = array_map(fn (int $day): array => ['day' => $day, 'name' => self::NAMES[$day] ?? (string) $day, 'short' => self::SHORT[$day] ?? (string) $day], $profile->days);

        return new self($days, $blocks, $start, $profile->blockMinutes);
    }

    /**
     * «7:00» de «07:00».
     */
    public static function clock(string $time): string
    {
        return (int) substr($time, 0, 2).substr($time, 2, 3);
    }

    /**
     * «7:00–9:00».
     */
    public static function range(string $startsAt, string $endsAt): string
    {
        return self::clock($startsAt).'–'.self::clock($endsAt);
    }

    /**
     * Las sesiones de cada día del perfil, por hora, con su renglón, cuántos abarca y su carril. Un carril se comparte
     * con las que ya terminaron; las que coinciden, aunque sea en cadena, son un racimo y todas sus sesiones miden
     * lo mismo (`lanes`). Lo que cae en un día fuera del perfil no se pinta.
     *
     * @template T of array{day: int, starts_at: string, ends_at: string}
     *
     * @param  list<T>  $items
     * @return array<int, list<T&array{top: float, span: float, lane: int, lanes: int}>>
     */
    public function lay(array $items): array
    {
        $byDay = array_fill_keys(array_column($this->days, 'day'), []);

        foreach ($items as $item) {
            if (array_key_exists($item['day'], $byDay)) {
                $byDay[$item['day']][] = $item;
            }
        }

        return array_map($this->lanesOf(...), $byDay);
    }

    /**
     * Cuántos carriles necesita cada día (al menos uno): el ancho de su columna.
     *
     * @param  array<int, list<array{lanes: int}>>  $laid
     * @return array<int, int>
     */
    public function lanes(array $laid): array
    {
        return array_map(fn (array $items): int => max([1, ...array_column($items, 'lanes')]), $laid);
    }

    /**
     * Las horas de un día que alguna sesión ocupa, sin contar dos veces las que coinciden.
     *
     * @param  list<array{day: int, starts_at: string, ends_at: string}>  $items
     */
    public function busyHours(array $items, int $day): int|float
    {
        $ranges = array_map(fn (array $item): array => [Minutes::of($item['starts_at']), Minutes::of($item['ends_at'])], array_values(array_filter($items, fn (array $item): bool => $item['day'] === $day)));
        sort($ranges);
        $minutes = 0;
        $reach = null;

        foreach ($ranges as [$start, $end]) {
            $from = $reach === null ? $start : max($start, $reach);
            $minutes += max(0, $end - $from);
            $reach = $reach === null ? $end : max($reach, $end);
        }

        return self::hours($minutes);
    }

    /**
     * Minutos en horas: enteras si caben, si no con un decimal.
     */
    public static function hours(int $minutes): int|float
    {
        return $minutes % 60 === 0 ? intdiv($minutes, 60) : round($minutes / 60, 1);
    }

    /**
     * El renglón donde empieza y cuántos abarca un rango, en bloques, dentro de la ventana del día.
     *
     * @return array{top: float, span: float}
     */
    public function position(string $startsAt, string $endsAt): array
    {
        $rows = (float) count($this->blocks);
        $top = min($rows, max(0.0, (Minutes::of($startsAt) - $this->start) / $this->blockMinutes));
        $bottom = min($rows, max($top, (Minutes::of($endsAt) - $this->start) / $this->blockMinutes));

        return ['top' => $top, 'span' => $bottom - $top];
    }

    /**
     * @param  list<array{day: int, starts_at: string, ends_at: string}>  $items
     * @return list<array<string, mixed>>
     */
    private function lanesOf(array $items): array
    {
        usort($items, fn (array $a, array $b): int => [$a['starts_at'], $b['ends_at']] <=> [$b['starts_at'], $a['ends_at']]);

        $laid = [];
        $cluster = [];
        $clusterEnd = 0;

        foreach ($items as $item) {
            $start = Minutes::of($item['starts_at']);
            $end = Minutes::of($item['ends_at']);

            if ($cluster !== [] && $start >= $clusterEnd) {
                array_push($laid, ...$this->close($cluster));
                $cluster = [];
            }

            $used = array_column(array_filter($cluster, fn (array $placed): bool => $placed['end'] > $start), 'lane');
            $lane = 0;

            while (in_array($lane, $used, true)) {
                $lane++;
            }

            $clusterEnd = $cluster === [] ? $end : max($clusterEnd, $end);
            $cluster[] = ['item' => $item, 'lane' => $lane, 'end' => $end];
        }

        array_push($laid, ...$this->close($cluster));

        return $laid;
    }

    /**
     * @param  list<array{item: array{starts_at: string, ends_at: string}, lane: int, end: int}>  $cluster
     * @return list<array<string, mixed>>
     */
    private function close(array $cluster): array
    {
        $lanes = max([0, ...array_column($cluster, 'lane')]) + 1;

        return array_map(fn (array $placed): array => [
            ...$placed['item'],
            ...$this->position($placed['item']['starts_at'], $placed['item']['ends_at']),
            'lane' => $placed['lane'],
            'lanes' => $lanes,
        ], $cluster);
    }
}
