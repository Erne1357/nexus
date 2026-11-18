<?php

namespace App\Modules\AcademicLoad\Enums;

/**
 * Cómo está una fila de la propuesta frente a la proyección de Demand (tarea 5.5; ProposalSuggestionState).
 */
enum SuggestionState: string
{
    /** No hay proyección para la materia en ese campus. */
    case None = 'none';

    /** El coordinador no la ha tocado: sigue sola a la proyección. */
    case Suggested = 'suggested';

    /** El coordinador la corrigió y la proyección sigue siendo la que vio. */
    case Captured = 'captured';

    /** El coordinador la corrigió y la proyección cambió después: le toca decidir. */
    case Changed = 'changed';
}
