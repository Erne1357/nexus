<?php

namespace App\Modules\Staffing\Actions;

use App\Modules\AcademicLoad\Enums\LoadStatus;
use App\Modules\Core\Models\Modality;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\User;
use App\Modules\Faculty\Models\Teacher;
use App\Modules\Scheduling\Enums\GroupStatus;
use App\Modules\Scheduling\Models\Group;
use App\Modules\Scheduling\Rules\LoadEditability;
use App\Modules\Scheduling\Validation\ScheduleValidator;
use App\Modules\Staffing\Actions\Selection\Concerns\LocksDraftSelections;
use App\Modules\Staffing\Actions\Selection\RecomputeSelectionOrder;
use App\Modules\Staffing\Models\PriorChoice;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * SEL-16 (tarea 1.41, decisión del usuario del 25 de noviembre de 2026): el docente que ya trae sus grupos escritos en la
 * relación de materias ya terminó y no recibe turno en la orden de selección. Esta Action le asigna esos grupos y deja su
 * marca de «Elección previa» para el periodo y la modalidad.
 *
 * En una transacción, para cada grupo: debe ser del periodo y la modalidad, no estar cancelado y estar libre o ya ser del
 * docente (si es de otro, se rechaza); HC-01 es duro (el docente no se empalma con otro grupo suyo del periodo, de
 * cualquier campus). Asigna con RecordTeacherAssignment y la razón REASON, y crea o actualiza la marca. Lo que ya es del
 * docente no se reasigna ni deja bitácora, así que repetir la llamada no duplica nada. Todo o nada.
 *
 * Es carga de datos, no la pantalla de asignación: por eso no usa AssignTeacher, que exige que la carga esté en
 * asignación o ajustes y el motivo de HC-16. Aquí la carga puede seguir en borrador (D4: los maestros la ven cuando el
 * admin publique la orden). HC-08 y HC-09 no vetan: las horas quedan justificadas al publicar la orden (SEL-16).
 * No toca salones: los pone el importador de Scheduling (1.40).
 *
 * Carga del grupo: la misma de 1.40 (borrador, propuesta, asignación, ajustes o re-análisis; `LoadEditability::lockLoad`
 * la toma FOR SHARE antes de bloquear el grupo); un grupo sin carga o con la carga en otro estado se rechaza.
 *
 * Ciclo de la selección: lo primero es el candado consultivo (`staffing_selection:{id}`, en orden de id) de cada
 * selección no cancelada del periodo y la modalidad cuyas cargas incluyen un departamento de los grupos. Si alguna no
 * es un borrador se rechaza todo (con la selección publicada o viva los turnos ya se avisaron). Si son borradores,
 * al final se recalcula su cola (RecomputeSelectionOrder) para que la vista previa del orden ya los muestre.
 * La marca guarda los departamentos de sus grupos: solo aplica en las selecciones de esos departamentos (supuesto por
 * confirmar, decisión del coordinador).
 */
final class RecordPriorChoice
{
    use LocksDraftSelections;

    public const string REASON = 'Elección previa a la orden de selección';

    public function __construct(
        private readonly RecordTeacherAssignment $record,
        private readonly ScheduleValidator $validator,
        private readonly LoadEditability $editability,
        private readonly RecomputeSelectionOrder $recompute,
    ) {}

    /** Los estados de la carga en que se registra una elección previa (los mismos que acepta la importación de 1.40). */
    private const array LOAD_STATES = [LoadStatus::Draft, LoadStatus::Proposed, LoadStatus::Staffing, LoadStatus::Adjusting, LoadStatus::Reanalysis];

    /**
     * @param  list<array{group: Group}>  $groups
     *
     * @throws ValidationException
     */
    public function handle(Term $term, Modality $modality, Teacher $teacher, array $groups, User $actor): PriorChoice
    {
        return DB::transaction(function () use ($term, $modality, $teacher, $groups, $actor): PriorChoice {
            $selections = $this->lockDraftSelections($term, $modality, collect($groups)->map(fn (array $item): string => $item['group']->department_id)->unique()->values()->all());

            $locked = Teacher::query()->lockForUpdate()->findOrFail($teacher->getKey());

            if (! $locked->is_active || $locked->trashed()) {
                throw ValidationException::withMessages(['teacher' => 'El docente no está activo.']);
            }

            $assigned = [];
            $departments = [];

            foreach ($groups as $item) {
                $group = $item['group'];
                $load = $this->editability->lockLoad($group);

                if ($load === null || ! in_array($load->status, self::LOAD_STATES, true)) {
                    throw ValidationException::withMessages(['group' => $load === null
                        ? "El grupo {$group->code} no tiene carga de su departamento en este periodo y modalidad."
                        : "La carga del grupo {$group->code} {$load->status->predicate()}: no se registra una elección previa."]);
                }

                $group->setRawAttributes($group->newQuery()->lockForUpdate()->findOrFail($group->getKey())->getAttributes(), sync: true);

                if ($group->term_id !== $term->getKey() || $group->modality_id !== $modality->getKey()) {
                    throw ValidationException::withMessages(['group' => "El grupo {$group->code} no es de este periodo y modalidad."]);
                }

                if ($group->status === GroupStatus::Cancelled) {
                    throw ValidationException::withMessages(['group' => "El grupo {$group->code} está cancelado."]);
                }

                $departments[] = $group->department_id;

                if ($group->teacher_id === $locked->getKey()) {
                    continue;
                }

                if ($group->teacher_id !== null) {
                    throw ValidationException::withMessages(['group' => "El grupo {$group->code} ya tiene otro docente."]);
                }

                $this->validator->forget();
                $clash = collect($this->validator->checkTeacher($group, $locked)->violations())->firstWhere('rule', 'HC-01');

                if ($clash !== null) {
                    throw ValidationException::withMessages(['group' => "El grupo {$group->code} se empalma con otro grupo de {$locked->name} (HC-01): {$clash->message}"]);
                }

                $this->record->handle($group, $locked, $actor, self::REASON, null, null);
                $assigned[] = $group->code;
            }

            $this->validator->forget();

            $mark = PriorChoice::query()->firstOrNew(['term_id' => $term->getKey(), 'modality_id' => $modality->getKey(), 'teacher_id' => $locked->getKey()]);
            $created = ! $mark->exists;

            $merged = array_values(array_unique([...($mark->department_ids ?? []), ...$departments]));
            sort($merged);

            if ($created) {
                $mark->fill(['department_ids' => $merged]);
            } elseif ($mark->department_ids !== $merged) {
                $mark->update(['department_ids' => $merged]);
            }

            if ($created) {
                $mark->fill(['recorded_by' => $actor->getKey(), 'recorded_at' => now()])->save();

                activity()
                    ->performedOn($mark)
                    ->causedBy($actor)
                    ->event('prior_choice_recorded')
                    ->withProperties(['teacher_id' => $locked->getKey(), 'term_id' => $term->getKey(), 'modality_id' => $modality->getKey(), 'groups' => $assigned])
                    ->log("Marcó a {$locked->name} con elección previa en {$term->name} (".count($assigned).' grupos nuevos)');
            }

            foreach ($selections as $selection) {
                $this->recompute->handle($selection);
            }

            return $mark;
        });
    }
}
