<?php

namespace App\Modules\Core\Support;

use App\Modules\Core\Enums\StageStatus;
use App\Modules\Core\Queries\StageWindow;
use Carbon\CarbonImmutable;

/**
 * El acomodo de la línea de tiempo de las etapas de un periodo (DESIGN.md, "Línea de tiempo de etapas"), la misma
 * para la ficha del periodo y para el bloque «Calendario» de Inicio, que la pintan con `core::terms.timeline`. El
 * rango va del lunes de la semana del primer inicio al domingo de la semana del último fin (contando las
 * ventanas propias de las modalidades), con una columna por semana y los meses rotulados. Cada etapa lleva su barra
 * general y una barra delgada por cada modalidad con ventana propia (M4); las posiciones son porcentajes del rango y el
 * último día de una ventana cuenta completo. Solo calcula: no lee la base ni guarda estado.
 */
final readonly class StageTimeline
{
    private const array MONTHS = ['Ene', 'Feb', 'Mar', 'Abr', 'May', 'Jun', 'Jul', 'Ago', 'Sep', 'Oct', 'Nov', 'Dic'];

    private const array MONTH_NAMES = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];

    /**
     * @param  list<CarbonImmutable>  $weeks  el lunes de cada semana del rango
     * @param  list<array{label: string, short: string, full: string, days: int}>  $months  cada mes del rango (rótulo «Nov 2026», corto «Nov» y completo «noviembre 2026») y los días del rango que cubre
     * @param  float|null  $today  la marca de hoy, como porcentaje del rango; null si cae fuera de él
     * @param  list<array{window: StageWindow, left: float|null, width: float|null, modalities: list<array{code: string, window: StageWindow, left: float, width: float}>}>  $rows  una por etapa, en el orden en que llegaron
     */
    private function __construct(
        public ?CarbonImmutable $from,
        public ?CarbonImmutable $to,
        public int $days,
        public array $weeks,
        public array $months,
        public ?float $today,
        public array $rows,
    ) {}

    /**
     * @param  list<StageWindow>  $general  las ventanas efectivas sin modalidad, una por etapa
     * @param  array<string, list<StageWindow>>  $modalities  por clave de modalidad, solo sus ventanas propias
     */
    public static function build(array $general, array $modalities, CarbonImmutable $today): self
    {
        $dated = array_filter([...$general, ...array_merge([], ...array_values($modalities))], fn (StageWindow $window): bool => $window->startsOn !== null);

        if ($dated === []) {
            return new self(null, null, 0, [], [], null, array_map(
                fn (StageWindow $window): array => ['window' => $window, 'left' => null, 'width' => null, 'modalities' => []],
                $general,
            ));
        }

        $from = min(array_map(fn (StageWindow $window): CarbonImmutable => $window->startsOn, $dated))->startOfWeek();
        $to = max(array_map(fn (StageWindow $window): CarbonImmutable => $window->endsOn, $dated))->endOfWeek()->startOfDay();
        $days = (int) $from->diffInDays($to) + 1;

        $rows = array_map(function (StageWindow $window) use ($modalities, $from, $days): array {
            $own = [];

            foreach ($modalities as $code => $windows) {
                foreach ($windows as $candidate) {
                    if ($candidate->stage === $window->stage && $candidate->startsOn !== null) {
                        $own[] = ['code' => (string) $code, 'window' => $candidate, ...self::bar($candidate, $from, $days)];
                    }
                }
            }

            return ['window' => $window, ...($window->startsOn === null ? ['left' => null, 'width' => null] : self::bar($window, $from, $days)), 'modalities' => $own];
        }, $general);

        $offset = (int) $from->diffInDays($today->startOfDay(), false);

        return new self(
            $from,
            $to,
            $days,
            array_map(fn (int $week): CarbonImmutable => $from->addWeeks($week), range(0, intdiv($days, 7) - 1)),
            self::months($from, $days),
            $offset >= 0 && $offset < $days ? ($offset + 0.5) / $days * 100 : null,
            $rows,
        );
    }

    public function hasRange(): bool
    {
        return $this->from !== null;
    }

    /**
     * Las fechas de la ventana como «10–19 nov» o «20 nov – 4 dic»; el año solo si la ventana cruza de año.
     */
    public static function rangeLabel(StageWindow $window): string
    {
        if ($window->startsOn === null || $window->endsOn === null) {
            return 'Sin fecha';
        }

        return self::rangeBetween($window->startsOn, $window->endsOn);
    }

    /**
     * Dos fechas como «10–19 nov» o «20 nov – 4 dic»; el año solo si cruzan de año.
     */
    public static function rangeBetween(CarbonImmutable $start, CarbonImmutable $end): string
    {
        return match (true) {
            $start->isSameDay($end) => self::dateLabel($start),
            $start->year !== $end->year => self::dateLabel($start)." {$start->year} – ".self::dateLabel($end)." {$end->year}",
            $start->month === $end->month => "{$start->day}–".self::dateLabel($end),
            default => self::dateLabel($start).' – '.self::dateLabel($end),
        };
    }

    /**
     * Un día como «12 nov».
     */
    public static function dateLabel(CarbonImmutable $date): string
    {
        return $date->day.' '.mb_strtolower(self::MONTHS[$date->month - 1]);
    }

    /**
     * Cuántos días faltan, según el estado: «quedan 7 días», «último día» o «empieza en 5 días»; null si ya
     * terminó o no tiene fecha.
     */
    public static function daysLeftLabel(StageWindow $window): ?string
    {
        $days = $window->daysLeft;

        return match (true) {
            $days === null => null,
            $window->status === StageStatus::InProgress => match ($days) {
                0 => 'último día',
                1 => 'queda 1 día',
                default => "quedan {$days} días",
            },
            $window->status === StageStatus::Upcoming => $days === 1 ? 'empieza mañana' : "empieza en {$days} días",
            default => null,
        };
    }

    /**
     * @return array{left: float, width: float}
     */
    private static function bar(StageWindow $window, CarbonImmutable $from, int $days): array
    {
        return [
            'left' => (int) $from->diffInDays($window->startsOn) / $days * 100,
            'width' => ((int) $window->startsOn->diffInDays($window->endsOn) + 1) / $days * 100,
        ];
    }

    /**
     * @return list<array{label: string, short: string, full: string, days: int}>
     */
    private static function months(CarbonImmutable $from, int $days): array
    {
        $months = [];

        for ($day = 0; $day < $days; $day++) {
            $date = $from->addDays($day);
            $label = self::MONTHS[$date->month - 1].' '.$date->year;
            $last = array_key_last($months);

            if ($last !== null && $months[$last]['label'] === $label) {
                $months[$last]['days']++;
            } else {
                $months[] = [
                    'label' => $label,
                    'short' => self::MONTHS[$date->month - 1],
                    'full' => self::MONTH_NAMES[$date->month - 1].' '.$date->year,
                    'days' => 1,
                ];
            }
        }

        return $months;
    }
}
