<?php

namespace App\Modules\Staffing\Actions\Selection;

use App\Modules\Academic\Models\Career;
use App\Modules\Academic\Models\Department;
use App\Modules\AcademicLoad\Models\AcademicLoad;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Modality;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\User;
use App\Modules\Scheduling\Enums\GroupStatus;
use App\Modules\Scheduling\Models\Group;
use App\Modules\Staffing\Enums\SelectionStatus;
use App\Modules\Staffing\Models\Selection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Abre la selección de un departamento en un periodo, modalidad y campus (diseño 6.2 y 6.12). Solo puede haber una viva
 * (la cancelada no cuenta). Incluye por defecto la carga del departamento y la de cada otro departamento que tenga
 * grupos de las carreras del departamento (`groups.owner_career_id` en las carreras con `careers.department_id` del
 * departamento) en ese periodo, modalidad y campus; el admin ajusta la lista después.
 */
final class CreateSelection
{
    /**
     * @throws ValidationException si ya hay una selección viva con esos datos
     */
    public function handle(Term $term, Modality $modality, Department $department, Campus $campus, User $actor): Selection
    {
        return DB::transaction(function () use ($term, $modality, $department, $campus, $actor): Selection {
            $exists = Selection::query()
                ->where('term_id', $term->getKey())
                ->where('modality_id', $modality->getKey())
                ->where('department_id', $department->getKey())
                ->where('campus_id', $campus->getKey())
                ->where('status', '!=', SelectionStatus::Cancelled->value)
                ->lockForUpdate()
                ->exists();

            if ($exists) {
                throw ValidationException::withMessages(['department_id' => "Ya hay una selección de {$department->name} para este periodo, modalidad y campus."]);
            }

            $selection = Selection::query()->create([
                'term_id' => $term->getKey(),
                'modality_id' => $modality->getKey(),
                'department_id' => $department->getKey(),
                'campus_id' => $campus->getKey(),
                'status' => SelectionStatus::Draft,
                'created_by' => $actor->getKey(),
            ]);

            $feeders = Group::query()
                ->where('term_id', $term->getKey())
                ->where('modality_id', $modality->getKey())
                ->where('campus_id', $campus->getKey())
                ->where('status', '!=', GroupStatus::Cancelled->value)
                ->whereIn('owner_career_id', Career::query()->where('department_id', $department->getKey())->select('id'))
                ->select('department_id');

            $loadIds = AcademicLoad::query()
                ->where('term_id', $term->getKey())
                ->where('modality_id', $modality->getKey())
                ->where(fn ($query) => $query
                    ->where('department_id', $department->getKey())
                    ->orWhereIn('department_id', $feeders))
                ->pluck('id')
                ->all();

            $selection->loads()->sync($loadIds);

            activity()
                ->performedOn($selection)
                ->causedBy($actor)
                ->event('selection_created')
                ->withProperties(['loads' => count($loadIds)])
                ->log("Creó la selección de {$department->name} con ".count($loadIds).' cargas');

            return $selection;
        });
    }
}
