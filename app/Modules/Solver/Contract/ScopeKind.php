<?php

namespace App\Modules\Solver\Contract;

/** Qué se programó: la carga de un departamento o la exploración de una carrera. */
enum ScopeKind: string
{
    case Department = 'department';
    case Career = 'career';
}
