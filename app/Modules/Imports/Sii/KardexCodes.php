<?php

namespace App\Modules\Imports\Sii;

use App\Modules\Students\Enums\KardexResult;

/**
 * Códigos de `historia_alumno` del SII llevados al kárdex de Nexus (reglas-de-negocio.md, "Migración del kárdex del
 * SII").
 *
 * - La oportunidad sale del número del tipo de evaluación (K1, elegido el 9 de noviembre de 2026; qué significa cada
 *   código, supuesto por confirmar con Servicios Escolares): O1, R1 y E1 son la 1 (ordinaria, con su regularización y
 *   su extraordinario); O2, R2 y E2, la 2 (repetición); CE y EE, la 3 (curso o examen especial). RC, RU, AC, EA y CG
 *   son convalidaciones y acreditaciones: no gastan oportunidad y cuentan como 1.
 * - `estatus_materia` A es aprobada; R y P son reprobadas (P es la que se reprobó en ese periodo y se acreditó en
 *   otro posterior, `periodo_acredita_materia`).
 * - Una aprobada con 0 queda sin calificación: son equivalencias acreditadas sin número (K2, confirmado el 9 de
 *   noviembre de 2026); las de 1 a 69 conservan la suya.
 * - Un periodo es el código del SII, año y 1, 2 o 3, del año en que se fundó el ITCJ (1964) al próximo (supuesto por
 *   confirmar).
 */
final class KardexCodes
{
    public const int FIRST_YEAR = 1964;

    /** @var array<string, int> oportunidad por tipo de evaluación */
    private const array ATTEMPTS = [
        'O1' => 1, 'R1' => 1, 'E1' => 1,
        'O2' => 2, 'R2' => 2, 'E2' => 2,
        'CE' => 3, 'EE' => 3,
        'RC' => 1, 'RU' => 1, 'AC' => 1, 'EA' => 1, 'CG' => 1,
    ];

    /**
     * La oportunidad del tipo de evaluación, o null si el código no se conoce.
     */
    public static function attempt(string $type): ?int
    {
        return self::ATTEMPTS[strtoupper(trim($type))] ?? null;
    }

    /**
     * El resultado de `estatus_materia`, o null si no es A, R ni P.
     */
    public static function result(string $status): ?KardexResult
    {
        return match (strtoupper(trim($status))) {
            'A' => KardexResult::Aprobada,
            'R', 'P' => KardexResult::Reprobada,
            default => null,
        };
    }

    /**
     * La calificación que se guarda: null si viene vacía, no se entiende o es el 0 de una aprobada (K2).
     */
    public static function grade(string $value, KardexResult $result): ?int
    {
        $value = trim($value);

        if ($value === '' || ! self::readableGrade($value)) {
            return null;
        }

        $grade = (int) $value;

        return $grade === 0 && $result === KardexResult::Aprobada ? null : $grade;
    }

    /**
     * Si la calificación se entiende: vacía o un entero de 0 a 100.
     */
    public static function readableGrade(string $value): bool
    {
        $value = trim($value);

        return $value === '' || (preg_match('/^\d{1,3}$/', $value) === 1 && (int) $value <= 100);
    }

    /**
     * Si es un periodo del SII: el año, de 1964 al próximo, y 1 (Ene-Jun), 2 (verano) o 3 (Ago-Dic).
     */
    public static function term(string $value): bool
    {
        return preg_match('/^(\d{4})[123]$/', $value, $match) === 1
            && (int) $match[1] >= self::FIRST_YEAR
            && (int) $match[1] <= (int) date('Y') + 1;
    }
}
