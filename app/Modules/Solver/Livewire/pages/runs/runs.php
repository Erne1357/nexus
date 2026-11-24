<?php

use App\Modules\Academic\Models\Career;
use App\Modules\Academic\Models\Department;
use App\Modules\Core\Authorization\Actor;
use App\Modules\Core\Livewire\Concerns\WithModalityCampusFilter;
use App\Modules\Core\Models\Modality;
use App\Modules\Core\Models\Setting;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\User;
use App\Modules\Core\Queries\KeyLookup;
use App\Modules\Core\Settings\SettingDefinition;
use App\Modules\Core\Support\WeekGrid;
use App\Modules\Solver\Actions\ApplySolverRun;
use App\Modules\Solver\Actions\CancelSolverRun;
use App\Modules\Solver\Actions\DiscardSolverRun;
use App\Modules\Solver\Actions\LaunchSolverRun;
use App\Modules\Solver\Actions\ReleaseStaleApplyingRun;
use App\Modules\Solver\Actions\RequestApplySolverRun;
use App\Modules\Solver\Contract\SoftWeights;
use App\Modules\Solver\Engines\CpSat\CpSatEngine;
use App\Modules\Solver\Engines\EngineRegistry;
use App\Modules\Solver\Engines\SolverEngine;
use App\Modules\Solver\Enums\RunScope;
use App\Modules\Solver\Enums\SolverPermission;
use App\Modules\Solver\Enums\SolverRunStatus;
use App\Modules\Solver\Models\SolverRun;
use App\Modules\Solver\Queries\CareerLoadsQuery;
use App\Modules\Solver\Queries\CommonCoreWarningQuery;
use App\Modules\Solver\Queries\RunComparison;
use App\Modules\Solver\Queries\RunComparisonQuery;
use App\Modules\Solver\Queries\RunOrderQuery;
use App\Modules\Solver\Queries\RunScreenQuery;
use App\Modules\Solver\Queries\SolverRunsQuery;
use App\Modules\Solver\Rules\SolverSettings;
use App\Modules\Solver\Support\RunPayloads;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Mary\Traits\Toast;

/**
 * Corridas del motor (tarea 6.10; variante C «Semana con cambios», elegida por el usuario el 17 de noviembre de 2026,
 * `.impeccable/mocks/corridas/c.html`; DESIGN.md, «Corridas del motor»; reglas SV-01 a SV-07).
 *
 * El jefe ve las corridas de la carga de su departamento en la modalidad, las lanza y acepta o descarta las terminadas;
 * el coordinador lanza las de su carrera para explorar, ve las de departamento de las cargas que tocan su carrera y no
 * acepta; Subdirección ve todas y elige el departamento (`?depto=`). Quien no ve corridas recibe 403. Lo que llega por la
 * URL o por un método pasa por SolverRunPolicy.
 *
 * Arriba, el aviso de tronco común (CommonCoreWarningQuery, SV-20) y el renglón de selectores: la corrida, el campus
 * (`x-core::campus-filter` con las sesiones que cambian en cada uno) y «Ver por» (alumno tipo, docente, salón o grupo)
 * con su lente. Una corrida terminada se compara con la carga en la semana de la cuadrícula del editor
 * (`Core\Support\WeekGrid`): a la izquierda la bandeja de grupos del lente con lo que cambia en cada uno y «Lo que
 * queda»; a la derecha «Con la corrida / Como está hoy / Las dos»; en celular, la lista de un día. Una corrida en
 * curso la lee cada 2 segundos un componente aparte (solver::components.run-progress, solo entonces) con su avance
 * y su mejor puntaje, sin volver a armar la página; al terminar, avisa y la página se pinta con su resultado. El
 * puntaje y lanzar van en drawers; aceptar, en un `x-modal` con el resumen (y la entrada vencida en otro); cancelar y
 * descartar, con `x-confirm`. Al pie, el historial. La URL guarda `?corrida=`, `?campus=`, `?ver=`, `?lente=`, `?modo=`, `?grupo=` y
 * `?dia=`.
 */
