<?php

namespace App\Modules\Academic\Enums;

/**
 * Tipo de espacio que pide una materia: el salón que un docente puede elegir al seleccionar su carga depende de él
 * (laboratorio solo en laboratorios, sala de cómputo solo en salas, el resto en aulas). Es de Academic y no `RoomType`
 * de Facilities, porque Academic no puede depender de Facilities. Nulo en la materia = sin revisar; las reglas de
 * salón lo tratan como aula.
 */
enum RequiredSpace: string
{
    case Aula = 'aula';
    case SalaComputo = 'sala_computo';
    case Laboratorio = 'laboratorio';

    public function label(): string
    {
        return match ($this) {
            self::Aula => 'Aula',
            self::SalaComputo => 'Sala de cómputo',
            self::Laboratorio => 'Laboratorio',
        };
    }
}
