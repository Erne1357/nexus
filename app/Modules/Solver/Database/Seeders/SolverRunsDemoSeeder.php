<?php

namespace App\Modules\Solver\Database\Seeders;

use App\Modules\Academic\Models\Career;
use App\Modules\Academic\Models\Department;
use App\Modules\AcademicLoad\Enums\LoadStatus;
use App\Modules\AcademicLoad\Models\AcademicLoad;
use App\Modules\Core\Authorization\Target;
use App\Modules\Core\Database\Seeders\Concerns\RefusesProduction;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Modality;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\User;
use App\Modules\Core\Queries\RecipientsQuery;
use App\Modules\Scheduling\Actions\AssignGroupCode;
use App\Modules\Scheduling\Events\GroupScheduleChanged;
use App\Modules\Scheduling\Models\Group;
use App\Modules\Scheduling\Rules\LoadEditability;
use App\Modules\Solver\Actions\CancelSolverRun;
use App\Modules\Solver\Actions\DiscardSolverRun;
use App\Modules\Solver\Actions\LaunchSolverRun;
use App\Modules\Solver\Actions\RequestApplySolverRun;
use App\Modules\Solver\Engines\CpSat\CpSatEngine;
use App\Modules\Solver\Engines\FixedEngine;
use App\Modules\Solver\Engines\PhpHeuristic\PhpHeuristicEngine;
use App\Modules\Solver\Enums\RunScope;
use App\Modules\Solver\Enums\SolverPermission;
use App\Modules\Solver\Enums\SolverRunStatus;
use App\Modules\Solver\Jobs\RunSolver;
use App\Modules\Solver\Models\SolverRun;
use App\Modules\Solver\Queries\BuildSolverInput;
use App\Modules\Solver\Rules\InputFingerprint;
use App\Modules\Solver\Support\RunPayloads;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Corridas del motor en la demo (pantalla «Corridas del motor», tarea 6.10): deja, en el periodo y la modalidad, corridas
 * en todos sus estados sobre tres departamentos con grupos en los campus de la demo, para que el historial, la semana
 * y el puntaje se vean con datos. Las que se pueden lanzar se lanzan de verdad, con LaunchSolverRun como el jefe del
 * departamento o el coordinador de la carrera (el titular del permiso de lanzar, RecipientsQuery) y un límite de
 * TIME_LIMIT segundos, en la cola síncrona: sus métricas, su entrada y su salida son las reales. Las que no se dejan solas
 * (en cola, corriendo, fallida) se crean con la factory, con la entrada real de su alcance (BuildSolverInput, una vez
 * por alcance) y su huella; la cancelada y la descartada pasan además por CancelSolverRun y DiscardSolverRun, para que
 * la pantalla diga quién y cuándo (bitácora).
 *
 * Los departamentos, de los que tienen grupos activos en los campus y jefe:
 * - el principal (MAIN_DEPARTMENT, el de `jefe.sc`, el usuario que imprime `demo:seed`; sin él, el de más grupos): una
 *   fallida con CP-SAT («El servicio del motor no está disponible.», de hace tres horas), una cancelada mientras corría
 *   (de hace dos), una en cola vieja (más de RunSolver::STALE_AFTER sin moverse; no estorba: la siguiente que se lance la
 *   da por perdida, SV-01), una del motor de prueba descartada y, la más nueva, una de la heurística lista, que es la que
 *   la pantalla abre: la semana con todo lo que cambia contra la carga de hoy. La en cola se crea después de lanzar las
 *   otras, con su fecha de antes, para que lanzar no la dé por perdida. Así el jefe ve en su historial todos los estados
 *   que caben en un departamento salvo la aplicada: la que corre es de carrera, que el jefe no ve
 *   (SolverRunPolicy::view). Además, en la carrera suya con más grupos y coordinador, una corrida de carrera que sigue
 *   corriendo (lanzada hace unos minutos, con diez de límite y el avance a medias) y una de la heurística lista, la más
 *   nueva, que es la que abre el coordinador. La de carrera no ocupa el lugar del departamento (SV-01). El coordinador
 *   ve además las de departamento de las cargas que tocan su carrera, así que ve todos los estados.
 * - el de la aceptación, de los que tienen la carga editable (LoadEditability::EDITABLE) el de menos grupos, primero los
 *   que siguen en propuesta (la etapa de horarios, sin ranking docente que se quede atrás): una de la heurística que el
 *   jefe acepta (RequestApplySolverRun y su job, en la cola síncrona) y queda aplicada. Con `$apply` apagado se queda
 *   lista. Si no se aplica (la carga cambió o el horario no se puede escribir), la demo se detiene con el motivo. Mientras
 *   se escribe se descarta GroupScheduleChanged, como en PublicationShowcaseDemoSeeder: en la cola síncrona cada grupo
 *   recalculaba los choques de la modalidad y el ranking de su departamento (46 s con el volumen demo). ApplySolverRun
 *   ya recalcula los choques una vez; después se vuelve a calcular la clave de cada grupo del departamento
 *   (AssignGroupCode, lo que hace el otro escucha). El ranking docente de ese departamento no se recalcula (Solver no
 *   depende de Staffing): se pone al día con el siguiente cambio de sus grupos (supuesto por confirmar).
 * - el amplio, de preferencia con grupos en más de un campus y luego el de más grupos: una del motor de prueba y una de
 *   la heurística listas (medido el 18 de noviembre de 2026: con el volumen demo, la de CEA deja de 1 a 3 grupos sin
 *   acomodar; con el masivo en `todo`, la de II deja 62).
 *
 * Idempotente: un departamento o una carrera que ya tiene corridas en el periodo y la modalidad no se toca, así que
 * volver a sembrar deja los mismos conteos. Sin el periodo, la modalidad o departamentos con grupos, no hace nada. Se
 * niega a correr en producción.
 */
