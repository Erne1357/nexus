<?php

namespace App\Modules\Core\Rules;

use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\TermOpening;
use Carbon\CarbonImmutable;
use Carbon\Exceptions\InvalidFormatException;
use DateTimeInterface;
use Illuminate\Validation\ValidationException;

/**
 * Reglas de un periodo y de las ventanas de su calendario, que comparten sus Actions (docs/dominio/
 * modelo-de-datos.md, `terms` y `term_stages`). Cada regla rota es una ValidationException sobre su
 * campo.
 *
 * - `code`: código del SII, cuatro dígitos de año y un dígito de periodo (1 Ene-Jun, 2 Verano,
 *   3 Ago-Dic), único.
 * - `code`: ya no cambia una vez que el periodo se abrió en algún campus (supuesto por confirmar, docs/dominio/
 *   reglas-de-negocio.md, Periodo y calendario).
 * - Fechas: un periodo o una ventana no termina antes de empezar (`ends_on` >= `starts_on`).
 *   Una ventana no se compara con las fechas del periodo: la planeación ocurre antes de que empiece
 *   (ver docs/dominio/reglas-de-negocio.md, Periodo y calendario, supuesto por confirmar).
 */
final class TermRules
{
    private const SUFFIXES = ['1' => 'Ene-Jun', '2' => 'Verano', '3' => 'Ago-Dic'];

    /**
     * @throws ValidationException si no tiene la forma `AAAAP`
     */
    public function normalizeCode(string $code): string
    {
        $code = trim($code);

        if (preg_match('/^[0-9]{4}[123]$/', $code) !== 1) {
            throw ValidationException::withMessages([
                'code' => 'El código lleva el año y el periodo: 4 dígitos y 1 (Ene-Jun), 2 (Verano) o 3 (Ago-Dic), por ejemplo 20271.',
            ]);
        }

        return $code;
    }

    /**
     * El nombre que se sugiere para un código válido; null si el código no tiene la forma.
     */
    public function suggestedName(string $code): ?string
    {
        $code = trim($code);

        if (preg_match('/^[0-9]{4}[123]$/', $code) !== 1) {
            return null;
        }

        return self::SUFFIXES[$code[4]].' '.substr($code, 0, 4);
    }

    /**
     * Revisa un código ya normalizado; `$except` es el periodo que lo conserva al editarse.
     *
     * @throws ValidationException
     */
    public function ensureCodeIsFree(string $code, ?Term $except = null): void
    {
        $taken = Term::query()
            ->where('code', $code)
            ->when($except !== null, fn ($query) => $query->whereKeyNot($except->getKey()))
            ->exists();

        if ($taken) {
            throw $this->codeTaken($code);
        }
    }

    /**
     * Por qué el código del periodo ya no se puede cambiar: «El código ya no se puede cambiar: el periodo se abrió
     * en C1 y C2.»; null si no se ha abierto en ningún campus (también cuenta uno desactivado o borrado después).
     */
    public function codeLockReason(Term $term): ?string
    {
        $codes = TermOpening::query()
            ->where('term_id', $term->getKey())
            ->join('campuses', 'campuses.id', '=', 'term_openings.campus_id')
            ->orderBy('campuses.code')
            ->pluck('campuses.code')
            ->all();

        if ($codes === []) {
            return null;
        }

        $last = array_pop($codes);
        $list = $codes === [] ? $last : implode(', ', $codes).' y '.$last;

        return "El código ya no se puede cambiar: el periodo se abrió en {$list}.";
    }

    /**
     * Rechaza un código distinto al guardado cuando el periodo ya se abrió en algún campus; conservar el propio vale.
     *
     * @throws ValidationException
     */
    public function ensureCodeIsUnlocked(Term $term, string $code): void
    {
        if ($code === $term->code) {
            return;
        }

        $reason = $this->codeLockReason($term);

        if ($reason !== null) {
            throw ValidationException::withMessages(['code' => $reason]);
        }
    }

    /**
     * El error de código repetido, también para cuando la restricción única de la base gana la
     * carrera a ensureCodeIsFree().
     */
    public function codeTaken(string $code): ValidationException
    {
        return ValidationException::withMessages(['code' => "Ya existe un periodo con el código {$code}."]);
    }

    /**
     * Las fechas de un periodo o una ventana como `Y-m-d`.
     *
     * @return array{0: string, 1: string}
     *
     * @throws ValidationException
     */
    public function dates(mixed $startsOn, mixed $endsOn): array
    {
        $starts = $this->date('starts_on', $startsOn);
        $ends = $this->date('ends_on', $endsOn);

        if ($ends < $starts) {
            throw ValidationException::withMessages(['ends_on' => 'La fecha de fin no puede ser anterior a la de inicio.']);
        }

        return [$starts, $ends];
    }

    /**
     * Una fecha estricta: un DateTimeInterface o un texto `Y-m-d` que existe en el calendario (el 30 de febrero
     * no pasa a marzo). Vacío, nulo o «now» no son «hoy»: son error.
     *
     * @throws ValidationException
     */
    public function date(string $field, mixed $value): string
    {
        if ($value instanceof DateTimeInterface) {
            return CarbonImmutable::instance($value)->toDateString();
        }

        if ($value === null || (is_string($value) && trim($value) === '')) {
            throw ValidationException::withMessages([$field => 'Escribe la fecha.']);
        }

        try {
            $date = is_string($value) ? CarbonImmutable::createFromFormat('!Y-m-d', $value) : null;
        } catch (InvalidFormatException) {
            $date = null;
        }

        if ($date === null || $date === false || $date->format('Y-m-d') !== $value) {
            throw ValidationException::withMessages([$field => 'La fecha no es válida.']);
        }

        return $date->toDateString();
    }
}
