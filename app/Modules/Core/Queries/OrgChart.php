<?php

namespace App\Modules\Core\Queries;

/**
 * El Organigrama para pintar (OrgChartQuery::chart()): las raíces con sus subárboles, cuántas unidades inactivas hay
 * (para «Mostrar inactivas») y cuántos departamentos académicos activos no tienen jefe vigente.
 */
final readonly class OrgChart
{
    /**
     * @param  list<OrgChartNode>  $roots
     */
    public function __construct(
        public array $roots,
        public int $inactive,
        public int $headless,
    ) {}
}