final class SolverRunsDemoSeeder extends Seeder
{
    use RefusesProduction;

    /** El departamento que la demo enseña primero: el de `jefe.sc`. */
    public const string MAIN_DEPARTMENT = 'SC';

    /** El límite de las corridas que se lanzan, el más chico que acepta LaunchSolverRun más un poco. */
    public const int TIME_LIMIT = 15;

    public const string UNAVAILABLE = 'El servicio del motor no está disponible.';

    /** @var array<string, array{0: array<string, mixed>, 1: string}> la entrada y su huella por alcance */
    private array $inputs = [];

    /**
     * @param  list<string>  $campuses  códigos de campus de la demo
     * @param  bool  $apply  si el departamento de la aceptación acepta su corrida
     */
    public function run(string $modality = 'PRES', array $campuses = ['C2'], string $term = '20271', bool $apply = true): void
    {
        $this->refuseProduction();

        $termModel = Term::query()->where('code', $term)->first();
        $modalityModel = Modality::query()->where('code', $modality)->first();

        if ($termModel === null || $modalityModel === null) {
            return;
        }

        $departments = $this->departments($termModel, $modalityModel, $campuses);

        if ($departments->isEmpty()) {
            return;
        }

        $queue = config('queue.default');
        config(['queue.default' => 'sync']);

        try {
            $main = $departments->firstWhere('department.code', self::MAIN_DEPARTMENT) ?? $departments->sortByDesc('groups')->first();
            $rest = $departments->reject(fn (array $row): bool => $row['department']->is($main['department']));
            $accepting = $rest->filter(fn (array $row): bool => $row['editable'])->sortBy([['proposed', 'desc'], ['groups', 'asc'], ['department.code', 'asc']])->first();
            $wide = $rest->reject(fn (array $row): bool => $accepting !== null && $row['department']->is($accepting['department']))
                ->sortBy([['campuses', 'desc'], ['groups', 'desc'], ['department.code', 'asc']])->first();

            $this->mainDepartment($termModel, $modalityModel, $main);

            if ($accepting !== null) {
                $this->acceptingDepartment($termModel, $modalityModel, $accepting, $apply);
            }

            if ($wide !== null) {
                $this->wideDepartment($termModel, $modalityModel, $wide);
            }

            $this->careerRuns($termModel, $modalityModel, $main['department'], $campuses);
        } finally {
            config(['queue.default' => $queue]);
        }

        $count = SolverRun::query()->where('term_id', $termModel->id)->where('modality_id', $modalityModel->id)->count();
        $this->command?->info("Corridas del motor en {$modalityModel->name}: {$count} en ".$departments->count().' departamentos con grupos.');
    }

