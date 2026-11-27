<?php

namespace App\Modules\CatalogValidation\Events;

use App\Modules\Core\Enums\StatusTone;
use App\Modules\Core\Models\Term;

/**
 * El tablero de avance de validación (tarea 2.5) pide el estado de la carga académica de cada departamento en las
 * modalidades dadas (la carga es por departamento y modalidad, M1). Evento con reporte de solo lectura
 * (docs/arquitectura/arquitectura.md, "Eventos con reporte"): CatalogValidation no conoce AcademicLoad, que está a su
 * derecha; su listener (ReportLoadStatuses) dice con `report()` el estado de cada carga, ya como etiqueta y tono de la
 * insignia, y el tablero lo lee con `for()`. Se despacha fuera de toda transacción y no escribe nada.
 */
final class LoadStatusesRequested
{
    /** @var array<string, array{label: string, tone: StatusTone}> por «departamento|modalidad» */
    private array $reports = [];

    /**
     * @param  list<string>  $modalityIds
     */
    public function __construct(public readonly Term $term, public readonly array $modalityIds) {}

    public function report(string $departmentId, string $modalityId, string $label, StatusTone $tone): void
    {
        $this->reports["{$departmentId}|{$modalityId}"] = ['label' => $label, 'tone' => $tone];
    }

    /**
     * @return array{label: string, tone: StatusTone}|null
     */
    public function for(string $departmentId, string $modalityId): ?array
    {
        return $this->reports["{$departmentId}|{$modalityId}"] ?? null;
    }
}
