<?php

namespace App\Modules\Solver\Contract;

/** El tipo de una sesión propuesta. */
enum SessionKind: string
{
    case Theory = 'teoria';
    case Practice = 'practica';
    case Virtual = 'virtual';
}