    /**
     * Los departamentos con grupos activos de la modalidad en los campus y con quien lance sus corridas, con cuántos
     * grupos y en cuántos campus, y si su carga se puede editar.
     *
     * @param  list<string>  $campuses
     * @return Collection<int, array{department: Department, head: User, groups: int, campuses: int, editable: bool, proposed: bool}>
     */
    private function departments(Term $term, Modality $modality, array $campuses): Collection
    {
        $counts = Group::query()->active()
            ->where('term_id', $term->id)
            ->where('modality_id', $modality->id)
            ->whereIn('campus_id', Campus::query()->whereIn('code', $campuses)->select('id'))
            ->groupBy('department_id')
            ->selectRaw('department_id, count(*) as total, count(distinct campus_id) as campuses')
            ->get()
            ->keyBy('department_id');
        $statuses = AcademicLoad::query()->where('term_id', $term->id)->where('modality_id', $modality->id)
            ->whereIn('department_id', $counts->keys())->pluck('status', 'department_id');

        return Department::query()->whereIn('id', $counts->keys())->orderBy('code')->get()
            ->map(fn (Department $department): ?array => ($head = $this->holder(SolverPermission::DepartmentRunLaunch, Target::of(modality: $modality->id, unit: $department->id))) === null ? null : [
                'department' => $department,
                'head' => $head,
                'groups' => (int) $counts[$department->id]->total,
                'campuses' => (int) $counts[$department->id]->campuses,
                'editable' => in_array($statuses[$department->id] ?? null, LoadEditability::EDITABLE, true),
                'proposed' => ($statuses[$department->id] ?? null) === LoadStatus::Proposed,
            ])
            ->filter()
            ->values();
    }

    /**
     * @param  array{department: Department, head: User, groups: int, campuses: int, editable: bool, proposed: bool}  $row
     */
    private function mainDepartment(Term $term, Modality $modality, array $row): void
    {
        ['department' => $department, 'head' => $head] = $row;

        if ($this->hasRuns($term, $modality, RunScope::Department, $department->id)) {
            return;
        }

        $this->fake($term, $modality, RunScope::Department, $department->id, $head, [
            'engine' => CpSatEngine::KEY,
            'status' => SolverRunStatus::Failed,
            'error' => self::UNAVAILABLE,
            'progress' => 0,
        ], now()->subHours(3), finishedAfter: 2);

        $interrupted = $this->fake($term, $modality, RunScope::Department, $department->id, $head, [
            'engine' => PhpHeuristicEngine::KEY,
            'status' => SolverRunStatus::Running,
            'progress' => 30,
            'best_score' => 2400,
        ], now()->subHours(2));
        app(CancelSolverRun::class)->handle($interrupted, $head);

        $discarded = $this->launch($term, $modality, RunScope::Department, $department->id, FixedEngine::KEY, $head);
        app(DiscardSolverRun::class)->handle($discarded, $head);

        $this->launch($term, $modality, RunScope::Department, $department->id, PhpHeuristicEngine::KEY, $head);

        $this->fake($term, $modality, RunScope::Department, $department->id, $head, [
            'engine' => PhpHeuristicEngine::KEY,
            'status' => SolverRunStatus::Queued,
            'progress' => 0,
        ], now()->subSeconds(2 * RunSolver::STALE_AFTER), started: false);
    }

