<?php

namespace App\Modules\Scheduling\Actions\Parallels;

use App\Modules\Academic\Models\Career;
use App\Modules\Academic\Models\Department;
use App\Modules\Academic\Models\Subject;
use App\Modules\AcademicLoad\Enums\AcademicLoadPermission;
use App\Modules\Core\Actions\NotifyHolders;
use App\Modules\Core\Authorization\Target;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Queries\RecipientsQuery;
use App\Modules\Scheduling\Events\ParallelGroupsArranged;
use App\Modules\Scheduling\Notifications\ParallelsArranged;

/**
 * Avisa por la campana que un departamento armó los paralelos de una materia (tarea 8.8): no hay acuerdo que aceptar, así
 * que se avisa (confirmado el 17 de noviembre de 2026). Por cada carrera cuyos lugares cambiaron, un aviso con sus grupos y
 * sus lugares antes y después (ParallelsArranged):
 *
 * - a sus coordinadores en la modalidad, de cualquier campus: los que pueden enviar su propuesta, como al regresarla
 *   (NotifyProposalReturned);
 * - al jefe del departamento de la carrera cuando no es el que imparte: los que decidían el grupo compartido
 *   (`academic_load.agreement.decide`, M12).
 *
 * Una persona con los dos puestos recibe uno por carrera. Una carrera sin cambios no recibe nada y no hay correo (supuestos
 * por confirmar). Da a cuántos usuarios avisó.
 */
final class NotifyParallelGroupsArranged
{
    public function __construct(private readonly RecipientsQuery $recipients, private readonly NotifyHolders $notifyHolders) {}

    public function handle(ParallelGroupsArranged $event): int
    {
        if ($event->careers === []) {
            return 0;
        }

        $department = Department::withTrashed()->findOrFail($event->departmentId);
        $subject = Subject::withTrashed()->findOrFail($event->subjectId);
        $campus = Campus::withTrashed()->findOrFail($event->campusId);
        $careers = Career::withTrashed()->whereIn('id', array_keys($event->careers))->get()->keyBy('id');
        $notified = 0;

        foreach ($event->careers as $careerId => $change) {
            $career = $careers->get($careerId);

            if ($career === null) {
                continue;
            }

            $holders = $this->recipients->holders(AcademicLoadPermission::ProposalSubmit, Target::of(modality: $event->modalityId, career: $careerId));

            if ($career->department_id !== $event->departmentId) {
                $holders = [...$holders, ...$this->recipients->holders(AcademicLoadPermission::AgreementDecide, Target::of(modality: $event->modalityId, unit: $career->department_id))];
            }

            $holders = array_values(collect($holders)->unique(fn (array $holder): string => $holder['user']->getKey())->all());
            $notice = new ParallelsArranged($event->termId, $event->modalityId, $department->name, "{$subject->code} {$subject->name}", $campus->code, $career->code, $change['before'], $change['after'], $change['group']);
            $notified += $this->notifyHolders->handle($holders, $notice)->count();
        }

        return $notified;
    }
}
