<?php

namespace App\Modules\Faculty\Rules;

use App\Modules\Faculty\Enums\PositionKind;

/**
 * Las categorías que se ofrecen para una plaza docente (CV17, PL-01) y su clave del catálogo oficial
 * (`teacher_positions.category`, ej. `E3817`): `E3` + la jornada (8 tiempo completo, 7 tres cuartos, 6 medio tiempo,
 * 5 asignatura) + los dos dígitos de la categoría. A la persona se le muestra el nombre («Titular B»), nunca la clave.
 * Honorarios y apoyo no tienen categoría que se elija. Puro: no lee la base.
 *
 * - Carrera: asociado A/B/C 07/09/11, titular A/B/C 13/15/17, técnico docente asociado 37/39/41, técnico docente
 *   titular 43/45/75, investigador titular 59/61/63.
 * - Asignatura (`E35`): asignatura A/B/C 19/21/25, técnico docente de asignatura A/B/C 05/07/09.
 */
final class PositionCategories
{
    /** Los dígitos de la jornada en la clave. */
    private const array PREFIX = [
        'tiempo_completo' => 'E38',
        'tres_cuartos' => 'E37',
        'medio_tiempo' => 'E36',
        'asignatura' => 'E35',
    ];

    /** @var array<string, array<string, string>> nombre legible → dígitos, por tipo de plaza (carrera o asignatura) */
    private const array CAREER = [
        'Asociado A' => '07', 'Asociado B' => '09', 'Asociado C' => '11',
        'Titular A' => '13', 'Titular B' => '15', 'Titular C' => '17',
        'Técnico docente asociado A' => '37', 'Técnico docente asociado B' => '39', 'Técnico docente asociado C' => '41',
        'Técnico docente titular A' => '43', 'Técnico docente titular B' => '45', 'Técnico docente titular C' => '75',
        'Investigador titular A' => '59', 'Investigador titular B' => '61', 'Investigador titular C' => '63',
    ];

    private const array ASSIGNMENT = [
        'Asignatura A' => '19', 'Asignatura B' => '21', 'Asignatura C' => '25',
        'Técnico docente de asignatura A' => '05', 'Técnico docente de asignatura B' => '07', 'Técnico docente de asignatura C' => '09',
    ];

    /**
     * Las opciones de la plaza: `id` es la clave guardada y `name` lo que ve el jefe. Si la plaza ya trae una clave
     * que no está en el catálogo (importada), va al final como «clave actual» para no perderla al editar.
     *
     * @return list<array{id: string, name: string}>
     */
    public static function options(?PositionKind $kind, ?string $current = null): array
    {
        $options = [];

        foreach (self::names($kind) as $name => $digits) {
            $options[] = ['id' => self::PREFIX[$kind->value].$digits, 'name' => $name];
        }

        $current = trim((string) $current);

        if ($current !== '' && ! in_array($current, array_column($options, 'id'), true)) {
            $options[] = ['id' => $current, 'name' => "Clave actual {$current}"];
        }

        return $options;
    }

    /** La clave es una de las que se ofrecen para el tipo. */
    public static function allows(PositionKind $kind, string $category): bool
    {
        return in_array($category, array_column(self::options($kind), 'id'), true);
    }

    /** El nombre legible de la categoría de una plaza, o null si no se conoce. */
    public static function label(PositionKind $kind, ?string $category): ?string
    {
        foreach (self::options($kind) as $option) {
            if ($option['id'] === $category) {
                return $option['name'];
            }
        }

        return null;
    }

    /**
     * @return array<string, string>
     */
    private static function names(?PositionKind $kind): array
    {
        return match ($kind) {
            PositionKind::TiempoCompleto, PositionKind::TresCuartos, PositionKind::MedioTiempo => self::CAREER,
            PositionKind::Asignatura => self::ASSIGNMENT,
            default => [],
        };
    }
}