    /**
     * La carrera del departamento principal con más grupos y coordinador: una de exploración que sigue corriendo y, la
     * más nueva de todas, una lista, para que el coordinador la abra al entrar.
     *
     * @param  list<string>  $campuses
     */
    private function careerRuns(Term $term, Modality $modality, Department $department, array $campuses): void
    {
        $career = $this->career($term, $modality, $department, $campuses);

        if ($career === null || $this->hasRuns($term, $modality, RunScope::Career, $career['career']->id)) {
            return;
        }

        $this->fake($term, $modality, RunScope::Career, $career['career']->id, $career['coordinator'], [
            'engine' => PhpHeuristicEngine::KEY,
            'status' => SolverRunStatus::Running,
            'progress' => 45,
            'best_score' => 1800,
        ], now()->subMinutes(4), timeLimit: LaunchSolverRun::MAX_TIME_LIMIT);

        $this->launch($term, $modality, RunScope::Career, $career['career']->id, PhpHeuristicEngine::KEY, $career['coordinator']);
    }

    /**
     * @param  array{department: Department, head: User, groups: int, campuses: int, editable: bool, proposed: bool}  $row
     */
    private function acceptingDepartment(Term $term, Modality $modality, array $row, bool $apply): void
    {
        ['department' => $department, 'head' => $head] = $row;

        if ($this->hasRuns($term, $modality, RunScope::Department, $department->id)) {
            return;
        }

        $run = $this->launch($term, $modality, RunScope::Department, $department->id, PhpHeuristicEngine::KEY, $head);

        if (! $apply) {
            return;
        }

        Event::fakeFor(fn () => app(RequestApplySolverRun::class)->handle($run, $head), [GroupScheduleChanged::class]);
        $run->refresh();

        if ($run->status !== SolverRunStatus::Applied) {
            throw new RuntimeException("La corrida de {$department->code} no se aplicó: ".($run->metrics['apply_error'] ?? $run->status->label()).'.');
        }

        Group::query()->active()->where('term_id', $term->id)->where('modality_id', $modality->id)->where('department_id', $department->id)
            ->orderBy('id')->get()->each(fn (Group $group) => app(AssignGroupCode::class)->handle($group));
    }

    /**
     * @param  array{department: Department, head: User, groups: int, campuses: int, editable: bool, proposed: bool}  $row
     */
    private function wideDepartment(Term $term, Modality $modality, array $row): void
    {
        ['department' => $department, 'head' => $head] = $row;

        if ($this->hasRuns($term, $modality, RunScope::Department, $department->id)) {
            return;
        }

        $this->launch($term, $modality, RunScope::Department, $department->id, FixedEngine::KEY, $head);
        $this->launch($term, $modality, RunScope::Department, $department->id, PhpHeuristicEngine::KEY, $head);
    }

    /**
     * La carrera del departamento con más grupos en los campus y con quien lance sus corridas.
     *
     * @param  list<string>  $campuses
     * @return array{career: Career, coordinator: User}|null
     */
    private function career(Term $term, Modality $modality, Department $department, array $campuses): ?array
    {
        $owned = Group::query()->active()
            ->where('term_id', $term->id)
            ->where('modality_id', $modality->id)
            ->whereIn('campus_id', Campus::query()->whereIn('code', $campuses)->select('id'))
            ->whereIn('owner_career_id', Career::query()->where('department_id', $department->id)->select('id'))
            ->groupBy('owner_career_id')
            ->selectRaw('owner_career_id, count(*) as total')
            ->pluck('total', 'owner_career_id');
        $careers = Career::query()->whereIn('id', $owned->keys())->get()
            ->sortBy([fn (Career $a, Career $b): int => $owned[$b->id] <=> $owned[$a->id], fn (Career $a, Career $b): int => $a->code <=> $b->code]);

        foreach ($careers as $career) {
            $coordinator = $this->holder(SolverPermission::CareerRunLaunch, Target::of(modality: $modality->id, career: $career->id));

            if ($coordinator !== null) {
                return ['career' => $career, 'coordinator' => $coordinator];
            }
        }

        return null;
    }

