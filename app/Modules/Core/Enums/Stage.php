<?php

namespace App\Modules\Core\Enums;

/**
 * Etapa del periodo (`term_stages.stage`): cada paso de docs/proceso/proceso-propuesto.md que
 * tiene una ventana de fechas en el calendario del periodo. No incluye la etapa 0 (preparar el
 * periodo, que es cuando se arma este calendario), la 1 (importar catálogos, repetible en
 * cualquier momento) ni la 10 y 11 (inscripción, R3; se agregan en su fase).
 */
enum Stage: string
{
    /** 2. Validar catálogos por departamento. */
    case CatalogValidation = 'catalog_validation';

    /** 3. Propuesta por carrera: materias y grupos. */
    case Proposal = 'proposal';

    /** 4. Horarios y cupos por carrera. */
    case Scheduling = 'scheduling';

    /** 5. Asignación docente con ranking. */
    case Staffing = 'staffing';

    /** 6. Salones y acuerdos interdepartamentales. */
    case Rooms = 'rooms';

    /** 7. Cierre por departamento y aprobación. */
    case Closing = 'closing';

    /** 8. Publicación. */
    case Publication = 'publication';

    /** 9. Re-análisis con calificaciones (R2). */
    case Reanalysis = 'reanalysis';
}
