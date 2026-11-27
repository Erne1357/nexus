<?php

namespace App\Modules\AcademicLoad\Rules;

use App\Modules\AcademicLoad\Enums\SuggestionState;
use App\Modules\Demand\Rules\DemandSettings;
use DateTimeInterface;

/**
 * El estado de una fila de la propuesta frente a la proyección vigente de Demand (reglas-de-negocio.md, «Propuesta de
 * carrera»; tarea 5.5, confirmado el 16 de noviembre de 2026). Es pura, sin base de datos: la pantalla y las Actions le
 * pasan lo de la fila y la fila vigente de la proyección.
 *
 * - `none`: sin proyección (ni vigente ni guardada en la fila).
 * - `suggested`: sin tocar (`captured_at` nulo); sigue sola a la proyección.
 * - `captured`: corregida y la proyección vigente es la que la fila guarda en `suggested_*`.
 * - `changed`: corregida y la proyección vigente ya no es la guardada. El aviso compara lo sugerido nuevo contra lo
 *   sugerido guardado, nunca contra lo capturado; `newExpected` y `newGroups` traen lo que sugiere ahora.
 *
 * En una fila corregida, la diferencia grande es DemandSettings::isLargeDifference del sugerido vigente contra lo
 * capturado, por separado en esperados y en grupos; solo resalta, no pide motivo ni bloquea el envío. Si la proyección
 * desapareció, el sugerido vigente es el guardado. En grupos, si la fila tiene paquetes (tarea 5.6b), se compara contra lo
 * sugerido efectivo de la fila (`$effectiveGroups`: los de paquetes más los sueltos sugeridos, SuggestedRowGroups, que
 * calcula ProposalForecastQuery::stateFor), porque los grupos de la fila los incluyen; «Cambió» sigue mirando lo sugerido
 * guardado contra la proyección cruda.
 */
final readonly class ProposalSuggestionState
{
    public function __construct(
        public SuggestionState $state,
        public ?int $newExpected = null,
        public ?int $newGroups = null,
        public bool $largeDifferenceExpected = false,
        public bool $largeDifferenceGroups = false,
        public ?int $effectiveGroups = null,
    ) {}

    /**
     * @param  int  $expected  `expected_students` de la fila
     * @param  int  $groups  `requested_groups` de la fila
     * @param  int|null  $suggestedExpected  `suggested_expected_students` de la fila
     * @param  int|null  $suggestedGroups  `suggested_groups` de la fila
     * @param  int|null  $forecastExpected  esperados de la proyección vigente, o null si no hay
     * @param  int|null  $forecastGroups  grupos sugeridos de la proyección vigente, o null si no hay
     * @param  int|null  $effectiveGroups  con paquetes, los grupos sugeridos efectivos de la fila (paquetes más sueltos); null sin paquetes
     */
    public static function of(
        int $expected,
        int $groups,
        ?int $suggestedExpected,
        ?int $suggestedGroups,
        ?DateTimeInterface $capturedAt,
        ?int $forecastExpected,
        ?int $forecastGroups,
        DemandSettings $settings,
        ?int $effectiveGroups = null,
    ): self {
        $hasForecast = $forecastExpected !== null && $forecastGroups !== null;
        $currentExpected = $hasForecast ? $forecastExpected : $suggestedExpected;
        $currentGroups = $hasForecast ? $forecastGroups : $suggestedGroups;

        if ($currentExpected === null || $currentGroups === null) {
            return new self(SuggestionState::None);
        }

        if ($capturedAt === null) {
            return new self(SuggestionState::Suggested, effectiveGroups: $effectiveGroups);
        }

        $largeExpected = $settings->isLargeDifference($currentExpected, $expected);
        $largeGroups = $settings->isLargeDifference($effectiveGroups ?? $currentGroups, $groups);

        if ($hasForecast && ($forecastExpected !== $suggestedExpected || $forecastGroups !== $suggestedGroups)) {
            return new self(SuggestionState::Changed, $forecastExpected, $forecastGroups, $largeExpected, $largeGroups, $effectiveGroups);
        }

        return new self(SuggestionState::Captured, null, null, $largeExpected, $largeGroups, $effectiveGroups);
    }
}
