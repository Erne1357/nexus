<?php

namespace App\Modules\AcademicLoad\Enums;

use App\Modules\AcademicLoad\Rules\ProposalSuggestionState;
use App\Modules\Core\Enums\StatusTone;
use App\Modules\Demand\Enums\ForecastMethod;
use App\Modules\Demand\Models\DemandForecast;

/**
 * Por qué una fila de la propuesta pide revisar la proyección de Nexus en «Revisar proyección» (tarea 5.6, opción C
 * elegida por el usuario el 17 de noviembre de 2026; ProposalForecastRow). En el orden en que manda sobre las acciones:
 *
 * - `changed`: la corrigió y la proyección cambió después (SuggestionState::Changed): «Usar N» o «Mantener».
 * - `large_difference`: corregida y lo capturado se aparta del sugerido vigente (DemandSettings::isLargeDifference, en
 *   esperados o en grupos): «Usar sugerido» o «Dejar».
 * - `specialty`: sin tocar y es de una especialidad del plan: los elegibles dependen de quién la eligió. «Está bien».
 * - `no_uptake`: sin tocar, la proyección la marca para revisar (`needs_review`) y no es de especialidad: tiene elegibles
 *   pero nadie la tomó en los periodos recientes, así que se proyecta casi en cero (confirmado con el Riel A el 17 de
 *   noviembre de 2026). «Está bien».
 * - `no_history`: sin tocar y la proyección no tiene historia de la materia (tasa del departamento o ninguna). «Está bien».
 *
 * Especialidad, sin tomas y sin historia solo cuentan sin tocar: corregirla ya es revisarla (supuesto por confirmar).
 * `filter()` es el valor de `?motivo=` en la página.
 */
enum ForecastReason: string
{
    case Changed = 'changed';
    case LargeDifference = 'large_difference';
    case Specialty = 'specialty';
    case NoUptake = 'no_uptake';
    case NoHistory = 'no_history';

    /**
     * Los motivos de una fila, en este orden, con su estado frente a la proyección vigente (null si ya no hay) y si su materia
     * es de una especialidad del plan.
     *
     * @return list<self>
     */
    public static function for(ProposalSuggestionState $suggestion, ?DemandForecast $forecast, bool $specialty): array
    {
        $untouched = $suggestion->state === SuggestionState::Suggested;

        return array_values(array_filter([
            $suggestion->state === SuggestionState::Changed ? self::Changed : null,
            in_array($suggestion->state, [SuggestionState::Captured, SuggestionState::Changed], true) && ($suggestion->largeDifferenceExpected || $suggestion->largeDifferenceGroups) ? self::LargeDifference : null,
            $untouched && $specialty ? self::Specialty : null,
            $untouched && ! $specialty && $forecast?->needs_review === true ? self::NoUptake : null,
            $untouched && in_array($forecast?->method, [ForecastMethod::DepartmentFallback, ForecastMethod::NoHistory], true) ? self::NoHistory : null,
        ]));
    }

    public function label(): string
    {
        return match ($this) {
            self::Changed => 'La proyección cambió',
            self::LargeDifference => 'Se aparta del sugerido',
            self::Specialty => 'Especialidad',
            self::NoUptake => 'Sin tomas recientes',
            self::NoHistory => 'Sin historia',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Changed => 'o-arrow-path',
            self::LargeDifference => 'o-arrows-up-down',
            self::Specialty => 'o-academic-cap',
            self::NoUptake => 'o-user-minus',
            self::NoHistory => 'o-information-circle',
        };
    }

    public function tone(): StatusTone
    {
        return match ($this) {
            self::Changed => StatusTone::Info,
            self::LargeDifference => StatusTone::Warning,
            self::Specialty, self::NoUptake, self::NoHistory => StatusTone::Neutral,
        };
    }

    /**
     * El valor de `?motivo=` que elige este motivo.
     */
    public function filter(): string
    {
        return match ($this) {
            self::Changed => 'cambio',
            self::LargeDifference => 'aparta',
            self::Specialty => 'especialidad',
            self::NoUptake => 'sin-tomas',
            self::NoHistory => 'sin-historia',
        };
    }

    public static function fromFilter(string $filter): ?self
    {
        foreach (self::cases() as $reason) {
            if ($reason->filter() === $filter) {
                return $reason;
            }
        }

        return null;
    }
}
