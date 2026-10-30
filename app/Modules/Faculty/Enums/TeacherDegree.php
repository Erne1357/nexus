<?php

namespace App\Modules\Faculty\Enums;

/**
 * Último grado de estudios del docente (`teachers.degree`). Un valor desconocido en la importación queda
 * vacío (S6, supuesto por confirmar).
 */
enum TeacherDegree: string
{
    case Licenciatura = 'licenciatura';
    case Especialidad = 'especialidad';
    case Maestria = 'maestria';
    case Doctorado = 'doctorado';

    public function label(): string
    {
        return match ($this) {
            self::Licenciatura => 'Licenciatura',
            self::Especialidad => 'Especialidad',
            self::Maestria => 'Maestría',
            self::Doctorado => 'Doctorado',
        };
    }
}
