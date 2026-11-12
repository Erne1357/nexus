<?php

namespace App\Modules\Students\Queries;

use App\Modules\Academic\Models\Career;
use App\Modules\Academic\Models\StudyPlan;
use App\Modules\Core\Authorization\Actor;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Queries\KeyLookup;
use App\Modules\Core\Queries\TextSearch;
use App\Modules\Students\Enums\StudentsPermission;
use App\Modules\Students\Models\Student;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Lecturas de alumnos para las pantallas (tarea 1.6). El listado es de un campus o, sin uno (`null`, «Todos»), de
 * todos los campus activos del usuario (#415: la credencial ya no elige campus), y deja ver lo mismo que StudentPolicy, filtrado en SQL con los alcances de Actor y la misma regla que
 * Actor::covers(): al admin, todo el campus; a la subdirección, las carreras de los departamentos de
 * Subdirección Académica; al jefe de departamento, las carreras de su departamento; al coordinador, su carrera. Los
 * alumnos son de su campus y de su modalidad (AZ-S17, MS7): un nombramiento con modalidad solo ve los de esa
 * modalidad (activa, MS8) y uno sin modalidad ve los de todas, también las inactivas (M10)
 * (reglas-de-negocio.md, "Consultar alumnos"). Incluye a los alumnos inactivos, como todo catálogo; los borrados no.
 */
final class StudentQuery
{
    /** Valor del filtro de estatus para los alumnos que quedaron sin estatus. */
    public const string WITHOUT_STATUS = 'sin_estatus';

    /**
     * La tabla de alumnos del campus, por número de control y sin los borrados, con su avance para la columna de
     * créditos. Busca `$search` en el número de control o el nombre con TextSearch; `$filters` filtra por carrera,
     * plan, semestre y estatus. Los filtros llegan del navegador: una carrera o un plan que no es uuid, o un
     * semestre que no es número, no encuentra a nadie, en lugar de tronar en PostgreSQL (ADR-0014).
     *
     * @param  array{career?: string, plan?: string, semester?: int|string, status?: string}  $filters
     * @return LengthAwarePaginator<int, Student>
     */
    public function forTable(Actor $context, ?Campus $campus, array $filters = [], string $search = '', int $perPage = 25): LengthAwarePaginator
    {
        return $this->visible($context, $campus)
            ->with([
                'career' => fn (BelongsTo $career) => $career->withTrashed(),
                'studyPlan' => fn (BelongsTo $plan) => $plan->withTrashed(),
                'progress',
                'campus:id,code,name',
            ])
            ->tap(fn (Builder $query) => TextSearch::apply($query, ['control_number', 'name'], $search))
            ->when($filters['career'] ?? null, fn (Builder $query, string $career) => $this->whereKeyFromClient($query, 'career_id', $career))
            ->when($filters['plan'] ?? null, fn (Builder $query, string $plan) => $this->whereKeyFromClient($query, 'study_plan_id', $plan))
            ->when($filters['semester'] ?? null, fn (Builder $query, int|string $semester) => filter_var($semester, FILTER_VALIDATE_INT) === false
                ? $query->whereRaw('false')
                : $query->where('current_semester', (int) $semester))
            ->when($filters['status'] ?? null, fn (Builder $query, string $status) => $status === self::WITHOUT_STATUS
                ? $query->whereNull('status')
                : $query->where('status', $status))
            ->orderBy('control_number')
            ->paginate($perPage);
    }

    /**
     * El alumno de la ficha, sin los borrados y con su carrera, que usan StudentPolicy y la ficha. El id llega
     * de la ruta: uno que no es uuid no existe (ADR-0014).
     *
     * @throws ModelNotFoundException<Student>
     */
    public function findOrFail(string $id): Student
    {
        return KeyLookup::findOrFail(Student::query()->with(['career' => fn (BelongsTo $career) => $career->withTrashed()]), $id);
    }

    /**
     * Las opciones de los filtros: las carreras, los planes y los semestres de los alumnos que ve, en el formato
     * de los select de MaryUI.
     *
     * @return array{careers: list<array{id: string, name: string}>, plans: list<array{id: string, name: string}>, semesters: list<array{id: int, name: string}>}
     */
    public function filterOptions(Actor $context, ?Campus $campus): array
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
     * Los alumnos del campus que el usuario puede ver: con cada nombramiento que concede `students.student.view`
     * (StudentsPermission) de ese campus o sin campus (de todo el Tec), los que Actor::constrain() le daría: los de las carreras de un departamento de su unidad
     * o de su subárbol y de su carrera, cuando el nombramiento las trae. Uno sin unidad ni carrera cubre todo el campus. Un nombramiento con modalidad
     * solo deja los alumnos de esa modalidad; sin modalidad, los de todas (M10).
     *
     * @return Builder<Student>
     */
    private function visible(Actor $context, ?Campus $campus): Builder
    {
        $query = Student::query()->when(
            $campus === null,
            fn (Builder $students) => $students->whereIn('students.campus_id', $context->campuses()->pluck('id')->all()),
            fn (Builder $students) => $students->where('students.campus_id', $campus->id),
        );

        return $context->constrain($query, StudentsPermission::StudentView, [
            'modality' => 'students.modality_id',
            'campus' => 'students.campus_id',
            'unit' => fn (Builder $students, array $units) => $students->whereIn('students.career_id', Career::withTrashed()->whereIn('department_id', $units)->select('id')),
            'career' => 'students.career_id',
        ]);
    }

    /**
     * @param  Builder<Student>  $query
     * @return Builder<Student>
     */
    private function whereKeyFromClient(Builder $query, string $column, string $id): Builder
    {
        return Str::isUuid($id) ? $query->where($column, $id) : $query->whereRaw('false');
    }
}
