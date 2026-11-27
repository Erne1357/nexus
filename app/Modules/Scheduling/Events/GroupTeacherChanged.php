<?php

namespace App\Modules\Scheduling\Events;

use App\Modules\Scheduling\Models\Group;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Cambió el docente vigente de un grupo (SetGroupTeacher): se recalculan los choques del periodo y Staffing actualiza
 * la carga de los dos docentes. Sale después del commit.
 */
final class GroupTeacherChanged implements ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public Group $group,
        public ?string $oldTeacherId,
        public ?string $newTeacherId,
    ) {}
}
