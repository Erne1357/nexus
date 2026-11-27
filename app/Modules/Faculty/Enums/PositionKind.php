<?php

namespace App\Modules\Faculty\Enums;

/**
 * Tipo de plaza (`teacher_positions.kind`) y las horas base que admite (S3, supuesto por confirmar):
 * tiempo completo 40, tres cuartos 30, medio tiempo 20, honorarios 0, y asignatura y apoyo de 1 a 40. Apoyo es la
 * plaza de personal de apoyo y asistencia a la educación (categorías de dos letras del SII); sus horas cuentan
 * como horas base igual que las de cualquier otra plaza (confirmado el 6 de noviembre de 2026).
 */
enum PositionKind: string
{
    case TiempoCompleto = 'tiempo_completo';
    case TresCuartos = 'tres_cuartos';
    case MedioTiempo = 'medio_tiempo';
    case Asignatura = 'asignatura';
    case Honorarios = 'honorarios';
    case Apoyo = 'apoyo';

    /** Horas base máximas de una plaza (modelo de datos). */
    public const int MAX_HOURS = 40;

    public function label(): string
    {
        return match ($this) {
            self::TiempoCompleto => 'Tiempo completo',
            self::TresCuartos => 'Tres cuartos',
            self::MedioTiempo => 'Medio tiempo',
            self::Asignatura => 'Asignatura',
            self::Honorarios => 'Honorarios',
            self::Apoyo => 'Apoyo y asistencia',
        };
    }

    /**
     * Las horas que el tipo fija, o null si el tipo las deja libres (asignatura y apoyo).
     */
    public function fixedHours(): ?int
    {
        return match ($this) {
            self::TiempoCompleto => 40,
            self::TresCuartos => 30,
            self::MedioTiempo => 20,
            self::Asignatura => null,
            self::Honorarios => 0,
            self::Apoyo => null,
        };
    }

    public function allows(int $hours): bool
    {
        return $this->fixedHours() === null
            ? $hours >= 1 && $hours <= self::MAX_HOURS
            : $hours === $this->fixedHours();
    }
}
