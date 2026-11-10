<?php

namespace App\Modules\Imports\Enums;

/**
 * Qué fue de un renglón de la historia del SII en Nexus (`sii_verificacion.materias.diagnostico`, paso `verificacion` de
 * imports:migrate-sii). El valor es el texto que queda en la base; el orden de los casos es el de la evaluación y el del
 * resumen. Los que empiezan con «no pasó» no llegaron al kárdex de Nexus.
 */
enum VerificationDiagnosis: string
{
    /** Llegó a una materia de la retícula del plan del alumno, con los créditos del SII. */
    case Same = 'igual';

    /** Llegó a una materia pendiente del plan del alumno («Por validar»): cuenta en el avance. */
    case PlanPending = 'pendiente del plan: cuenta';

    /** Llegó a una materia que no está en la retícula ni en los pendientes del plan del alumno: no cuenta. */
    case OutsidePlan = 'fuera de la retícula del plan: no cuenta';

    /** El alumno aprobó la misma materia de Nexus con otra clave del SII: en Nexus cuenta una vez. */
    case SameSubject = 'otra clave del SII es la misma materia: cuenta una vez';

    /** Los créditos de la materia en la retícula del alumno en el SII no son los de la materia en Nexus. */
    case CreditsDiffer = 'créditos distintos';

    /** El número de control no está en Nexus. */
    case StudentMissing = 'alumno no está en Nexus';

    /** No pasó: otro renglón del mismo alumno y periodo llegó a la misma materia de Nexus, y el kárdex guarda uno. */
    case KeptOther = 'no pasó: el mismo alumno tiene la misma materia de Nexus en el mismo periodo (se queda uno)';

    /** No pasó: la clave no está en ninguna retícula del SII (`materias_carreras`). */
    case UnknownKey = 'no pasó: la clave del SII no está en ninguna retícula';

    /** No pasó y ninguna de las razones anteriores lo explica: es error. */
    case Unexplained = 'no pasó: sin explicar';
}
