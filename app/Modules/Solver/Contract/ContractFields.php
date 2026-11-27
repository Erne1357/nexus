<?php

namespace App\Modules\Solver\Contract;

use BackedEnum;

/**
 * Lectura tipada de los campos de un objeto JSON del contrato. Cada método recibe el arreglo, la llave y la ruta del
 * objeto, y lanza `ContractException` con la ruta completa del campo cuando algo no cuadra.
 */
final class ContractFields
{
    public static function path(string $base, string|int $key): string
    {
        if (is_int($key)) {
            return "{$base}[{$key}]";
        }

        return $base === '' ? $key : "{$base}.{$key}";
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function has(array $data, string $key): bool
    {
        return array_key_exists($key, $data) && $data[$key] !== null;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function string(array $data, string $key, string $path, bool $required = true): ?string
    {
        $at = self::path($path, $key);
        if (! self::has($data, $key)) {
            return $required ? throw ContractException::at($at, 'es obligatorio') : null;
        }
        if (! is_string($data[$key]) || $data[$key] === '') {
            throw ContractException::at($at, 'debe ser una cadena no vacía');
        }

        return $data[$key];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function int(array $data, string $key, string $path, int $min = 0, ?int $max = null, bool $required = true): ?int
    {
        $at = self::path($path, $key);
        if (! self::has($data, $key)) {
            return $required ? throw ContractException::at($at, 'es obligatorio') : null;
        }
        $value = $data[$key];
        if (! is_int($value) || $value < $min || ($max !== null && $value > $max)) {
            throw ContractException::at($at, self::intMessage($min, $max));
        }

        return $value;
    }

    private static function intMessage(int $min, ?int $max): string
    {
        if ($max !== null) {
            return "debe ser un entero entre {$min} y {$max}";
        }

        return "debe ser un entero de {$min} en adelante";
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function float(array $data, string $key, string $path): float
    {
        $at = self::path($path, $key);
        if (! self::has($data, $key)) {
            throw ContractException::at($at, 'es obligatorio');
        }
        if (! (is_int($data[$key]) || is_float($data[$key])) || $data[$key] < 0) {
            throw ContractException::at($at, 'debe ser un número de 0 en adelante');
        }

        return (float) $data[$key];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function bool(array $data, string $key, string $path, ?bool $default = null): bool
    {
        $at = self::path($path, $key);
        if (! self::has($data, $key)) {
            return $default ?? throw ContractException::at($at, 'es obligatorio');
        }
        if (! is_bool($data[$key])) {
            throw ContractException::at($at, 'debe ser verdadero o falso');
        }

        return $data[$key];
    }

    /**
     * @template T of BackedEnum
     *
     * @param  array<string, mixed>  $data
     * @param  class-string<T>  $enum
     * @return T
     */
    public static function enum(array $data, string $key, string $path, string $enum): BackedEnum
    {
        $value = self::string($data, $key, $path);
        $case = $enum::tryFrom($value);
        if ($case === null) {
            $allowed = implode(', ', array_map(fn (BackedEnum $c): string => (string) $c->value, $enum::cases()));

            throw ContractException::at(self::path($path, $key), "debe ser uno de: {$allowed}");
        }

        return $case;
    }

    /**
     * Una hora `HH:MM`.
     *
     * @param  array<string, mixed>  $data
     */
    public static function time(array $data, string $key, string $path): string
    {
        $value = self::string($data, $key, $path);
        if (! preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $value)) {
            throw ContractException::at(self::path($path, $key), 'debe ser una hora HH:MM');
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function object(array $data, string $key, string $path): array
    {
        $at = self::path($path, $key);
        if (! self::has($data, $key)) {
            throw ContractException::at($at, 'es obligatorio');
        }
        if (! is_array($data[$key]) || (array_is_list($data[$key]) && $data[$key] !== [])) {
            throw ContractException::at($at, 'debe ser un objeto');
        }

        return $data[$key];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return list<mixed>
     */
    public static function list(array $data, string $key, string $path, bool $required = true): array
    {
        $at = self::path($path, $key);
        if (! self::has($data, $key)) {
            return $required ? throw ContractException::at($at, 'es obligatorio') : [];
        }
        if (! is_array($data[$key]) || ! array_is_list($data[$key])) {
            throw ContractException::at($at, 'debe ser una lista');
        }

        return $data[$key];
    }

    /**
     * @template T of object
     *
     * @param  array<string, mixed>  $data
     * @param  class-string<T>  $class  Con `fromArray(array, string)`.
     * @return list<T>
     */
    public static function objects(array $data, string $key, string $path, string $class, bool $required = true): array
    {
        $result = [];
        foreach (self::list($data, $key, $path, $required) as $i => $item) {
            $at = self::path(self::path($path, $key), $i);
            if (! is_array($item)) {
                throw ContractException::at($at, 'debe ser un objeto');
            }
            $result[] = $class::fromArray($item, $at);
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return list<string>
     */
    public static function strings(array $data, string $key, string $path, bool $required = true): array
    {
        $result = [];
        foreach (self::list($data, $key, $path, $required) as $i => $item) {
            if (! is_string($item) || $item === '') {
                throw ContractException::at(self::path(self::path($path, $key), $i), 'debe ser una cadena no vacía');
            }
            $result[] = $item;
        }

        return $result;
    }

    /**
     * Un bloque de tiempo de `$startKey` a `$endKey`: el fin debe ser posterior al inicio.
     */
    public static function assertOrdered(string $start, string $end, string $path, string $endKey = 'ends_at'): void
    {
        if ($end <= $start) {
            throw ContractException::at(self::path($path, $endKey), 'debe ser posterior a '.$start);
        }
    }
}
