<?php

namespace App\Modules\Staffing\Queries;

use App\Modules\Faculty\Models\Teacher;
use App\Modules\Faculty\Queries\SelectionQueueMembership;
use App\Modules\Staffing\Enums\SelectionStatus;
use App\Modules\Staffing\Models\SelectionEntry;
use Illuminate\Database\Eloquent\Builder;

/**
 * SEL-13: el docente tiene renglón en la cola de una orden de selección en cualquier estado menos cancelada. Lo usa
 * Faculty (SelectionQueueMembership) para ofrecer el reenvío de la invitación a su correo personal.
 */
final class SelectionQueueMembershipQuery implements SelectionQueueMembership
{
    public function includes(Teacher $teacher): bool
    {
        return SelectionEntry::query()
            ->where('teacher_id', $teacher->getKey())
            ->whereHas('selection', fn (Builder $selection) => $selection->where('status', '!=', SelectionStatus::Cancelled->value))
            ->exists();
    }
}