new #[Title('Corridas del motor')] class extends Component
{
    use Toast;
    use WithModalityCampusFilter;

    /** Los nombres cortos de las reglas blandas (maqueta C). */
    public const array SOFT_RULES = [
        'SC-01' => 'Huecos del alumno tipo', 'SC-02' => 'Reparto en días', 'SC-03' => 'Salón por defecto', 'SC-04' => 'Bloques preferidos del docente',
        'SC-05' => 'Compacidad del docente', 'SC-06' => 'Cambio de edificio', 'SC-07' => 'Semestres consecutivos', 'SC-08' => 'Turnos de honorarios',
        'SC-09' => 'Traslado entre campus',
    ];

    private const array LIMITS = [60, 120, 300, 600];

    #[Url(as: 'corrida')]
    public string $runId = '';

    #[Url(as: 'depto')]
    public string $department = '';

    #[Url(as: 'carrera')]
    public string $career = '';

    #[Url(as: 'ver')]
    public string $view = 'tipo';

    #[Url(as: 'lente')]
    public string $lens = '';

    #[Url(as: 'modo')]
    public string $mode = 'despues';

    #[Url(as: 'grupo')]
    public string $selected = '';

    /** El día de la lista del celular; '' es el primero. Lo cambia el navegador. */
    #[Url(as: 'dia')]
    public string $day = '';

    #[Locked]
    public ?string $termId = null;

    #[Locked]
    public ?string $modalityId = null;

    /** 'department' o 'career': de qué es el historial. */
    #[Locked]
    public string $scopeKind = 'department';

    #[Locked]
    public ?string $scopeId = null;

    #[Locked]
    public ?string $message = null;

    /** @var list<array{code: string, name: string}> */
    #[Locked]
    public array $scopeOptions = [];

    /** @var list<array{name: string, groups: int, without: int}> el aviso de tronco común (SV-20) */
    #[Locked]
    public array $commonCore = [];

    /** @var list<array{name: string, current: bool}> el orden sugerido (SV-21) */
    #[Locked]
    public array $order = [];

    public bool $showScore = false;

    public bool $showLaunch = false;

    public bool $showAccept = false;

    public bool $showStale = false;

    public string $launchScope = 'department';

    public string $launchCareer = '';

    public string $launchEngine = '';

    public int $launchLimit = 300;

    /** Si el drawer de lanzar viene de «Volver a correr»: la corrida que se repite. */
    #[Locked]
    public ?string $againId = null;

    /** Si «Volver a correr» viene de una corrida cuyo motor ya no está registrado: el nombre de ese motor. */
    public ?string $unavailableEngine = null;

    /**
     * Si el motor predeterminado de la modalidad (`solver.default_engine`) no está registrado, porque el servicio de
     * CP-SAT no está configurado en este servidor: su nombre. Se propone la heurística y el drawer lo dice (7.11).
     */
    public ?string $unconfiguredEngine = null;

    public function mount(Actor $context, RunScreenQuery $screen): void
    {
        abort_unless($context->allowsAny(SolverPermission::DepartmentRunView) || $context->allowsAny(SolverPermission::CareerRunView), 403);

        $run = $this->runId === '' ? null : (KeyLookup::find(SolverRun::query(), $this->runId) ?? abort(404));

        if ($run !== null) {
            $this->authorize('view', $run);
            $this->modalidad = Modality::query()->withTrashed()->whereKey($run->modality_id)->value('code');
        }

        $this->view = array_key_exists($this->view, RunComparison::VIEWS) ? $this->view : 'tipo';
        $this->mode = array_key_exists($this->mode, RunComparison::MODES) ? $this->mode : 'despues';
        $this->resolve($context, $screen, $run);
        $this->releaseStale($run ?? $this->newestRun($context));
    }

    /**
     * La corrida que la página abre sin `?corrida=`: la más nueva que ve quien entra.
     */
    private function newestRun(Actor $context): ?SolverRun
    {
        if ($this->scopeId === null) {
            return null;
        }

        [$term, $modality] = $this->context();
        $runs = $this->scopeKind === 'department'
            ? app(SolverRunsQuery::class)->forDepartment($term, $modality, KeyLookup::findOrFail(Department::withTrashed(), $this->scopeId), $context->user())
            : app(SolverRunsQuery::class)->forCareer($term, $modality, KeyLookup::findOrFail(Career::withTrashed(), $this->scopeId), $context->user());

        return $runs[0]['run'] ?? null;
    }

    /**
     * Al abrir, una corrida que se quedó «Aplicando» más de RunSolver::STALE_AFTER segundos (su job se perdió) vuelve a
     * estar lista, con su motivo (ReleaseStaleApplyingRun).
     */
    private function releaseStale(?SolverRun $run): void
    {
        if ($run !== null) {
            app(ReleaseStaleApplyingRun::class)->handle($run);
        }
    }

    /**
     * Otra modalidad, otro departamento u otra carrera: la página se abre de nuevo en ella.
     */
    public function updatedModalidad(): void
    {
        $this->redirectRoute('solver.runs', ['modalidad' => $this->modalidad], navigate: true);
    }

    public function updatedDepartment(): void
    {
        $this->redirectRoute('solver.runs', array_filter(['modalidad' => $this->modalidad, 'depto' => $this->department]), navigate: true);
    }

    public function updatedCareer(): void
    {
        $this->redirectRoute('solver.runs', array_filter(['modalidad' => $this->modalidad, 'carrera' => $this->career]), navigate: true);
    }

    public function updatedCampus(): void
    {
        $this->lens = '';
        $this->selected = '';
    }

    public function updatedView(): void
    {
        $this->view = array_key_exists($this->view, RunComparison::VIEWS) ? $this->view : 'tipo';
        $this->lens = '';
    }

    public function updatedMode(): void
    {
        $this->mode = array_key_exists($this->mode, RunComparison::MODES) ? $this->mode : 'despues';
    }

    /**
     * Elige una corrida del historial: suelta el lente y el grupo.
     */
    public function selectRun(string $id): void
    {
        $run = $this->runOrFail($id);
        $this->releaseStale($run);
        $this->runId = $run->getKey();
        $this->lens = '';
        $this->selected = '';
        $this->showScore = $this->showAccept = $this->showStale = false;
    }

    /**
     * Elige un grupo de la bandeja o de la semana (otra vez, lo suelta).
     */
    public function selectGroup(string $groupId): void
    {
        $this->selected = $this->selected === $groupId ? '' : $groupId;
    }

    public function openScore(): void
    {
        $this->runOrFail($this->runId);
        $this->showScore = true;
    }

    /**
     * Abre «Lanzar una corrida» o, con una corrida, «Volver a correr» con su alcance, su motor y su límite. Sin corrida, el
     * motor es el predeterminado de la modalidad (`solver.default_engine`, 7.11).
     */
    public function openLaunch(EngineRegistry $engines, ?string $againId = null): void
    {
        abort_if($this->scopeId === null, 403);
        $again = $againId === null ? null : $this->runOrFail($againId);
        $options = $this->launchOptions();

        $this->againId = $again?->getKey();
        $this->launchScope = match (true) {
            $again !== null => $again->scope_kind->value,
            $options['department'] => 'department',
            default => 'career',
        };
        $this->launchCareer = $again?->career_id ?? ($options['careers'][0]['id'] ?? '');
        $registered = array_map(fn (SolverEngine $engine): string => $engine->key(), $engines->all());
        $this->unavailableEngine = $again !== null && ! in_array($again->engine, $registered, true) ? $this->engineLabel($again->engine) : null;
        $modalityCode = $this->context()[1]->code;
        $this->launchEngine = $again !== null && $this->unavailableEngine === null ? $again->engine : $engines->default($modalityCode)->key();
        $preferred = SolverSettings::fromSettings()->defaultEngineFor($modalityCode);
        $this->unconfiguredEngine = $again === null && ! in_array($preferred, $registered, true) ? $this->engineLabel($preferred) : null;
        $this->launchLimit = $again === null ? SolverSettings::fromSettings()->timeLimitFor(RunScope::from($this->launchScope)) : (int) (RunPayloads::read($again, 'input')['time_limit_seconds'] ?? 300);
        $this->showLaunch = true;
    }

    /**
     * «Volver a correr» desde la entrada vencida: cierra el aviso y abre el drawer de lanzar con esa corrida.
     */
    public function rerun(EngineRegistry $engines): void
    {
        $this->showStale = false;
        $this->openLaunch($engines, $this->runId);
    }

    /**
     * Cambiar el alcance en el drawer pone el límite de ese alcance (`solver.time_limits`).
     */
    public function updatedLaunchScope(): void
    {
        $this->launchScope = in_array($this->launchScope, ['department', 'career'], true) ? $this->launchScope : 'department';
        $this->launchLimit = SolverSettings::fromSettings()->timeLimitFor(RunScope::from($this->launchScope));
    }

    public function launch(LaunchSolverRun $launch, Actor $context): void
    {
        [$term, $modality] = $this->context();
        $scope = RunScope::tryFrom($this->launchScope) ?? RunScope::Department;
        $scopeId = $scope === RunScope::Department ? (string) $this->departmentScopeId() : $this->launchCareer;

        try {
            $run = $launch->handle($term, $modality, $scope, $scopeId, $this->launchEngine, $this->launchLimit, $context->user());
        } catch (AuthorizationException) {
            $this->error('No se lanzó', e($scope === RunScope::Department ? 'No lanzas corridas de esta carga.' : 'No lanzas corridas de esa carrera.'));

            return;
        } catch (ValidationException $exception) {
            $this->error('No se lanzó', e((string) collect($exception->errors())->flatten()->first()));

            return;
        }

        $this->showLaunch = false;
        $this->againId = null;
        $this->unavailableEngine = null;
        $this->unconfiguredEngine = null;
        $this->runId = $run->getKey();
        $this->lens = '';
        $this->selected = '';
        $this->success('Corrida lanzada', e(($scope === RunScope::Department ? 'Toda la carga' : 'Carrera '.($run->career->code ?? '')).' · '.$this->engineLabel($run->engine)));
    }

    /**
     * SV-03: cancela la corrida elegida (confirmado con `x-confirm`).
     */
    public function cancel(CancelSolverRun $cancel, Actor $context): void
    {
        $run = $this->runOrFail($this->runId);

        try {
            $cancel->handle($run, $context->user());
        } catch (ValidationException $exception) {
            $this->error('No se canceló', e((string) collect($exception->errors())->flatten()->first()));

            return;
        }

        $this->success('Corrida cancelada', $run->fresh()->status === SolverRunStatus::Cancelled ? null : e('Se detiene en su siguiente aviso de avance.'));
    }

    /**
     * SV-07: descarta la corrida elegida (confirmado con `x-confirm`).
     */
    public function discard(DiscardSolverRun $discard, Actor $context): void
    {
        $run = $this->runOrFail($this->runId);

        try {
            $discard->handle($run, $context->user());
        } catch (ValidationException $exception) {
            $this->error('No se descartó', e((string) collect($exception->errors())->flatten()->first()));

            return;
        }

        $this->success('Corrida descartada', e('La carga no cambió.'));
    }

    /**
     * Abre «¿Aceptar la corrida?» o, si la carga ya cambió (SV-02), «La entrada ya cambió».
     */
    public function openAccept(ApplySolverRun $apply): void
    {
        $run = $this->runOrFail($this->runId);

        if ($run->status !== SolverRunStatus::Done) {
            $this->error('No se aceptó', e('Solo se acepta una corrida terminada.'));

            return;
        }

        $this->authorize('apply', $run);

        if (($blocked = $apply->loadBlockReason($run)) !== null) {
            $this->error('No se aceptó', e($blocked));

            return;
        }

        if (! $apply->isCurrent($run)) {
            $this->showStale = true;

            return;
        }

        $this->showAccept = true;
    }

    /**
     * SV-05: pide aceptar la corrida elegida (RequestApplySolverRun autoriza y la pasa a «Aplicando»; el job en cola la
     * escribe). Si otra persona la aceptó primero, esta petición falla con su motivo.
     */
    public function apply(RequestApplySolverRun $request, Actor $context): void
    {
        $run = $this->runOrFail($this->runId);

        try {
            $request->handle($run, $context->user());
        } catch (ValidationException $exception) {
            $message = (string) collect($exception->errors())->flatten()->first();
            $this->showAccept = false;

            if ($message === ApplySolverRun::STALE_MESSAGE) {
                $this->showStale = true;

                return;
            }

            $this->error('No se aceptó', e($message));

            return;
        }

        $this->showAccept = false;

        if ($run->refresh()->status === SolverRunStatus::Applied) {
            $this->success('Corrida aplicada', e('Horario escrito.'));

            return;
        }

        $this->success('Aplicando la corrida', e('Se escribe en segundo plano; te avisa la campana al terminar.'));
    }

    /**
     * La corrida en curso terminó (lo avisa solver::components.run-progress, que es el que lee cada 2 segundos): la
     * página se vuelve a pintar con su resultado.
     */
    #[On('run-finished')]
    public function runFinished(): void {}

    /**
     * @return array<string, mixed>
     */
    public function with(Actor $context): array
    {
        $base = ['message' => $this->message, ...$this->scopeViewData()];

        if ($this->message !== null) {
            return $base;
        }

        [$term, $modality] = $this->context();
        $user = $context->user();
        $department = $this->scopeKind === 'department' ? KeyLookup::find(Department::withTrashed(), $this->scopeId) : null;
        $career = $this->scopeKind === 'career' ? KeyLookup::find(Career::withTrashed(), $this->scopeId) : null;
        $runs = $department !== null
            ? app(SolverRunsQuery::class)->forDepartment($term, $modality, $department, $user)
            : app(SolverRunsQuery::class)->forCareer($term, $modality, $career, $user);
        $row = collect($runs)->first(fn (array $row): bool => $row['run']->getKey() === $this->runId) ?? ($runs[0] ?? null);
        $this->runId = $row['run']->id ?? '';
        $run = $row === null ? null : KeyLookup::find(SolverRun::query(), $row['run']->id);
        $gate = Gate::forUser($user);
        $acceptBlock = $run?->scope_kind === RunScope::Department ? app(ApplySolverRun::class)->loadBlockReason($run) : null;

        return [
            ...$base,
            'acceptBlock' => $acceptBlock,
            'loadPublished' => $acceptBlock === ApplySolverRun::PUBLISHED_MESSAGE,
            'softRules' => self::SOFT_RULES,
            'term' => $term,
            'shownModality' => $modality,
            'scopeName' => $department?->name ?? ($career === null ? '' : "{$career->code} · {$career->name}"),
            'facts' => app(RunScreenQuery::class)->facts($term, $modality, $department, $career),
            'campuses' => $this->campusOptions()->keyBy('code'),
            'runs' => $runs,
            'row' => $row,
            'run' => $run,
            'engines' => collect(app(EngineRegistry::class)->all())->mapWithKeys(fn (SolverEngine $engine): array => [$engine->key() => $engine->label()])->all(),
            'engineNames' => $this->engineNames(),
            'can' => [
                'launch' => $this->launchOptions()['any'],
                'apply' => $run !== null && $gate->allows('apply', $run),
                'acceptRole' => $run !== null && $run->scope_kind === RunScope::Department && $gate->allows('apply', $run->replicate()->forceFill(['status' => SolverRunStatus::Done])),
                'discard' => $run !== null && $run->status === SolverRunStatus::Done && $gate->allows('discard', $run),
                'cancel' => $run !== null && $run->status->isInProgress() && $gate->allows('cancel', $run),
                'settings' => $user !== null && $this->canEditWeights($user),
            ],
            ...$this->runViewData($run, $row),
            ...$this->launchViewData($term, $modality, $department, $career),
        ];
    }

    /**
     * Lo que la página pinta de la corrida elegida: su avance, su comparación y su puntaje.
     *
     * @param  array{run: SolverRun, number: int}|null  $row
     * @return array<string, mixed>
     */
    private function runViewData(?SolverRun $run, ?array $row): array
    {
        $data = ['comparison' => null, 'output' => null, 'weights' => [], 'decision' => null];

        if ($run === null) {
            return $data;
        }

        $data['decision'] = in_array($run->status, [SolverRunStatus::Applied, SolverRunStatus::Discarded, SolverRunStatus::Cancelled], true) ? app(SolverRunsQuery::class)->decision($run) : null;

        $output = RunPayloads::output($run);
        $data['output'] = $output;
        $data['weights'] = $output === null ? [] : (RunPayloads::read($run, 'input')['soft_weights'] ?? []);

        $comparison = $output === null ? null : app(RunComparisonQuery::class)->of($run, $this->scopeKind === 'career' ? $this->scopeId : null);
        $data['comparison'] = $comparison;

        if ($run->status !== SolverRunStatus::Done || $comparison === null) {
            return $data;
        }

        $campus = $this->campusFilter()?->code;
        $options = $comparison->lensOptions($this->view, $campus);
        $lens = RunComparison::chooseLens($options, $this->lens);
        $this->lens = $lens['id'] ?? '';
        $this->selected = $this->selected !== '' && isset($comparison->movable()[$this->selected]) ? $this->selected : '';
        $items = $lens === null ? [] : $comparison->weekItems($lens['id'], $this->mode, $this->selected ?: null, $campus);
        $grid = WeekGrid::of(KeyLookup::findOrFail(Modality::withTrashed(), $run->modality_id)->profile)->covering($items);
        $laid = $grid->lay($items);
        $days = array_column($grid->days, 'day');
        $mobileDay = in_array((int) $this->day, $days, true) ? (int) $this->day : ($days[0] ?? 1);

        return [
            ...$data,
            'comparison' => $comparison,
            'campus' => $campus,
            'lensOptions' => $options,
            'lensChoice' => $lens,
            'grid' => $grid,
            'laid' => $laid,
            'lanes' => $grid->lanes($laid),
            'dayChanges' => RunComparison::changesByDay($items),
            'dayRows' => array_map(fn (array $items): array => $grid->dayRows($items, [], null), $laid),
            'firstDay' => $days[0] ?? 1,
            'mobileDay' => $mobileDay,
            'tray' => $lens === null ? [] : $comparison->tray($lens['id'], $campus),
            'kept' => $comparison->kept($campus),
            'stats' => $comparison->stats($campus),
            'allStats' => $comparison->stats(),
            'campusChanges' => $comparison->changesByCampus(),
            'selectedGroup' => $this->selected === '' ? null : $comparison->groups[$this->selected],
            'selectedLines' => $this->selected === '' ? [] : $comparison->linesOf($this->selected),
            'chainClasses' => WeekGrid::CHAINS,
            'chains' => $lens === null ? [] : $comparison->chains($lens['id'], $campus),
        ];
    }

    /**
     * Lo que pinta el drawer de lanzar.
     *
     * @return array<string, mixed>
     */
    private function launchViewData(Term $term, Modality $modality, ?Department $department, ?Career $career): array
    {
        $options = $this->launchOptions();
        $limits = array_values(array_unique([...self::LIMITS, $this->launchLimit]));
        sort($limits);

        return [
            'launch' => [
                ...$options,
                'limits' => $limits,
                'weights' => (new SoftWeights($this->currentWeights()))->toArray(),
                'again' => $this->againId === null ? null : KeyLookup::find(SolverRun::query(), $this->againId),
                'departmentName' => $department?->name,
            ],
        ];
    }

    /**
     * Qué puede lanzar quien mira: la carga del departamento de la página y las carreras que puede explorar.
     *
     * @return array{department: bool, careers: list<array{id: string, code: string, name: string}>, any: bool}
     */
    private function launchOptions(): array
    {
        if ($this->scopeId === null) {
            return ['department' => false, 'careers' => [], 'any' => false];
        }

        [$term, $modality] = $this->context();
        $user = app(Actor::class)->user();
        $gate = Gate::forUser($user);
        $department = $this->scopeKind === 'department' ? $this->scopeId : null;
        $careers = $this->scopeKind === 'career'
            ? Career::withTrashed()->whereKey($this->scopeId)->get()
            : app(RunScreenQuery::class)->careersOfDepartment($term, $modality, KeyLookup::findOrFail(Department::withTrashed(), $this->scopeId));
        $careers = $careers->filter(fn (Career $career): bool => $gate->allows('launchForCareer', [SolverRun::class, $modality->id, $career->id]))
            ->map(fn (Career $career): array => ['id' => $career->id, 'code' => $career->code, 'name' => $career->name])->values()->all();
        $canDepartment = $department !== null && $gate->allows('launchForDepartment', [SolverRun::class, $modality->id, $department]);

        return ['department' => $canDepartment, 'careers' => $careers, 'any' => $canDepartment || $careers !== []];
    }

    /**
     * Los pesos de las reglas blandas que fija Subdirección para todo el Tec (`solver.soft_weights`, SV-04): los mismos
     * que BuildSolverInput pone en la entrada. En el drawer van de solo lectura; no hay pesos por corrida.
     *
     * @return array<string, int>
     */
    private function currentWeights(): array
    {
        return SolverSettings::fromSettings()->softWeightsByRule();
    }

    /**
     * Si quien mira puede cambiar los pesos en «Parámetros» (`solver.setting.update`).
     */
    private function canEditWeights(User $user): bool
    {
        $definition = collect(SolverSettings::definitions())->first(fn (SettingDefinition $definition): bool => $definition->key === SolverSettings::SOFT_WEIGHTS_SETTING);

        return $definition !== null && Gate::forUser($user)->allows('update', [Setting::class, $definition]);
    }

    private function engineLabel(string $key): string
    {
        return $this->engineNames()[$key] ?? $key;
    }

    /**
     * El nombre en pantalla de cada motor: los registrados y CP-SAT aunque el servicio no esté configurado, para que el
     * historial no muestre la clave de una corrida de cuando sí lo estaba.
     *
     * @return array<string, string>
     */
    private function engineNames(): array
    {
        $registered = collect(app(EngineRegistry::class)->all())->mapWithKeys(fn (SolverEngine $engine): array => [$engine->key() => $engine->label()])->all();

        return array_merge([CpSatEngine::KEY => CpSatEngine::LABEL], $registered);
    }

    /**
     * El periodo, la modalidad y el alcance de la página, con quién mira; una petición puede llegar de una página que
     * abrió otro usuario, así que la corrida pasa siempre por la policy.
     */
    private function runOrFail(string $id): SolverRun
    {
        abort_if($this->scopeId === null, 403);
        $run = KeyLookup::find(SolverRun::query()->where('term_id', $this->termId)->where('modality_id', $this->modalityId), $id) ?? abort(404);
        $this->authorize('view', $run);

        return $run;
    }

    /**
     * @return array{Term, Modality}
     */
    private function context(): array
    {
        return [KeyLookup::findOrFail(Term::query(), (string) $this->termId), KeyLookup::findOrFail(Modality::withTrashed(), (string) $this->modalityId)];
    }

    private function departmentScopeId(): ?string
    {
        return $this->scopeKind === 'department' ? $this->scopeId : null;
    }

    /**
     * El periodo (el de la corrida que llegó por la URL o el activo), la modalidad y la carga o la carrera.
     */
    private function resolve(Actor $context, RunScreenQuery $screen, ?SolverRun $run): void
    {
        $term = $run !== null ? KeyLookup::find(Term::query(), $run->term_id) : $context->loadTerm();
        $modality = $this->requiredModality();
        $this->modalidad = $modality->code;

        if ($term === null) {
            $this->message = 'No hay un periodo activo: elige uno en tu credencial.';

            return;
        }

        $departments = $screen->departmentsFor($context, $term, $modality);

        if ($departments->isNotEmpty()) {
            $wanted = $this->department !== '' ? $this->department : ($run === null ? null : ($run->department?->code ?? $run->career?->department?->code));
            $chosen = $wanted === null ? $departments->first() : $departments->firstWhere('code', $wanted);
            abort_if($chosen === null, 403);

            $this->scopeKind = 'department';
            $this->scopeId = $chosen->id;
            $this->department = $chosen->code;
            $this->scopeOptions = $departments->map(fn (Department $department): array => ['code' => $department->code, 'name' => $department->name])->all();
            $this->commonCore = array_map(fn (array $row): array => ['name' => $row['department']->name, 'groups' => $row['groups'], 'without' => $row['without_sessions']],
                app(CommonCoreWarningQuery::class)->forDepartment($term, $modality, $chosen));
            $this->order = array_map(fn (array $row): array => ['name' => $row['department']->name, 'current' => $row['department']->id === $chosen->id],
                app(RunOrderQuery::class)->forTerm($term, $modality));
        } else {
            $careers = $screen->careersFor($context, $term, $modality);
            $touching = $run?->scope_kind === RunScope::Department
                ? $careers->first(fn (Career $career): bool => Gate::allows('view', $run) && in_array($career->id, app(CareerLoadsQuery::class)->careersOf($run->term_id, $run->modality_id, $run->department_id), true))
                : null;
            $wanted = $this->career !== '' ? $this->career : ($touching?->code ?? $run?->career?->code);
            $chosen = $wanted === null ? $careers->first() : $careers->firstWhere('code', $wanted);

            if ($chosen === null) {
                abort_if($wanted !== null, 403);
                $this->message = "No tienes una carga ni una carrera con corridas en {$modality->name}.";

                return;
            }

            $this->scopeKind = 'career';
            $this->scopeId = $chosen->id;
            $this->career = $chosen->code;
            $this->scopeOptions = $careers->map(fn (Career $career): array => ['code' => $career->code, 'name' => "{$career->code} · {$career->name}"])->all();
        }

        $this->termId = $term->id;
        $this->modalityId = $modality->id;
        $this->message = null;
    }
};
