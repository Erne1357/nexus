<?php

namespace App\Modules\Core\Support;

use App\Modules\Core\Models\CampusProfile;

/**
 * La semana de un horario con el perfil del campus: la del editor de horarios (tarea 3.5; DESIGN.md, "Editor de
 * horarios") y la de la consulta publicada (tarea 4.4). Vive en Core para que la use cualquier módulo del mapa: una
 * columna por día del perfil del campus y un renglón por bloque, de `day_start` a `day_end`. Acomoda cada sesión en su día: desde qué renglón empieza
 * (`top`) y cuántos abarca (`span`), en bloques del perfil, y en qué carril va (`lane` de `lanes`) cuando otras del
 * mismo día coinciden con ella, para que se vean lado a lado. Es puro: no lee la base.
 */
final readonly class WeekGrid
{
    /** El color de una materia en `--chain`: los tokens de las cadenas de la retícula, uno por materia del semestre. */
    public const array CHAINS = [
        '[--chain:var(--color-chain-1)]', '[--chain:var(--color-chain-2)]', '[--chain:var(--color-chain-3)]',
        '[--chain:var(--color-chain-4)]', '[--chain:var(--color-chain-5)]', '[--chain:var(--color-chain-6)]',
        '[--chain:var(--color-chain-7)]', '[--chain:var(--color-chain-8)]', '[--chain:var(--color-chain-9)]',
        '[--chain:var(--color-chain-10)]', '[--chain:var(--color-chain-11)]', '[--chain:var(--color-chain-12)]',
    ];

    /**
     * Los rellenos de las celdas y bandas, con utilidades de los tokens: cabe en `success/10` con borde punteado; choca
     * en rayas `error`; no se puede, no disponible y bloqueado en las rayas `neutral` de la disponibilidad; preferido
     * en `success/15`.
     */
    public const array FILLS = [
        'fits' => 'border border-dashed border-success/55 bg-success/10 text-success',
        'clash' => 'bg-[image:repeating-linear-gradient(135deg,color-mix(in_oklab,var(--color-error)_18%,transparent)_0_3px,transparent_3px_8px)] text-error',
        'blocked' => 'bg-[image:repeating-linear-gradient(135deg,color-mix(in_oklab,var(--color-neutral)_9%,var(--color-base-100))_0_5px,color-mix(in_oklab,var(--color-neutral)_20%,var(--color-base-100))_5px_10px)] text-secondary-muted',
        'preferred' => 'bg-success/15 text-success',
    ];

    private const array NAMES = [1 => 'Lunes', 2 => 'Martes', 3 => 'Miércoles', 4 => 'Jueves', 5 => 'Viernes', 6 => 'Sábado', 7 => 'Domingo'];

    private const array SHORT = [1 => 'L', 2 => 'M', 3 => 'Mi', 4 => 'J', 5 => 'V', 6 => 'S', 7 => 'D'];

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
        $start = WeekTime::minutes($profile->dayStart);
        $end = WeekTime::minutes($profile->dayEnd);

        return self::build($profile->days, $start, $start + intdiv($end - $start, $profile->blockMinutes) * $profile->blockMinutes, $profile->blockMinutes);
    }

    /**
     * La misma semana extendida a lo que cae fuera de ella (la sesión de otro campus en la vista por docente): más
     * bloques antes o después, alineados a los del perfil, y los días que falten, en orden.
     *
     * @param  iterable<array{day: int, starts_at: string, ends_at: string}>  $ranges
     */
    public function covering(iterable $ranges): self
    {
        $start = $this->start;
        $end = $this->start + count($this->blocks) * $this->blockMinutes;
        $days = array_column($this->days, 'day');

        foreach ($ranges as $range) {
            while ($start > 0 && WeekTime::minutes($range['starts_at']) < $start) {
                $start -= $this->blockMinutes;
            }

            while ($end < 24 * 60 && WeekTime::minutes($range['ends_at']) > $end) {
                $end += $this->blockMinutes;
            }

            $days[] = $range['day'];
        }

        $days = array_values(array_unique($days));
        sort($days);

        return self::build($days, max(0, $start), min(24 * 60, $end), $this->blockMinutes);
    }

    /**
     * @param  list<int>  $days
     */
    private static function build(array $days, int $start, int $end, int $blockMinutes): self
    {
        $blocks = [];

        for ($minutes = $start; $minutes + $blockMinutes <= $end; $minutes += $blockMinutes) {
            $blocks[] = [
                'starts_at' => WeekTime::hhmm($minutes),
                'ends_at' => WeekTime::hhmm($minutes + $blockMinutes),
                'label' => WeekTime::clock($minutes),
            ];
        }

        $days = array_map(fn (int $day): array => ['day' => $day, 'name' => self::NAMES[$day] ?? (string) $day, 'short' => self::SHORT[$day] ?? (string) $day], $days);

        return new self($days, $blocks, $start, $blockMinutes);
    }

    /**
     * «7:00» de «07:00» (WeekTime::clock()).
     */
    public static function clock(string $time): string
    {
        return WeekTime::clock($time);
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
        $ranges = array_map(fn (array $item): array => [WeekTime::minutes($item['starts_at']), WeekTime::minutes($item['ends_at'])], array_values(array_filter($items, fn (array $item): bool => $item['day'] === $day)));
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
        $top = min($rows, max(0.0, (WeekTime::minutes($startsAt) - $this->start) / $this->blockMinutes));
        $bottom = min($rows, max($top, (WeekTime::minutes($endsAt) - $this->start) / $this->blockMinutes));

        return ['top' => $top, 'span' => $bottom - $top];
    }

    /**
     * Dónde cabe el grupo elegido, por día y bloque (GroupFitQuery): `fits` (cabe), `clash` (choca con una regla que
     * se guarda marcada), `blocked` (rompe una de `$unsaveable`, que la base no guarda) u `own` (el grupo ya tiene
     * sesión ahí, o no se edita); null sin grupo elegido.
     *
     * @param  array<string, array{fits: bool, rules: list<string>, messages: list<string>}>|null  $fit
     * @param  list<string>  $unsaveable
     * @return array<int, list<array{key: string, starts_at: string, ends_at: string, state: string, rules: list<string>, why: string}>>|null
     */
    public function cells(?array $fit, array $unsaveable): ?array
    {
        if ($fit === null) {
            return null;
        }

        $cells = [];

        foreach ($this->days as $day) {
            foreach ($this->blocks as $block) {
                $key = "{$day['day']}|{$block['starts_at']}";
                $cell = $fit[$key] ?? null;
                $cells[$day['day']][] = [
                    'key' => $key,
                    'starts_at' => $block['starts_at'],
                    'ends_at' => $block['ends_at'],
                    'state' => match (true) {
                        $cell === null => 'own',
                        $cell['fits'] => 'fits',
                        array_intersect($cell['rules'], $unsaveable) !== [] => 'blocked',
                        default => 'clash',
                    },
                    'rules' => $cell['rules'] ?? [],
                    'why' => $cell === null ? '' : implode(' ', $cell['rules']).($cell['messages'] !== [] ? ' · '.implode(' ', $cell['messages']) : ''),
                ];
            }
        }

        return $cells;
    }

    /**
     * La única parada de tabulación de la semana: la primera celda que coloca o la primera sesión que se abre y no
     * queda bajo las celdas del grupo elegido (`covered`); si no hay ninguna, la primera celda que no se puede
     * (`blocked`, que también recorren las flechas para leer su motivo), así Tab siempre entra a la semana.
     *
     * @param  array<int, list<array{id: string, open: bool, covered?: bool}>>  $laid
     * @param  array<int, list<array{key: string, state: string}>>|null  $cells
     */
    public function tabStop(array $laid, ?array $cells, bool $canPlace): ?string
    {
        foreach ($this->days as $day) {
            foreach ($cells[$day['day']] ?? [] as $cell) {
                if ($canPlace && in_array($cell['state'], ['fits', 'clash'], true)) {
                    return 'cell:'.$cell['key'];
                }
            }

            foreach ($laid[$day['day']] ?? [] as $item) {
                if ($item['open'] && ! ($item['covered'] ?? false)) {
                    return 'session:'.$item['id'];
                }
            }
        }

        $blocked = $canPlace ? array_find(array_merge(...array_values($cells ?? [])), fn (array $cell): bool => $cell['state'] === 'blocked') : null;

        return $blocked === null ? null : 'cell:'.$blocked['key'];
    }

    /**
     * La lista de un día en celular: un renglón por bloque con las sesiones que empiezan en él. Sin grupo elegido, el
     * bloque que una sesión ya cubre no lleva renglón; con él, lo lleva todo bloque donde el grupo no tiene sesión,
     * aunque lo cubra la de otro grupo, para colocar ahí también (cabe, choca o no se puede).
     *
     * @param  list<array{top: float, span: float}>  $items
     * @param  list<array{starts_at: string, ends_at: string}>  $bands
     * @param  list<array{state: string}>|null  $cells
     * @return list<array{block: array{starts_at: string, ends_at: string, label: string}, items: list<array<string, mixed>>, cell: ?array<string, mixed>, band: ?array<string, mixed>}>
     */
    public function dayRows(array $items, array $bands, ?array $cells): array
    {
        $rows = [];

        foreach ($this->blocks as $index => $block) {
            $starting = array_values(array_filter($items, fn (array $item): bool => (int) floor($item['top']) === $index));
            $covered = array_any($items, fn (array $item): bool => $item['top'] < $index && $index < $item['top'] + $item['span']);
            $cell = $cells[$index] ?? null;
            $placeable = $cell !== null && $cell['state'] !== 'own';

            if ($starting === [] && $covered && ! $placeable) {
                continue;
            }

            $minutes = WeekTime::minutes($block['starts_at']);
            $rows[] = [
                'block' => $block,
                'items' => $starting,
                'cell' => $placeable ? $cell : null,
                'band' => array_find($bands, fn (array $band): bool => WeekTime::minutes($band['starts_at']) <= $minutes && $minutes < WeekTime::minutes($band['ends_at'])),
            ];
        }

        return $rows;
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
            $start = WeekTime::minutes($item['starts_at']);
            $end = WeekTime::minutes($item['ends_at']);

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
