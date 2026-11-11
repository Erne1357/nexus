<?php

namespace App\Modules\Academic\Enums;

/**
 * Qué pasó con un requisito del SII que esperaba al quedar sus dos materias en la retícula (ApplyWaitingPrerequisites):
 * entró a la seriación, ya estaba (se queda con su tipo hasta la siguiente corrida del SII), no entró porque cerraría
 * un ciclo de seriación (sigue esperando) o no entró porque se quitó de la seriación en Nexus (queda aplicado, con nota).
 */
enum WaitingPrerequisiteOutcome: string
{
    case Entered = 'entered';
    case AlreadyThere = 'already_there';
    case Cycle = 'cycle';
    case RemovedInNexus = 'removed_in_nexus';
}
