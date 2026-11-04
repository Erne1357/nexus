<?php

namespace App\Modules\Core\Queries;

use App\Modules\Core\Enums\AssignmentStatus;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\OrgUnit;
use Carbon\CarbonImmutable;

/**
 * El panel de la unidad elegida en el Organigrama (OrgChartQuery::detail()): su lugar, su titular o por qué no lo
 * tiene, los nombramientos vigentes o por empezar sobre ella, cuántas personas cuelgan de ella, sus hijas y, de
 * OrgUnitRules, por qué no se edita, no se mueve, no se desactiva, no se activa o no recibe hijas; y los puestos que
 * se nombran sobre ella con el motivo si no se puede.
 */
final readonly class OrgUnitDetail
{
    /**
     * @param  list<string>  $path  los nombres de la raíz a la madre
     * @param  array{name: string, userId: string, summary: string, from: CarbonImmutable}|null  $titular
     * @param  string|null  $titularNote  por qué no hay titular; nulo si lo hay
     * @param  list<array{id: string, name: string, userId: string, summary: string, status: AssignmentStatus, from: CarbonImmutable, to: ?CarbonImmutable, endBlocker: ?string, earliestLastDay: ?CarbonImmutable}>  $assignments
     * @param  list<OrgUnit>  $children  sin borrar, activas o no
     * @param  list<string>  $deactivationBlockers
     * @param  string|null  $childBlocker  por qué no recibe unidades hijas
     * @param  list<Role>  $appointRoles  los puestos que se nombran sobre la unidad
     * @param  string|null  $appointBlocker  por qué no se nombra aquí; nulo si sí
     */
    public function __construct(
        public OrgUnit $unit,
        public array $path,
        public ?array $titular,
        public ?string $titularNote,
        public array $assignments,
        public int $people,
        public array $children,
        public ?string $lockedReason,
        public ?string $moveBlocker,
        public array $deactivationBlockers,
        public ?string $activationBlocker,
        public ?string $childBlocker,
        public array $appointRoles,
        public ?string $appointBlocker,
    ) {}
}
