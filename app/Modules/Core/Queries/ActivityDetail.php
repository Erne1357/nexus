<?php

namespace App\Modules\Core\Queries;

use Spatie\Activitylog\Models\Activity;

/**
 * Una entrada de la bitácora lista para el drawer de «Bitácora» (CF-S5): el registro afectado, el puesto con que actuó
 * la persona y, campo por campo, lo de antes y lo de después. Solo lectura.
 */
final readonly class ActivityDetail
{
    /**
     * @param  list<array{field: string, old: string, new: string}>  $changes  el nombre de la columna y los valores, ya legibles (ActivityAttributes)
     * @param  array<string, mixed>  $properties  lo de `properties` sin `acting_as` ni lo que ya salió en `$changes`
     */
    public function __construct(
        public Activity $activity,
        public string $typeLabel,
        public ?string $subjectName,
        public ?string $actingAs,
        public array $changes,
        public array $properties,
    ) {}
}
