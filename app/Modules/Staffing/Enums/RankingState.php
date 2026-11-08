<?php

namespace App\Modules\Staffing\Enums;

/**
 * En qué va el ranking de un grupo en la pantalla de asignación: listo (tiene renglones), pendiente (sin renglones y
 * el departamento no se ha recalculado desde que nació el grupo) o vacío (se recalculó y nadie entró al ranking).
 */
enum RankingState: string
{
    case Ready = 'ready';
    case Pending = 'pending';
    case Empty = 'empty';
}
