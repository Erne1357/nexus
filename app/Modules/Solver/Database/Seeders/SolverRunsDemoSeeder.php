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
use App\Modules\Scheduling\Events\ScheduleConflictsSynced;
use App\Modules\Scheduling\Models\Group;
use App\Modules\Scheduling\Rules\LoadEditability;
use App\Modules\Solver\Actions\CancelSolverRun;
use App\Modules\Solver\Actions\DiscardSolverRun;
use App\Modules\Solver\Actions\LaunchSolverRun;
use App\Modules\Solver\Actions\RequestApplySolverRun;
use App\Modules\Solver\Engines\CpSat\CpSatClient;
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
use Spatie\Activitylog\Models\Activity;

/**
 * Corridas del motor en la demo (pantalla «Corridas del motor», tarea 6.10): deja, en el periodo y la modalidad, corridas
 * en todos sus estados sobre tres departamentos con grupos en los campus de la demo, para que el historial, la semana
 * y el puntaje se vean con datos. Las que se pueden lanzar se lanzan de verdad, con LaunchSolverRun como el jefe del
 * departamento o el coordinador de la carrera (el titular del permiso de lanzar, RecipientsQuery) y un límite de
 * TIME_LIMIT segundos, en la cola síncrona: sus métricas, su entrada y su salida son las reales. Las que no se dejan solas
 * (en cola, corriendo, fallida) se crean con la factory, con la entrada real de su alcance (BuildSolverInput, una vez
 * por alcance) y su huella; no dejan la bitácora `launched` de LaunchSolverRun, así que su autor es solo `created_by`
 * (lo que lee la pantalla). La cancelada y la descartada pasan además por CancelSolverRun y DiscardSolverRun, para que la
 * pantalla diga quién y cuándo (bitácora).
 *
 * Los departamentos, de los que tienen grupos activos en los campus y jefe, en este orden:
 * - primero el de la aceptación, de los que tienen la carga editable (LoadEditability::EDITABLE) el de menos grupos,
 *   antes los que siguen en propuesta (la etapa de horarios): una de la heurística que el jefe acepta (accept()) y queda
 *   aplicada; con `$apply` apagado se queda lista. Va primero porque la entrada de las demás trae como fijos los grupos
 *   de otros departamentos con los que comparten cohorte (BuildSolverInput) y su huella cambiaría al aceptar: lanzadas
 *   después, todas las listas siguen vigentes (SV-02). Si no se aplica, la demo se detiene con el motivo.
 * - el principal (MAIN_DEPARTMENT, el de `jefe.sc`, el usuario que imprime `demo:seed`; sin él, el de más grupos): una
 *   fallida (failedRun(), de hace tres horas), una cancelada mientras corría (de hace dos), una del motor de prueba
 *   descartada, una de la heurística lista, que es la que la pantalla abre (la semana con todo lo que cambia contra la
 *   carga de hoy), y una en cola vieja (más de RunSolver::STALE_AFTER sin moverse; no estorba: la siguiente que se
 *   lance la da por perdida, SV-01). La en cola se crea al final, con su fecha de antes, para que lanzar no la dé por
 *   perdida. Así el jefe ve en su historial todos los estados que caben en un departamento salvo la aplicada: la que
 *   corre es de carrera, que el jefe no ve (SolverRunPolicy::view).
 * - el amplio, de preferencia con grupos en más de un campus y luego el de más grupos: una del motor de prueba y una de
 *   la heurística listas (medido el 18 de noviembre de 2026: con el volumen demo, la de CEA deja de 1 a 3 grupos sin
 *   acomodar; con el masivo en `todo`, la de II deja 62).
 * - al final, la carrera del principal con más grupos y coordinador: una de exploración que sigue corriendo (lanzada
 *   hace unos minutos, con diez de límite y el avance a medias; la siguiente que se lance de su carrera o de su
 *   departamento la da por perdida, SV-01) y una de la heurística lista, la más nueva, que es la que abre el
 *   coordinador. La de carrera no ocupa el lugar del departamento. El coordinador ve además las de departamento de las
 *   cargas que tocan su carrera, así que ve todos los estados.
 *
 * Las fechas son relativas a `now()`, no al periodo: la pantalla dice «Hoy a las…» y la en cola tiene que ser más vieja
 * que RunSolver::STALE_AFTER contra la hora en que se mira, así que dos siembras no dejan las mismas fechas (lo demás sí,
 * con la misma semilla). La heurística decide por su plazo: si una corrida llega a él, sus números dependen de la
 * máquina y la consola lo avisa (reachedDeadline(); con el volumen demo ninguna llega, lo revisa su prueba).
 *
 * Idempotente por estado: cada corrida se crea solo si su alcance todavía no tiene una en ese estado (y con ese motor),
 * así que volver a sembrar deja los mismos conteos y una siembra cortada a medias se completa. Sin el periodo, la
 * modalidad o departamentos con grupos, no hace nada. Se niega a correr en producción.
 */
