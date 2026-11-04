<?php

namespace App\Modules\Core\Queries;

use App\Modules\Core\Enums\OrgUnitKind;
use Illuminate\Support\Str;

/**
 * Una caja del Organigrama (OrgChartQuery::chart()): la unidad, su titular vigente, cuántas personas cuelgan de ella y
 * sus hijas visibles, en orden.
 */
final readonly class OrgChartNode
{
    /**
     * @param  bool  $locked  es un departamento académico: se edita en Académico (AZ-S13)
     * @param  bool  $missingTitular  un departamento académico activo sin jefe vigente, o Subdirección Académica sin titular
     * @param  int  $people  usuarios distintos con adscripción o nombramiento vigente en la unidad o en su subárbol
     * @param  list<OrgChartNode>  $children
     */
    public function __construct(
        public string $id,
        public string $code,
        public string $name,
        public OrgUnitKind $kind,
        public bool $active,
        public bool $locked,
        public ?string $titular,
        public bool $missingTitular,
        public int $people,
        public array $children,
    ) {}

    /**
     * La clave y el nombre en minúsculas y sin acentos, para que el buscador del diagrama compare sin ir al servidor.
     */
    public function search(): string
    {
        return Str::lower(Str::ascii("{$this->code} {$this->name}"));
    }
}
