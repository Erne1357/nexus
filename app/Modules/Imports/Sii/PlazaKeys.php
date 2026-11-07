<?php

namespace App\Modules\Imports\Sii;

use App\Modules\Faculty\Enums\PositionKind;

/**
 * Lee `docentes2.claves_plazas`: una o varias claves `<prefijo> <categoría><horas>.<número>` por renglón (por
 * ejemplo `1403 E352504.0100001`), separadas por espacios, comas, saltos de línea o el texto `\x09` que deja el
 * SII. Solo toma la categoría y las horas; el prefijo y el número de plaza no se guardan.
 *
 * El tipo sale de la categoría: E36xx medio tiempo, E37xx tres cuartos y E38xx tiempo completo, con las horas fijas
 * del tipo (20, 30 y 40; el número de la familia no son las horas, confirmado el 6 de noviembre de 2026); E35xx
 * asignatura, con sus horas en la clave, y las de dos letras (IA, IP, IS, IT…) de apoyo y asistencia, con jornada
 * de 36 horas si la clave no trae otras (supuestos por confirmar con Recursos Humanos, sacados de los datos del 5 de
 * noviembre de 2026). Otra categoría queda sin tipo, y una asignatura sin horas en la clave, sin horas.
 */
final class PlazaKeys
{
    /** Horas de la jornada de una plaza de apoyo cuya clave trae `00` (supuesto por confirmar). */
    public const int SUPPORT_HOURS = 36;

    private const string PATTERN = '/(?<![A-Z])(?<support>[A-Z]{2})(?<supportCategory>\d{5})\s?(?<supportHours>\d{2})|(?<![A-Z])(?<letter>[A-Z])(?<category>\d{4})\s?(?<hours>\d{2})/';

    /**
     * @return list<array{category: string, kind: PositionKind|null, hours: int|null}>
     */
    public static function parse(string $field): array
    {
        preg_match_all(self::PATTERN, self::normalize($field), $matches, PREG_SET_ORDER | PREG_UNMATCHED_AS_NULL);
        $plazas = [];

        foreach ($matches as $match) {
            if ($match['support'] !== null) {
                $hours = (int) $match['supportHours'];
                $plazas[] = ['category' => $match['support'].$match['supportCategory'], 'kind' => PositionKind::Apoyo, 'hours' => $hours > 0 ? $hours : self::SUPPORT_HOURS];

                continue;
            }

            $category = $match['letter'].$match['category'];
            $kind = self::teachingKind($category);
            $hours = match (true) {
                $kind === null => null,
                $kind === PositionKind::Asignatura => (int) $match['hours'] > 0 ? (int) $match['hours'] : null,
                default => $kind->fixedHours(),
            };
            $plazas[] = ['category' => $category, 'kind' => $kind, 'hours' => $hours];
        }

        return $plazas;
    }

    /**
     * Lo que queda del campo sin entender: quita las claves, los números de plaza (`.0100001`), los números sueltos
     * (prefijos como `1403`), las letras sueltas que el SII deja entre claves y los separadores. Vacío si todo se entendió. Puede traer datos de la plaza: no se imprime.
     */
    public static function leftover(string $field): string
    {
        $rest = preg_replace(self::PATTERN, ' ', self::normalize($field)) ?? '';
        $rest = preg_replace('/\.\s?\d*/', ' ', $rest) ?? '';
        $rest = preg_replace('/(?<![A-Z\d])(\d+|[A-Z])(?![A-Z\d])/', ' ', $rest) ?? '';

        return trim(preg_replace('/[\s,;\/\\\\]+/', ' ', $rest) ?? '');
    }

    private static function normalize(string $field): string
    {
        return mb_strtoupper(str_replace('\\x09', ' ', $field));
    }

    private static function teachingKind(string $category): ?PositionKind
    {
        return match (substr($category, 0, 3)) {
            'E35' => PositionKind::Asignatura,
            'E36' => PositionKind::MedioTiempo,
            'E37' => PositionKind::TresCuartos,
            'E38' => PositionKind::TiempoCompleto,
            default => null,
        };
    }
}