final class SolverRunsDemoSeeder extends Seeder
{
    use RefusesProduction;

    /** El departamento que la demo enseña primero: el de `jefe.sc`. */
    public const string MAIN_DEPARTMENT = 'SC';

    /** El límite de las corridas que se lanzan, el más chico que acepta LaunchSolverRun más un poco. */
    public const int TIME_LIMIT = 15;

    public const string UNAVAILABLE = 'El servicio del motor no está disponible.';

    /** El error de una corrida que se dio por perdida (SV-01), el de LaunchSolverRun. */
    public const string STALE_ERROR = 'No terminó en '.(RunSolver::STALE_AFTER / 60).' minutos: se lanzó otra corrida.';

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

            if ($accepting !== null) {
                $this->acceptingDepartment($termModel, $modalityModel, $accepting, $apply);
            }

            $this->mainDepartment($termModel, $modalityModel, $main);

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
        $has = fn (SolverRunStatus $status, ?string $engine = null): bool => $this->has($term, $modality, RunScope::Department, $department->id, $status, $engine);

        if (! $has(SolverRunStatus::Failed)) {
            $this->failedRun($term, $modality, $department, $head);
        }

        if (! $has(SolverRunStatus::Cancelled)) {
            $interrupted = $this->fake($term, $modality, RunScope::Department, $department->id, $head, [
                'engine' => PhpHeuristicEngine::KEY,
                'status' => SolverRunStatus::Running,
                'progress' => 30,
                'best_score' => 2400,
            ], now()->subHours(2));
            app(CancelSolverRun::class)->handle($interrupted, $head);
        }

        if (! $has(SolverRunStatus::Discarded)) {
            $discarded = $this->launch($term, $modality, RunScope::Department, $department->id, FixedEngine::KEY, $head);
            app(DiscardSolverRun::class)->handle($discarded, $head);
        }

        if (! $has(SolverRunStatus::Done, PhpHeuristicEngine::KEY)) {
            $this->launch($term, $modality, RunScope::Department, $department->id, PhpHeuristicEngine::KEY, $head);
        }

