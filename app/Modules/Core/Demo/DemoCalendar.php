<?php

namespace App\Modules\Core\Demo;

use App\Modules\Core\Database\Seeders\DemoTermSeeder;
use App\Modules\Core\Enums\DemoCheckpoint;
use App\Modules\Core\Enums\Stage;
use Carbon\CarbonImmutable;
use LogicException;

/**
 * El calendario general de 20271 que deja la demo en un punto: la etapa del punto empezó ayer, así que hoy está
 * «En curso»; las anteriores ya terminaron y las siguientes vienen después, una tras otra, sin huecos ni traslapes,
 * con las duraciones del calendario demo (`DemoTermSeeder::CALENDAR_20271`). Antes de abrir el periodo, la validación
 * empieza en tres días. Las fechas solo informan (D4): ninguna regla rechaza nada por fecha.
 */
final class DemoCalendar
{
    /**
     * Días que faltan para la validación cuando el periodo todavía no se abre.
     */
    private const int DAYS_BEFORE_VALIDATION = 3;

    /**
     * @return array<string, array{starts_on: string, ends_on: string}> por `Stage->value`, en el orden de las etapas
     *
     * @throws LogicException con `All`, que conserva el calendario de la demo mezclada
     */
    public static function windows(DemoCheckpoint $target, CarbonImmutable $today): array
    {
        if ($target === DemoCheckpoint::All) {
            throw new LogicException('«todo» conserva el calendario de la demo mezclada.');
        }

        $stages = Stage::cases();
        $anchor = $target->stage() ?? Stage::CatalogValidation;
        $anchorStart = $target->stage() === null ? $today->addDays(self::DAYS_BEFORE_VALIDATION) : $today->subDay();

        $durations = [];
        foreach ($stages as $stage) {
            [$startsOn, $endsOn] = DemoTermSeeder::CALENDAR_20271[$stage->value];
            $durations[$stage->value] = (int) CarbonImmutable::parse($startsOn)->diffInDays(CarbonImmutable::parse($endsOn)) + 1;
        }

        $start = $anchorStart;
        foreach ($stages as $stage) {
            if ($stage === $anchor) {
                break;
            }

            $start = $start->subDays($durations[$stage->value]);
        }

        $windows = [];
        foreach ($stages as $stage) {
            $end = $start->addDays($durations[$stage->value] - 1);
            $windows[$stage->value] = ['starts_on' => $start->toDateString(), 'ends_on' => $end->toDateString()];
            $start = $end->addDay();
        }

        return $windows;
    }
}
