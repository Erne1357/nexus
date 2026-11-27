<?php

namespace App\Modules\Staffing\Actions\Selection;

use App\Modules\AcademicLoad\Models\AcademicLoad;
use App\Modules\Core\Models\User;
use App\Modules\Facilities\Models\Room;
use App\Modules\Faculty\Models\Teacher;
use App\Modules\Scheduling\Actions\Sessions\AssignSessionRoom;
use App\Modules\Scheduling\Enums\ConflictSeverity;
use App\Modules\Scheduling\Enums\GroupStatus;
use App\Modules\Scheduling\Enums\SessionKind;
use App\Modules\Scheduling\Models\Group;
use App\Modules\Scheduling\Models\GroupSession;
use App\Modules\Scheduling\Rules\LoadEditability;
use App\Modules\Scheduling\Validation\ScheduleValidator;
use App\Modules\Scheduling\Validation\Violation;
use App\Modules\Staffing\Actions\RecordTeacherAssignment;
use App\Modules\Staffing\Actions\Selection\Concerns\GuardsSelectionTurn;
use App\Modules\Staffing\Models\GroupTeacherAssignment;
use App\Modules\Staffing\Models\Selection;
use App\Modules\Staffing\Models\SelectionEntry;
use App\Modules\Staffing\Queries\SelectionGroupsQuery;
use App\Modules\Staffing\Queries\SelectionRoomOptionsQuery;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * El docente toma un grupo en su turno de la orden de selección (tarea 1.33, diseño 6.5). Quién puede lo decide
 * SelectionPolicy::pickOwn antes de llamarla.
 *
 * En una transacción, en este orden: el candado consultivo de la selección (las elecciones de una selección van de una
 * en una, también las de quienes comparten turno), su turno, la carga en compartido y el grupo bloqueado (el orden de
 * LoadEditability, como AssignTeacher). El grupo es de una carga incluida, no está cancelado y no tiene docente: si ya
 * lo tiene, «Este grupo ya fue asignado.». HC-01 y HC-09 del validador son duros; HC-08 no veta (SEL-05: la banda de ±2 con la
 * casilla lo reemplaza al terminar). El salón debe ser elegible (SEL-01 a SEL-04, SelectionRoomOptionsQuery); sin
 * salón, solo si no hay ninguno elegible (supuesto por confirmar), y el jefe se lo asigna. Escribe con
 * RecordTeacherAssignment, con el motivo «Orden de selección, turno N», y pone el salón en todas las sesiones no
 * virtuales del grupo (AssignSessionRoom::handleMany; un salón por grupo, supuesto por confirmar).
 */
final class PickSelectionGroup
{
    use GuardsSelectionTurn;

    /** Las reglas del docente que vetan una elección. */
    private const array HARD_RULES = ['HC-01', 'HC-09'];

    public function __construct(
        private readonly RecordTeacherAssignment $record,
        private readonly AssignSessionRoom $rooms,
        private readonly SelectionGroupsQuery $groups,
        private readonly SelectionRoomOptionsQuery $options,
        private readonly LoadEditability $editability,
    ) {}

    /**
     * @throws ValidationException
     */
    public function handle(Selection $selection, Teacher $teacher, Group $group, ?Room $room, User $actor): GroupTeacherAssignment
    {
        return $this->editability->guard(fn (): GroupTeacherAssignment => DB::transaction(function () use ($selection, $teacher, $group, $room, $actor): GroupTeacherAssignment {
            $selection = $this->lockSelection($selection);
            $entry = $this->entryInTurn($selection, $teacher, $actor);

            $load = $this->editability->lockLoad($group);
            $group->setRawAttributes($group->newQuery()->lockForUpdate()->findOrFail($group->getKey())->getAttributes(), sync: true);

            $this->ensureFree($selection, $group, $load);

            if (! $teacher->is_active || $teacher->trashed()) {
                throw ValidationException::withMessages(['teacher' => 'El docente no está activo.']);
            }

            $this->ensureTeacherRules($group, $teacher);
            $this->ensureRoom($selection, $group, $room);

            [$position, $size] = $this->record->rankingPosition($group, $teacher);
            $assignment = $this->record->handle($group, $teacher, $actor, self::reasonFor($entry), $position, $size);

            if ($room !== null) {
                $sessions = GroupSession::query()->where('group_id', $group->id)->where('kind', '!=', SessionKind::Virtual->value)->pluck('id');
                $this->rooms->handleMany($group, $sessions->mapWithKeys(fn (string $id): array => [$id => $room])->all(), $actor);
            }

            return $assignment;
        }), 'group', 'Otra persona está cambiando este grupo; intenta de nuevo.');
    }

    /**
     * El motivo del historial de lo que el docente toma en su turno; ReleaseSelectionGroup lo usa para saber que el grupo
     * lo tomó él.
     */
    public static function reasonFor(SelectionEntry $entry): string
    {
        return "Orden de selección, turno {$entry->position}";
    }

    /**
     * @throws ValidationException
     */
    private function ensureFree(Selection $selection, Group $group, ?AcademicLoad $load): void
    {
        if ($load === null || $group->status === GroupStatus::Cancelled || ! $this->groups->includesLoad($selection, $load)) {
            throw ValidationException::withMessages(['group' => 'El grupo no es de esta selección.']);
        }

        if (! $load->status->acceptsTeacherAssignment()) {
            throw ValidationException::withMessages(['group' => "La carga está en {$load->status->label()}: los docentes se asignan en Asignación, Ajustes o Re-análisis."]);
        }

        if ($group->teacher_id !== null) {
            throw ValidationException::withMessages(['group' => 'Este grupo ya fue asignado.']);
        }
    }

    /**
     * HC-01 (cruce con lo suyo, en cualquier campus) y HC-09 (su disponibilidad).
     *
     * @throws ValidationException
     */
    private function ensureTeacherRules(Group $group, Teacher $teacher): void
    {
        $validator = app(ScheduleValidator::class);
        $validator->forget();

        $blocking = array_filter(
            $validator->checkTeacher($group, $teacher)->violations(),
            fn (Violation $violation): bool => $violation->severity === ConflictSeverity::Hard && in_array($violation->rule, self::HARD_RULES, true),
        );

        if ($blocking !== []) {
            throw ValidationException::withMessages(['teacher' => array_values(array_map(fn (Violation $violation): string => $violation->message, $blocking))]);
        }
    }

    /**
     * SEL-01 a SEL-04 en el servidor: el salón está entre los elegibles; sin salón, solo si no hay ninguno.
     *
     * @throws ValidationException
     */
    private function ensureRoom(Selection $selection, Group $group, ?Room $room): void
    {
        $options = $this->options->forGroup($selection, $group);
        $eligible = array_column($options['eligible'], 'id');

        if ($room === null) {
            if ($eligible !== []) {
                throw ValidationException::withMessages(['room' => 'Elige un salón para el grupo.']);
            }

            return;
        }

        if (in_array($room->getKey(), $eligible, true)) {
            return;
        }

        $reason = collect($options['rejected'])->firstWhere('id', $room->getKey())['reason'] ?? null;

        throw ValidationException::withMessages(['room' => $reason === null
            ? "El salón {$room->code} no se ofrece para este grupo."
            : "El salón {$room->code} no se puede elegir para este grupo: {$reason}."]);
    }
}
