<?php

namespace App\Modules\AcademicLoad\Enums;

/**
 * A quién va un aviso de la republicación de una carga (RA-05, tarea 8.4): al docente afectado, a los coordinadores de
 * una carrera afectada o a los jefes de otro departamento cuyos préstamos de salón o grupos compartidos se tocaron. Cada
 * uno recibe solo las líneas de los grupos que le tocan.
 */
enum RepublicationAudience: string
{
    case Teacher = 'teacher';
    case Career = 'career';
    case Department = 'department';
}
