<?php

namespace App\Modules\Staffing\Queries;

use App\Modules\Core\Authorization\AcademicLoadVisibility;
use App\Modules\Core\Authorization\Actor;
use App\Modules\Core\Models\Term;
use App\Modules\Faculty\Models\Teacher;

/**
 * Los periodos de «Mi carga» y de su Resumen (tareas 3.10 y 1.85): la regla vive aquí para que las dos pantallas
 * digan lo mismo (el Resumen nunca dice algo distinto de Mi carga). El periodo en curso es el de consulta de la
 * credencial y el de planeación es el activo para capturar carga (TP-03, TP-04, #755, confirmado el 20 de noviembre de
 * 2026).
 */
final class MyLoadTerms
{
    public function __construct(private readonly MyLoadQuery $query) {}

    /**
     * El periodo en curso (#755): el de consulta de la credencial, que por omisión es el que contiene hoy por sus
     * fechas y, entre semestres, el que acaba de terminar (TP-03, TP-04).
     */
    public function inProgress(Actor $context): ?Term
    {
        return $context->viewTerm() ?? $context->loadTerm();
    }

    /**
     * El periodo en planeación: el activo para capturar carga (TP-04).
     */
    public function planning(Actor $context): ?Term
    {
        return $context->loadTerm();
    }

    /**
     * Si se ofrece el periodo en planeación (supuesto por confirmar): la carga académica no está oculta para quien
     * mira (`hiddenFrom`, MN-05; el admin que ve lo oculto se rige por lo mismo), el periodo en planeación no es el que
     * está en curso y el docente ya tiene grupos de Nexus en él, asignados o publicados.
     */
    public function offersPlanning(Actor $context, Teacher $teacher): bool
    {
        $planning = $this->planning($context);
        $inProgress = $this->inProgress($context);

        return $planning !== null
            && $inProgress !== null
            && ! app(AcademicLoadVisibility::class)->hiddenFrom($context)
            && $planning->id !== $inProgress->id
            && $this->query->hasNexusGroups($teacher, $planning);
    }

    /**
     * El periodo que se ve: el que pide `$requested` si es el de planeación y se ofrece; si no, el periodo en curso.
     */
    public function selected(Actor $context, ?Teacher $teacher, string $requested): ?Term
    {
        $planning = $this->planning($context);

        if ($teacher !== null && $requested !== '' && $planning !== null && $requested === $planning->id && $this->offersPlanning($context, $teacher)) {
            return $planning;
        }

        return $this->inProgress($context);
    }
}
