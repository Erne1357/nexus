<?php

namespace App\Modules\Staffing\Actions\Selection;

use App\Modules\Core\Models\User;
use App\Modules\Faculty\Models\Teacher;
use App\Modules\Scheduling\Actions\Sessions\AssignSessionRoom;
use App\Modules\Scheduling\Models\Group;
use App\Modules\Scheduling\Models\GroupSession;
use App\Modules\Scheduling\Rules\LoadEditability;
use App\Modules\Staffing\Actions\Selection\Concerns\GuardsSelectionTurn;
use App\Modules\Staffing\Actions\UnassignTeacher;
use App\Modules\Staffing\Models\Selection;
use App\Modules\Staffing\Queries\SelectionGroupsQuery;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * El docente suelta en su turno un grupo suyo de la selección (tarea 1.33, diseño 6.5): le quita los salones
 * (AssignSessionRoom::handleMany) y se quita como docente (UnassignTeacher, que deja el historial y la bitácora). Mismo
 * orden de candados que PickSelectionGroup. Después de terminar su carga ya no suelta nada. Quién puede lo decide
 * SelectionPolicy::pickOwn.
 */
final class ReleaseSelectionGroup
{
    use GuardsSelectionTurn;

    public const string REASON = 'Lo soltó en su turno de la orden de selección';

    public function __construct(
        private readonly UnassignTeacher $unassign,
        private readonly AssignSessionRoom $rooms,
        private readonly SelectionGroupsQuery $groups,
        private readonly LoadEditability $editability,
    ) {}

    /**
     * @throws ValidationException
     */
    public function handle(Selection $selection, Teacher $teacher, Group $group, User $actor): void
    {
        $this->editability->guard(fn () => DB::transaction(function () use ($selection, $teacher, $group, $actor): void {
            $selection = $this->lockSelection($selection);
            $this->entryInTurn($selection, $teacher);

            $load = $this->editability->lockLoad($group);
            $group->setRawAttributes($group->newQuery()->lockForUpdate()->findOrFail($group->getKey())->getAttributes(), sync: true);

            if (! $this->groups->includesLoad($selection, $load)) {
                throw ValidationException::withMessages(['group' => 'El grupo no es de esta selección.']);
            }

            if ($group->teacher_id !== $teacher->getKey()) {
                throw ValidationException::withMessages(['group' => 'Ese grupo no es tuyo.']);
            }

            $withRoom = GroupSession::query()->where('group_id', $group->id)->whereNotNull('room_id')->pluck('id');

            if ($withRoom->isNotEmpty()) {
                $this->rooms->handleMany($group, $withRoom->mapWithKeys(fn (string $id): array => [$id => null])->all(), $actor);
            }

            $this->unassign->handle($group, $actor, self::REASON);
        }), 'group', 'Otra persona está cambiando este grupo; intenta de nuevo.');
    }
}
