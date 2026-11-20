<?php

namespace App\Modules\Imports\Sii;

use Normalizer;

/**
 * Busca el RFC de una persona por su nombre, como lo imprime el organigrama oficial, entre el personal que el SII
 * exporta (`docentes`: nombre + apellidos; `docentes2`: nombre + apellido paterno + apellido materno). El nombre se
 * normaliza (mayúsculas, sin acentos ni puntuación, espacios juntos) y coincide con una persona del SII cuando cada
 * palabra suya está entre las palabras del nombre completo de esa persona. Con exactamente un RFC distinto da ese RFC;
 * con ninguno o con varios, nulo (nunca se adivina entre homónimos).
 */
final class StaffNames
{
    /** @var array<string, list<string>> palabras del nombre completo por RFC */
    private array $wordsByRfc = [];

    /**
     * @param  iterable<int, array<string, string>>  $staff  renglones de `docentes` (rfc, nombre_empleado, apellidos_empleado)
     * @param  iterable<int, array<string, string>>  $staff2  renglones de `docentes2` (rfc, nombre_empleado, apellidop, apellidom)
     */
    public function __construct(iterable $staff, iterable $staff2)
    {
        foreach ($staff as $row) {
            $this->add($row['rfc'], [$row['nombre_empleado'], $row['apellidos_empleado']]);
        }

        foreach ($staff2 as $row) {
            $this->add($row['rfc'], [$row['nombre_empleado'], $row['apellidop'], $row['apellidom']]);
        }
    }

    /**
     * @return string|null el RFC si exactamente una persona distinta coincide
     */
    public function rfcFor(string $name): ?string
    {
        $wanted = self::words($name);

        if ($wanted === []) {
            return null;
        }

        $matches = [];

        foreach ($this->wordsByRfc as $rfc => $words) {
            if (array_diff($wanted, $words) === []) {
                $matches[] = $rfc;
            }
        }

        return count($matches) === 1 ? $matches[0] : null;
    }

    /**
     * @return list<string> las palabras del nombre, en mayúsculas y sin acentos ni puntuación
     */
    public static function words(string $name): array
    {
        $decomposed = Normalizer::normalize($name, Normalizer::FORM_D);
        $plain = mb_strtoupper((string) preg_replace('/\p{Mn}+/u', '', $decomposed === false ? $name : $decomposed));

        return array_values(array_filter(preg_split('/[^\p{L}\p{N}]+/u', $plain) ?: [], fn (string $word): bool => $word !== ''));
    }

    /**
     * @param  list<string>  $parts
     */
    private function add(string $rfc, array $parts): void
    {
        $rfc = TeacherRoster::normalizeRfc($rfc);

        if ($rfc !== '') {
            $this->wordsByRfc[$rfc] = array_values(array_unique([...($this->wordsByRfc[$rfc] ?? []), ...self::words(implode(' ', $parts))]));
        }
    }
}
