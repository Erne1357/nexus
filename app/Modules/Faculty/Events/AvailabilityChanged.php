<?php

namespace App\Modules\Faculty\Events;

use App\Modules\Core\Models\Term;
use App\Modules\Faculty\Models\Teacher;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Cambió la disponibilidad de un docente en un periodo (SetTeacherAvailability). Staffing la lee para no asignarlo
 * en bloques no disponibles (HC-09). Sale después del commit.
 */
final class AvailabilityChanged implements ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    public function __construct(public Teacher $teacher, public Term $term) {}
}
