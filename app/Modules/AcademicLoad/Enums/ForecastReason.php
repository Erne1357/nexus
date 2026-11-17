<?php

namespace App\Modules\AcademicLoad\Enums;

use App\Modules\Core\Enums\StatusTone;

/**
 * Por qué una fila de la propuesta pide revisar la proyección de Nexus en «Revisar proyección» (tarea 5.6, opción C
 * elegida por el usuario el 17 de noviembre de 2026; ProposalForecastRow). En el orden en que manda sobre las acciones:
 *
 * - `changed`: la corrigió y la proyección cambió después (SuggestionState::Changed): «Usar N» o «Mantener».
 * - `large_difference`: corregida y lo capturado se aparta del sugerido vigente (DemandSettings::isLargeDifference, en
 *   esperados o en grupos): «Usar sugerido» o «Dejar».
 * - `specialty`: sin tocar y es de una especialidad del plan: los elegibles dependen de quién la eligió. «Está bien».
 * - `no_history`: sin tocar y la proyección no tiene historia de la materia (tasa del departamento o ninguna). «Está bien».
 *
 * Especialidad y sin historia solo cuentan sin tocar: corregirla ya es revisarla (supuesto por confirmar). `filter()` es
 * el valor de `?motivo=` en la página.
 */
enum ForecastReason: string
{
    case Changed = 'changed';
    case LargeDifference = 'large_difference';
    case Specialty = 'specialty';
    case NoHistory = 'no_history';

    public function label(): string
    {
        return match ($this) {
            self::Changed => 'La proyección cambió',
            self::LargeDifference => 'Se aparta del sugerido',
            self::Specialty => 'Especialidad',
            self::NoHistory => 'Sin historia',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Changed => 'o-arrow-path',
            self::LargeDifference => 'o-exclamation-triangle',
            self::Specialty => 'o-academic-cap',
            self::NoHistory => 'o-information-circle',
        };
    }

    public function tone(): StatusTone
    {
        return match ($this) {
            self::Changed => StatusTone::Info,
            self::LargeDifference => StatusTone::Warning,
            self::Specialty, self::NoHistory => StatusTone::Neutral,
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
