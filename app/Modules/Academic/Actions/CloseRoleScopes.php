<?php

namespace App\Modules\Academic\Actions;

use App\Modules\Academic\Models\Career;
use App\Modules\Academic\Models\Department;
use App\Modules\Core\Models\RoleScope;
use App\Modules\Core\Queries\ScopedContext;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Spatie\Activitylog\Enums\ActivityEvent;

/**
 * Cierra los alcances (`role_scopes`) de un departamento o una carrera que se borra (supuesto S2 de
 * reglas-de-negocio.md): Core no depende de Academic, así que lo hace quien borra. Los alcances
 * vigentes que empezaron antes de hoy terminan ayer (`valid_to`); los que empiezan hoy o después se
 * eliminan, porque el CHECK `valid_to >= valid_from` impide cerrarlos antes de empezar; los ya
 * vencidos no se tocan. Cada cambio deja su entrada en la bitácora sobre el usuario del alcance.
 *
 * Se llama dentro de la transacción de DeleteDepartment o DeleteCareer. Devuelve cuántos alcances
 * tocó y le avisa a ScopedContext que recargue roles y alcances.
 */
final class CloseRoleScopes
{
    /**
     * @param  string  $column  `department_id` o `career_id`
     * @param  string  $id  el departamento o la carrera que se borra
     * @return int cuántos alcances cerró o eliminó
     *
     * @throws InvalidArgumentException si `$column` no es `department_id` ni `career_id`
     */
    public function handle(string $column, string $id): int
    {
        $record = match ($column) {
            'department_id' => ($department = Department::withTrashed()->find($id)) === null ? 'el departamento' : "el departamento {$department->code}",
            'career_id' => ($career = Career::withTrashed()->find($id)) === null ? 'la carrera' : "la carrera {$career->code}",
            default => throw new InvalidArgumentException("No se cierran alcances por {$column}."),
        };

        $today = today();

        return DB::transaction(function () use ($column, $id, $record, $today): int {
            $scopes = RoleScope::query()
                ->with('user')
                ->where($column, $id)
                ->where(fn ($query) => $query->whereNull('valid_to')->orWhere('valid_to', '>=', $today->toDateString()))
                ->lockForUpdate()
                ->get();

            foreach ($scopes as $scope) {
                $description = "Cerró el alcance de {$scope->role->label()} por borrar {$record}";

                if ($scope->valid_from->lt($today)) {
                    $old = $scope->valid_to?->toDateString();
                    $scope->forceFill(['valid_to' => $today->copy()->subDay()->toDateString()])->save();

                    activity()
                        ->performedOn($scope->user)
                        ->event(ActivityEvent::Updated)
                        ->withChanges(['attributes' => ['valid_to' => $scope->valid_to->toDateString()], 'old' => ['valid_to' => $old]])
                        ->log($description);

                    continue;
                }

                $scope->delete();

                activity()
                    ->performedOn($scope->user)
                    ->event(ActivityEvent::Deleted)
                    ->withChanges(['old' => $scope->only(['role', 'campus_id', 'department_id', 'career_id', 'valid_from', 'valid_to'])])
                    ->log($description);
            }

            if ($scopes->isNotEmpty()) {
                app(ScopedContext::class)->refresh();
            }

            return $scopes->count();
        });
    }
}
