<?php

namespace App\Modules\Students\Queries;

use App\Modules\Academic\Models\Career;
use App\Modules\Academic\Models\StudyPlan;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\RoleScope;
use App\Modules\Core\Queries\ScopedContext;
use App\Modules\Core\Queries\TextSearch;
use App\Modules\Students\Models\Student;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Lecturas de alumnos para las pantallas (tarea 1.6). El listado es de un campus, el activo de la credencial, y
 * deja ver lo mismo que StudentPolicy, filtrado en SQL con los alcances de ScopedContext: al admin y a la
 * subdirección, todo el campus; al jefe de departamento, las carreras de su departamento; al coordinador, su
 * carrera (reglas-de-negocio.md, "Consultar alumnos").
 */
final class StudentQuery
{
    /** Valor del filtro de estatus para los alumnos que quedaron sin estatus. */
    public const string WITHOUT_STATUS = 'sin_estatus';

    /**
     * La tabla de alumnos del campus, por número de control y sin los borrados. Busca `$search` en el número de
     * control o el nombre con TextSearch; `$filters` filtra por carrera, plan, semestre y estatus.
     *
     * @param  array{career?: string, plan?: string, semester?: int, status?: string}  $filters
     * @return LengthAwarePaginator<int, Student>
     */
    public function forTable(ScopedContext $context, Campus $campus, array $filters = [], string $search = '', int $perPage = 25): LengthAwarePaginator
    {
        return $this->visible($context, $campus)
            ->with([
                'career' => fn (BelongsTo $career) => $career->withTrashed(),
                'studyPlan' => fn (BelongsTo $plan) => $plan->withTrashed(),
                'progress',
            ])
            ->tap(fn (Builder $query) => TextSearch::apply($query, ['control_number', 'name'], $search))
            ->when($filters['career'] ?? null, fn (Builder $query, string $career) => $query->where('career_id', $career))
            ->when($filters['plan'] ?? null, fn (Builder $query, string $plan) => $query->where('study_plan_id', $plan))
            ->when($filters['semester'] ?? null, fn (Builder $query, int $semester) => $query->where('current_semester', $semester))
            ->when($filters['status'] ?? null, fn (Builder $query, string $status) => $status === self::WITHOUT_STATUS
                ? $query->whereNull('status')
                : $query->where('status', $status))
            ->orderBy('control_number')
            ->paginate($perPage);
    }

    /**
     * Las opciones de los filtros: las carreras, los planes y los semestres de los alumnos que ve, en el formato
     * de los select de MaryUI.
     *
     * @return array{careers: list<array{id: string, name: string}>, plans: list<array{id: string, name: string}>, semesters: list<array{id: int, name: string}>}
     */
    public function filterOptions(ScopedContext $context, Campus $campus): array
    {
        $visible = fn (): Builder => $this->visible($context, $campus);

        return [
            'careers' => Career::withTrashed()->whereIn('id', $visible()->select('career_id'))->orderBy('code')->get(['id', 'code', 'name'])
                ->map(fn (Career $career): array => ['id' => $career->id, 'name' => "{$career->code} · {$career->name}"])->all(),
            'plans' => StudyPlan::withTrashed()->whereIn('id', $visible()->select('study_plan_id'))->orderBy('code')->get(['id', 'code'])
                ->map(fn (StudyPlan $plan): array => ['id' => $plan->id, 'name' => $plan->code])->all(),
            'semesters' => $visible()->whereNotNull('current_semester')->distinct()->orderBy('current_semester')->pluck('current_semester')
                ->map(fn (int $semester): array => ['id' => $semester, 'name' => "{$semester}.º"])->all(),
        ];
    }

    /**
     * Los alumnos del campus que el usuario puede ver.
     *
     * @return Builder<Student>
     */
    private function visible(ScopedContext $context, Campus $campus): Builder
    {
        $query = Student::query()->where('students.campus_id', $campus->id);

        if ($context->covers(Role::Subdireccion, $campus)) {
            return $query;
        }

        $scopes = $context->scopes()->filter(fn (RoleScope $scope): bool => $scope->campus_id === $campus->id);
        $careers = $scopes->where('role', Role::Coordinador)->pluck('career_id')->all();
        $departments = $scopes->where('role', Role::JefeDepartamento)->pluck('department_id')->all();

        return $query->where(fn (Builder $query) => $query
            ->whereIn('career_id', $careers)
            ->orWhereIn('career_id', Career::withTrashed()->whereIn('department_id', $departments)->select('id')));
    }
}