        if (! $has(SolverRunStatus::Queued)) {
            $this->fake($term, $modality, RunScope::Department, $department->id, $head, [
                'engine' => PhpHeuristicEngine::KEY,
                'status' => SolverRunStatus::Queued,
                'progress' => 0,
            ], now()->subSeconds(2 * RunSolver::STALE_AFTER), started: false);
        }
    }

    /**
     * La fallida del departamento principal (SV-08). Sin el servicio de CP-SAT (CpSatClient::configured()), LaunchSolverRun
     * ni acepta ese motor, y una fallida de CP-SAT es justo lo que la pantalla tiene que enseñar: sale de la factory, de
     * hace tres horas, con el mensaje de un servicio que no está. Con el servicio configurado se lanza de verdad; si
     * termina bien, la fallida es una de la heurística que se dio por perdida (SV-01), también de la factory.
     */
    private function failedRun(Term $term, Modality $modality, Department $department, User $head): void
    {
        if (CpSatClient::configured()) {
            $run = app(LaunchSolverRun::class)->handle($term, $modality, RunScope::Department, $department->id, CpSatEngine::KEY, self::TIME_LIMIT, $head);

            if ($run->status === SolverRunStatus::Failed) {
                return;
            }
        }

        $this->fake($term, $modality, RunScope::Department, $department->id, $head, CpSatClient::configured()
            ? ['engine' => PhpHeuristicEngine::KEY, 'status' => SolverRunStatus::Failed, 'error' => self::STALE_ERROR, 'progress' => 40]
            : ['engine' => CpSatEngine::KEY, 'status' => SolverRunStatus::Failed, 'error' => self::UNAVAILABLE, 'progress' => 0],
            now()->subHours(3), finishedAfter: 2);
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

        if ($career === null) {
            return;
        }

        ['career' => $model, 'coordinator' => $coordinator] = $career;

        if (! $this->has($term, $modality, RunScope::Career, $model->id, SolverRunStatus::Running)) {
            $this->fake($term, $modality, RunScope::Career, $model->id, $coordinator, [
                'engine' => PhpHeuristicEngine::KEY,
                'status' => SolverRunStatus::Running,
                'progress' => 45,
                'best_score' => 1800,
            ], now()->subMinutes(4), timeLimit: LaunchSolverRun::MAX_TIME_LIMIT);
        }

        if (! $this->has($term, $modality, RunScope::Career, $model->id, SolverRunStatus::Done, PhpHeuristicEngine::KEY)) {
            $this->launch($term, $modality, RunScope::Career, $model->id, PhpHeuristicEngine::KEY, $coordinator);
        }
    }

    /**
     * @param  array{department: Department, head: User, groups: int, campuses: int, editable: bool, proposed: bool}  $row
     */
    private function acceptingDepartment(Term $term, Modality $modality, array $row, bool $apply): void
    {
        ['department' => $department, 'head' => $head] = $row;

        if ($this->has($term, $modality, RunScope::Department, $department->id, $apply ? SolverRunStatus::Applied : SolverRunStatus::Done, PhpHeuristicEngine::KEY)) {
            return;
        }

        $run = $this->launch($term, $modality, RunScope::Department, $department->id, PhpHeuristicEngine::KEY, $head);

        if ($apply) {
            $this->accept($term, $modality, $department, $head, $run);
        }
    }

    /**
     * SV-05: el jefe acepta la corrida (RequestApplySolverRun y su job, en la cola síncrona) con GroupScheduleChanged
     * descartado mientras se escribe. Exige que quede aplicada y con los choques recalculados (el `conflicts_pending`
     * falso de ApplySolverRun: su recálculo terminó y avisó ScheduleConflictsSynced); si no, la demo se detiene con el
     * motivo. Escribe en la consola lo que se escribió, lo que se quedó como estaba, los problemas de entrada y si un
     * préstamo quedó bloqueado. Después vuelve a calcular la clave de cada grupo de la carga (AssignGroupCode) y manda un
     * solo GroupScheduleChanged, con las horas cambiadas, de un grupo de la carga: así los escuchas encolan una vez el
     * recálculo de choques (RecomputeScheduleConflicts, único por periodo y modalidad) y el ranking docente
     * (ComputeRankings, único por periodo, modalidad y departamento: la carga), en vez de una vez por grupo. Va de
     * preferencia un grupo sin docente, para no encolar además los alcances de un docente.
     */
    private function accept(Term $term, Modality $modality, Department $department, User $head, SolverRun $run): void
    {
        $synced = false;
        Event::listen(ScheduleConflictsSynced::class, function (ScheduleConflictsSynced $event) use (&$synced, $term, $modality): void {
            $synced = $synced || ($event->termId === $term->id && $event->modalityId === $modality->id);
        });

        Event::fakeFor(fn () => app(RequestApplySolverRun::class)->handle($run, $head), [GroupScheduleChanged::class]);
        $run->refresh();

        if ($run->status !== SolverRunStatus::Applied) {
            throw new RuntimeException("La corrida de {$department->code} no se aplicó: ".($run->metrics['apply_error'] ?? $run->status->label()).'.');
        }

        if (! $synced) {
            throw new RuntimeException("La corrida de {$department->code} se aplicó, pero los choques quedaron por recalcular (conflicts_pending).");
        }

        $this->reportApplied($run, $department);

        $groups = Group::query()->active()->where('term_id', $term->id)->where('modality_id', $modality->id)->where('department_id', $department->id)
            ->orderByRaw('teacher_id is not null')->orderBy('id')->get();
        $groups->sortBy('id')->each(fn (Group $group) => app(AssignGroupCode::class)->handle($group));

        if ($groups->isNotEmpty()) {
            GroupScheduleChanged::dispatch($groups->first()->refresh(), true);
        }
    }

    /**
     * El resumen de la aceptación en la consola, de su bitácora (`applied`).
     */
    private function reportApplied(SolverRun $run, Department $department): void
    {
        $summary = Activity::query()->where('subject_type', $run->getMorphClass())->where('subject_id', $run->getKey())
            ->where('event', 'applied')->latest('id')->value('properties')['summary'] ?? [];
        $problems = collect($summary['input_problems'] ?? [])->map(fn (array $problem): string => "{$problem['rule']} ({$problem['message']})");

        $this->command?->info(sprintf('Aceptada la corrida de %s: %d sesiones en %d grupos; se quedan como estaban %d grupos; problemas de entrada: %s; préstamos bloqueados: %s.',
            $department->code, $summary['sessions'] ?? 0, $summary['groups'] ?? 0, count($summary['kept'] ?? []),
            $problems->isEmpty() ? 'ninguno' : $problems->implode('; '), ($summary['loans_blocked'] ?? false) ? 'sí' : 'no'));
    }

    /**
     * @param  array{department: Department, head: User, groups: int, campuses: int, editable: bool, proposed: bool}  $row
     */
    private function wideDepartment(Term $term, Modality $modality, array $row): void
    {
        ['department' => $department, 'head' => $head] = $row;

        foreach ([FixedEngine::KEY, PhpHeuristicEngine::KEY] as $engine) {
            if (! $this->has($term, $modality, RunScope::Department, $department->id, SolverRunStatus::Done, $engine)) {
                $this->launch($term, $modality, RunScope::Department, $department->id, $engine, $head);
            }
        }
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

    /**
     * Si el alcance ya tiene la corrida que el paso deja en ese estado (y con ese motor): la guarda de cada una, para que
     * volver a sembrar no repita las que ya están y complete las que faltan si una siembra se cortó a medias.
     */
    private function has(Term $term, Modality $modality, RunScope $scope, string $scopeId, SolverRunStatus $status, ?string $engine = null): bool
    {
        return SolverRun::query()
            ->where('term_id', $term->id)
            ->where('modality_id', $modality->id)
            ->where('scope_kind', $scope)
            ->where($scope === RunScope::Department ? 'department_id' : 'career_id', $scopeId)
            ->where('status', $status)
            ->when($engine !== null, fn ($query) => $query->where('engine', $engine))
            ->exists();
    }

    private function launch(Term $term, Modality $modality, RunScope $scope, string $scopeId, string $engine, User $by): SolverRun
    {
        $run = app(LaunchSolverRun::class)->handle($term, $modality, $scope, $scopeId, $engine, self::TIME_LIMIT, $by);

        if ($run->status !== SolverRunStatus::Done) {
            throw new RuntimeException("La corrida «{$engine}» de {$scope->label()} no terminó: ".($run->error ?? $run->status->label()).'.');
        }

        if (self::reachedDeadline($run)) {
            $this->command?->warn("La corrida «{$engine}» de {$scope->label()} llegó a su plazo de ".self::TIME_LIMIT.' s: sus números dependen de la máquina.');
        }

        return $run;
    }

    /**
     * Si la heurística llegó a su plazo antes de terminar: entonces lo que dejó depende de cuánto avanzó la máquina en ese
     * tiempo y la demo deja de dar siempre lo mismo. Lo dice su bitácora (se acabó el tiempo, no quedó tiempo para buscar
     * o para validar, la búsqueda paró por el tiempo) o lo que tardó, del 90 % del límite en adelante.
     */
    public static function reachedDeadline(SolverRun $run): bool
    {
        $output = RunPayloads::output($run);

        if ($output === null) {
            return false;
        }

        $limit = (int) (RunPayloads::read($run, 'input')['time_limit_seconds'] ?? self::TIME_LIMIT);

        return $output->metrics->elapsedSeconds >= 0.9 * $limit
            || preg_match('/tiempo límite|No quedó tiempo/u', implode("\n", $output->log)) === 1;
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
