<?php

namespace App\Modules\Solver\Queries;

use App\Modules\Academic\Models\Career;
use App\Modules\Academic\Models\Department;
use App\Modules\Core\Models\Modality;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\User;
use App\Modules\Solver\Enums\RunScope;
use App\Modules\Solver\Enums\SolverRunStatus;
use App\Modules\Solver\Models\SolverRun;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use Spatie\Activitylog\Models\Activity;

/**
 * El historial de corridas de la pantalla (tarea 6.10), de la más nueva a la más vieja, según quién mira:
 * - de una carga (el jefe, Subdirección): las corridas de la carga del departamento y las de exploración de sus
 *   carreras;
 * - de una carrera (el coordinador): las suyas de carrera y las de departamento de las cargas que tocan su carrera
 *   (CareerLoadsQuery), nunca las de otra carrera.
 * Cada corrida pasa además por SolverRunPolicy::view de quien mira. Cada una lleva su número dentro de su alcance
 * (la carga o la carrera, en el periodo y la modalidad): la primera es la 1; y la aplicada, cuántas sesiones escribió
 * (de su bitácora, `written`).
 */
final class SolverRunsQuery
{
    public function __construct(private readonly CareerLoadsQuery $loads) {}

    /**
     * @return list<array{run: SolverRun, number: int, written: ?int}>
     */
    public function forDepartment(Term $term, Modality $modality, Department $department, User $viewer): array
    {
        $careers = Career::withTrashed()->where('department_id', $department->id)->pluck('id')->all();

        return $this->rows($term, $modality, $viewer, fn (Builder $query) => $query
            ->where(fn (Builder $scope) => $scope->where('department_id', $department->id)->orWhereIn('career_id', $careers)));
    }

    /**
     * @return list<array{run: SolverRun, number: int, written: ?int}>
     */
    public function forCareer(Term $term, Modality $modality, Career $career, User $viewer): array
    {
        $departments = $this->loads->departmentsOf($term->id, $modality->id, $career->id);

        return $this->rows($term, $modality, $viewer, fn (Builder $query) => $query
            ->where(fn (Builder $scope) => $scope->where('career_id', $career->id)->orWhereIn('department_id', $departments)));
    }

    /**
     * El número de cada corrida dentro de su alcance, por id.
     *
     * @return array<string, int>
     */
    public function numbers(Term $term, Modality $modality): array
    {
        $numbers = [];
        $counts = [];

        foreach (SolverRun::query()->where('term_id', $term->id)->where('modality_id', $modality->id)->orderBy('created_at')->orderBy('id')->get(['id', 'scope_kind', 'department_id', 'career_id']) as $run) {
            $scope = $run->scope_kind === RunScope::Department ? "d:{$run->department_id}" : "c:{$run->career_id}";
            $counts[$scope] = ($counts[$scope] ?? 0) + 1;
            $numbers[$run->id] = $counts[$scope];
        }

        return $numbers;
    }

    /**
     * Quién y cuándo aplicó, descartó o canceló la corrida, de su bitácora, con el resumen de lo que se escribió al
     * aplicarla; null si no tiene ninguna de esas.
     *
     * @return array{who: ?string, at: ?CarbonInterface, summary: ?array<string, mixed>}|null
     */
    public function decision(SolverRun $run): ?array
    {
        $entry = Activity::query()
            ->where('subject_type', $run->getMorphClass())
            ->where('subject_id', $run->getKey())
            ->whereIn('event', ['applied', 'discarded', 'cancelled', 'cancel_requested'])
            ->with('causer')
            ->latest('id')
            ->first();

        return $entry === null ? null : [
            'who' => $entry->causer?->name,
            'at' => $entry->created_at,
            'summary' => $entry->properties['summary'] ?? null,
        ];
    }

    /**
     * @param  callable(Builder<SolverRun>): mixed  $scope
     * @return list<array{run: SolverRun, number: int, written: ?int}>
     */
    private function rows(Term $term, Modality $modality, User $viewer, callable $scope): array
    {
        $numbers = $this->numbers($term, $modality);
        $query = SolverRun::query()
            ->where('term_id', $term->id)
            ->where('modality_id', $modality->id)
            ->with([
                'creator:id,name',
                'applier:id,name',
                'department' => fn ($department) => $department->withTrashed()->select('id', 'code', 'name'),
                'career' => fn ($career) => $career->withTrashed()->select('id', 'code', 'name'),
            ])
            ->select(['id', 'term_id', 'modality_id', 'scope_kind', 'department_id', 'career_id', 'engine', 'status', 'metrics', 'error', 'created_by', 'started_at', 'finished_at', 'applied_by', 'applied_at', 'progress', 'best_score', 'cancel_requested_at', 'created_at', 'updated_at'])
            ->orderByDesc('created_at')
            ->orderByDesc('id');
        $scope($query);
        $gate = Gate::forUser($viewer);

        $runs = $query->get()->filter(fn (SolverRun $run): bool => $gate->allows('view', $run))->values();
        $written = Activity::query()
            ->where('subject_type', (new SolverRun)->getMorphClass())
            ->whereIn('subject_id', $runs->filter(fn (SolverRun $run): bool => $run->status === SolverRunStatus::Applied)->modelKeys())
            ->where('event', 'applied')
            ->get(['subject_id', 'properties'])
            ->mapWithKeys(fn (Activity $entry): array => [$entry->subject_id => (int) ($entry->properties['summary']['sessions'] ?? 0)]);

        return $runs->map(fn (SolverRun $run): array => ['run' => $run, 'number' => $numbers[$run->id] ?? 0, 'written' => $written[$run->id] ?? null])->all();
    }
}
