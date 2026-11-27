<?php

namespace App\Modules\Staffing\Queries;

use App\Modules\Academic\Models\Subject;

/**
 * Una materia de «Carga actual» con sus grupos a la vista.
 */
final readonly class CurrentLoadSubject
{
    /**
     * @param  list<CurrentLoadGroup>  $groups
     */
    public function __construct(public Subject $subject, public array $groups) {}
}
