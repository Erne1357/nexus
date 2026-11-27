<?php

namespace App\Modules\Staffing\Actions;

use App\Modules\AcademicLoad\Models\AcademicLoad;
use App\Modules\Core\Models\User;
use App\Modules\Faculty\Models\Teacher;
use App\Modules\Scheduling\Models\Group;
use App\Modules\Scheduling\Rules\LoadEditability;
use App\Modules\Scheduling\Validation\ScheduleValidator;
use App\Modules\Staffing\Models\GroupTeacherAssignment;
use App\Modules\Staffing\Queries\SelectionGroupsQuery;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Asigna el docente de un grupo y deja la asignación en el historial (HC-16; ADR-0020).
 *
 * En una transacción bloquea el grupo y exige que la carga de su departamento esté en `staffing` o `adjusting`
 * (confirmado el 7 de noviembre de 2026), el docente activo y que las reglas duras del docente pasen (HC-01 y HC-09 por
 * ScheduleValidator::checkTeacher; el motivo no las salva, supuesto por confirmar). HC-08 ya no veta (tarea 1.50,
 * confirmado el 26 de noviembre de 2026): pasar la banda de horas frente a grupo se permite y queda como aviso, con el
 * oficio de conformidad del docente pendiente; handleWithNotice() devuelve ese aviso, el mismo que dio checkTeacher. HC-16: si el docente no
 * ocupa el primer lugar del ranking del grupo, o no está en él, exige un motivo. Asignar al docente vigente no cambia
 * nada y devuelve su asignación (supuesto por confirmar). Si no, escribe con RecordTeacherAssignment: reemplaza la
 * asignación vigente, cambia `groups.teacher_id` con SetGroupTeacher, crea la nueva fila y deja bitácora con el lugar y
 * el motivo. Quién puede lo decide GroupTeacherAssignmentPolicy::create antes de llamarla.
 *
 * Con una selección viva (publicada, en curso o en ronda extra) o ya terminada que incluye la carga del grupo, el orden
 * de selección reemplaza al ranking: HC-16 no se exige (diseño 1.33, 6.7, supuesto por confirmar; ya terminada, decisión
 * del coordinador del riel del 24 de noviembre de 2026). Sin motivo, el historial guarda SELECTION_REASON: la base exige
 * un motivo cuando el docente no es el primer lugar (`group_teacher_assignments_hc16_check`).
 */
final class AssignTeacher
{
    public const string SELECTION_REASON = 'Asignación durante la orden de selección';

    public function __construct(
        private readonly RecordTeacherAssignment $record,
        private readonly SelectionGroupsQuery $groups,
    ) {}

    /**
     * @throws ValidationException
     */
    public function handle(Group $group, Teacher $teacher, User $actor, ?string $reason = null): GroupTeacherAssignment
    {
        return $this->handleWithNotice($group, $teacher, $actor, $reason)[0];
    }

    /**
     * Lo mismo que handle(), con el aviso de HC-08 (tarea 1.50) que dio checkTeacher al asignar: «Ana Pérez pasa su banda
     * de horas frente a grupo (…). Requiere oficio de conformidad del docente (pendiente).», o null si no pasa su banda o
     * si el docente ya era el del grupo.
     *
     * @return array{0: GroupTeacherAssignment, 1: ?string}
     *
     * @throws ValidationException
     */
    public function handleWithNotice(Group $group, Teacher $teacher, User $actor, ?string $reason = null): array
    {
        $reason = filled($reason) ? trim($reason) : null;

        $editability = app(LoadEditability::class);

        return $editability->guard(fn (): array => DB::transaction(function () use ($group, $teacher, $actor, $reason, $editability): array {
            $load = $editability->lockLoad($group);
            $group->setRawAttributes($group->newQuery()->lockForUpdate()->findOrFail($group->getKey())->getAttributes(), sync: true);

            $this->ensureLoadAllowsChanges($load);

            $current = GroupTeacherAssignment::query()->current()->where('group_id', $group->id)->lockForUpdate()->first();

            if ($current !== null && $group->teacher_id === $teacher->id && $current->teacher_id === $teacher->id) {
                return [$current, null];
            }

            if (! $teacher->is_active || $teacher->trashed()) {
                throw ValidationException::withMessages(['teacher' => 'El docente no está activo.']);
            }

            [$position, $size] = $this->record->rankingPosition($group, $teacher);
            $inSelection = $this->groups->liveSelectionFor($load) !== null;

            // HC-16: el orden de una selección viva o ya terminada de la carga reemplaza al ranking.
            if ($position !== 1 && ($inSelection || $this->groups->finishedSelectionFor($load) !== null)) {
                $reason ??= self::SELECTION_REASON;
            }

            if ($position !== 1 && $reason === null) {
                throw ValidationException::withMessages(['reason' => $position === null
                    ? 'No está en el ranking del grupo: escribe el motivo (HC-16).'
                    : "Ocupa el lugar {$position} de {$size}: escribe por qué no eliges al primer lugar (HC-16)."]);
            }

            $validator = app(ScheduleValidator::class);
            $validator->forget();
            $result = $validator->checkTeacher($group, $teacher);

            if ($result->hasHard()) {
                throw ValidationException::withMessages(['teacher' => $result->messages()]);
            }

            $notice = $result->forRule('HC-08')[0]->message ?? null;

            return [$this->record->handle($group, $teacher, $actor, $reason, $position, $size), $notice];
        }), 'group');
    }

    /**
     * @throws ValidationException
     */
    private function ensureLoadAllowsChanges(?AcademicLoad $load): void
    {
        if ($load === null || ! $load->status->acceptsTeacherAssignment()) {
            $label = $load?->status->label() ?? 'sin carga';

            throw ValidationException::withMessages(['group' => "La carga está en {$label}: los docentes se asignan en Asignación, Ajustes o Re-análisis."]);
        }
    }
}