    private function holder(SolverPermission $permission, Target $target): ?User
    {
        return app(RecipientsQuery::class)->holders($permission, $target)[0]['user'] ?? null;
    }

    private function hasRuns(Term $term, Modality $modality, RunScope $scope, string $scopeId): bool
    {
        return SolverRun::query()
            ->where('term_id', $term->id)
            ->where('modality_id', $modality->id)
            ->where('scope_kind', $scope)
            ->where($scope === RunScope::Department ? 'department_id' : 'career_id', $scopeId)
            ->exists();
    }

    private function launch(Term $term, Modality $modality, RunScope $scope, string $scopeId, string $engine, User $by): SolverRun
    {
        $run = app(LaunchSolverRun::class)->handle($term, $modality, $scope, $scopeId, $engine, self::TIME_LIMIT, $by);

        if ($run->status !== SolverRunStatus::Done) {
            throw new RuntimeException("La corrida «{$engine}» de {$scope->label()} no terminó: ".($run->error ?? $run->status->label()).'.');
        }

        return $run;
    }

    /**
     * Una corrida en un estado que no se deja solo, con la entrada real de su alcance y su huella, lanzada en `$at` por
     * `$by`. Si arrancó, `started_at` es `$at`; con `$finishedAfter`, terminó esos segundos después.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function fake(Term $term, Modality $modality, RunScope $scope, string $scopeId, User $by, array $attributes, CarbonInterface $at,
        bool $started = true, ?int $finishedAfter = null, int $timeLimit = self::TIME_LIMIT): SolverRun
    {
        $runId = (string) Str::uuid7();
        $at = CarbonImmutable::instance($at);
        [$input, $hash] = $this->input($term, $modality, $scope, $scopeId);

        return SolverRun::factory()->create([
            'id' => $runId,
            'term_id' => $term->id,
            'modality_id' => $modality->id,
            'scope_kind' => $scope,
            'department_id' => $scope === RunScope::Department ? $scopeId : null,
            'career_id' => $scope === RunScope::Career ? $scopeId : null,
            'input_hash' => $hash,
            'created_by' => $by->id,
            ...RunPayloads::attributes($runId, 'input', [...$input, 'run_id' => $runId, 'time_limit_seconds' => $timeLimit]),
            'started_at' => $started ? $at : null,
            'finished_at' => $finishedAfter === null ? null : $at->addSeconds($finishedAfter),
            'created_at' => $at,
            'updated_at' => $finishedAfter === null ? $at : $at->addSeconds($finishedAfter),
            ...$attributes,
        ]);
    }

    /**
     * La entrada del alcance y su huella, armadas una vez: la huella no depende del id de la corrida ni del límite.
     *
     * @return array{0: array<string, mixed>, 1: string}
     */
    private function input(Term $term, Modality $modality, RunScope $scope, string $scopeId): array
    {
        return $this->inputs["{$scope->value}:{$scopeId}"] ??= (function () use ($term, $modality, $scope, $scopeId): array {
            $builder = app(BuildSolverInput::class);
            $build = $scope === RunScope::Department
                ? $builder->forDepartment($term, $modality, Department::query()->findOrFail($scopeId), (string) Str::uuid7())
                : $builder->forCareer($term, $modality, Career::query()->findOrFail($scopeId), (string) Str::uuid7());

            return [$build->input->toArray(), InputFingerprint::of($build->input)];
        })();
    }
}
