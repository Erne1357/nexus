<?php

use App\Modules\AcademicLoad\Actions\Transitions\CancelReanalysis;
use App\Modules\AcademicLoad\Actions\Transitions\StartReanalysis;
use App\Modules\AcademicLoad\Enums\AcademicLoadPermission;
use App\Modules\AcademicLoad\Enums\LoadStatus;
use App\Modules\AcademicLoad\Models\AcademicLoad;
use App\Modules\Core\Authorization\Actor;
use App\Modules\Core\Livewire\Concerns\WithModalityCampusFilter;
use App\Modules\Scheduling\Queries\ReanalysisBoardQuery;
use App\Modules\Scheduling\Queries\ReanalysisLoadsQuery;
use App\Modules\Scheduling\Queries\ReanalysisStateQuery;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Mary\Traits\Toast;

/**
 * Re-análisis de la carga (tarea 8.2, pantalla, #602; RA-01 a RA-10; opción B «Por materia» de
 * `.impeccable/mocks/reanalisis/`, elegida por el usuario el 18 de noviembre de 2026). La carga del departamento en el
 * periodo activo y la modalidad de la página (`?modalidad=`, M1), elegida con el select «Carga» (`?departamento=`) a quien
 * ve varias; solo las que ya se publicaron alguna vez.
 *
 * - **Publicada:** la línea del ciclo (Publicada vN → Calificaciones N % → Re-análisis → Cierre → Publicar vN+1), las tres
 *   condiciones de RA-02 con el avance de las calificaciones y «Iniciar re-análisis» (StartReanalysis), con confirmación
 *   (ADR-0023) o deshabilitado con su motivo.
 * - **En re-análisis:** el diff de demanda (ReanalysisDiffQuery) por materia y campus, con los filtros de clase, búsqueda,
 *   carrera y campus; lo que el jefe ya ajustó por bloque y en el drawer «Lo que ya cambiaste»; «Cancelar re-análisis»
 *   (CancelReanalysis, RA-10) solo sin cambios, y «Cerrar re-análisis», que va a «Cierre de la carga». Cada bloque abre el
 *   drawer de sugerencias (`scheduling::components.reanalysis-suggestions`, que llena la tarea 8.3).
 * - **Coordinador** (RA-07): sin `academic_load.load.view`, con la propuesta de una carrera (`academic_load.proposal.view`):
 *   las cargas que imparten materias de sus carreras, los mismos bloques filtrados a ellas, sin acciones y con la nota de
 *   que los ajustes los hace la jefatura. Sus carreras se resuelven en el servidor con Actor en cada petición.
 *
 * Entra quien ve la carga o la propuesta de una carrera; un departamento ajeno es 403. Cada método vuelve a autorizar.
 */
new #[Title('Re-análisis de la carga')] class extends Component
{
    use Toast, WithModalityCampusFilter;

    /** El id del departamento de la carga; '' es la primera en re-análisis, o la primera. */
    #[Url(as: 'departamento')]
    public string $department = '';

    /** Clase de los renglones: shortfall, surplus, adjusted, new o '' (todas). */
    #[Url(as: 'clase')]
    public string $class = '';

    /** Solo los renglones marcados para revisar o sin historia. */
    #[Url(as: 'revisar')]
    public bool $review = false;

    /** El id de una carrera del diff, o '' (todas). */
    #[Url(as: 'carrera')]
    public string $career = '';

    #[Url(as: 'buscar')]
    public string $search = '';

    /** La carga a la vista; el navegador no la puede cambiar. */
    #[Locked]
    public ?string $loadId = null;

    /**
     * Las cargas del select «Carga».
     *
     * @var list<array{id: string, name: string}>
     */
    #[Locked]
    public array $loads = [];

    /** Por qué no hay carga que mostrar. */
    #[Locked]
    public ?string $message = null;

    public bool $showChanges = false;

    public function mount(Actor $context, ReanalysisLoadsQuery $query): void
    {
        abort_unless($context->allowsAny(AcademicLoadPermission::LoadView) || $context->allowsAny(AcademicLoadPermission::ProposalView), 403);

        $this->resolve($context, $query);
    }

    public function updatedDepartment(Actor $context, ReanalysisLoadsQuery $query): void
    {
        $this->resetFilters();
        $this->resolve($context, $query);
    }

    public function updatedModalidad(Actor $context, ReanalysisLoadsQuery $query): void
    {
        $this->department = '';
        $this->resetFilters();
        $this->resolve($context, $query);
    }

    public function updatedClass(): void
    {
        $this->class = in_array($this->class, ReanalysisBoardQuery::CLASSES, true) ? $this->class : '';
    }

    /**
     * Un filtro de clase: lo pone o, si ya estaba, lo quita.
     */
    public function toggleClass(string $class): void
    {
        $this->class = $this->class === $class || ! in_array($class, ReanalysisBoardQuery::CLASSES, true) ? '' : $class;
    }

    public function clearFilters(): void
    {
        $this->resetFilters();
        $this->campus = null;
    }

    /**
     * Inicia el re-análisis (RA-01, RA-02): la Action vuelve a autorizar y revisa la guarda con la carga bloqueada.
     */
    public function startReanalysis(Actor $context, ReanalysisLoadsQuery $query, StartReanalysis $action): void
    {
        $load = $this->authorizedLoad($context, $query);
        Gate::authorize('startReanalysis', $load);

        try {
            $action->handle($load, $context->user());
        } catch (ValidationException $exception) {
            $this->error('No se inició el re-análisis', e(implode(' ', $exception->validator->errors()->all())));

            return;
        }

        $this->resetFilters();
        $this->success('Re-análisis iniciado', e("{$load->department->code} en {$load->modality->name}: los coordinadores ya ven el diff de su carrera."));
    }

    /**
     * Cancela el re-análisis sin cambios (RA-10): la carga vuelve a la versión vigente, sin una nueva.
     */
    public function cancelReanalysis(Actor $context, ReanalysisLoadsQuery $query, CancelReanalysis $action): void
    {
        $load = $this->authorizedLoad($context, $query);
        Gate::authorize('cancelReanalysis', $load);

        try {
            $action->handle($load, $context->user());
        } catch (ValidationException $exception) {
            $this->error('No se canceló el re-análisis', e(implode(' ', $exception->validator->errors()->all())));

            return;
        }

        $this->showChanges = false;
        $this->resetFilters();
        $this->success('Re-análisis cancelado', e("La carga sigue publicada en la versión {$load->current_version}."));
    }

    /**
     * Abre «Lo que ya cambiaste»: solo quien ve toda la carga.
     */
    public function openChanges(Actor $context, ReanalysisLoadsQuery $query): void
    {
        $load = $this->authorizedLoad($context, $query);
        abort_unless($query->careerScopeOf($context, $load) === null, 403);

        $this->showChanges = true;
    }

    /**
     * @return array<string, mixed>
     */
    public function with(Actor $context, ReanalysisLoadsQuery $query, ReanalysisStateQuery $states, ReanalysisBoardQuery $boards): array
    {
        $scope = $this->loadId === null ? [] : $this->scopeViewData();
        $empty = ['load' => null, 'state' => null, 'board' => null, 'careerIds' => null, 'careerCodes' => '', 'abilities' => [], 'closingUrl' => null];

        if ($this->loadId === null) {
            $modalities = $this->modalityOptions();

            return [...$empty, 'modalityOptions' => $modalities, 'shownModality' => $modalities->isEmpty() ? null : $this->requiredModality(), 'campusOptions' => collect(), 'shownCampus' => null];
        }

        $load = $this->authorizedLoad($context, $query);
        $careerIds = $query->careerScopeOf($context, $load);
        $state = $states->of($load);
        $full = $careerIds === null;
        $board = $state->stage === 'reanalysis' ? $boards->of($load, $careerIds, [
            'class' => $this->class,
            'review' => $this->review,
            'career' => $this->career,
            'campus' => $scope['shownCampus']?->getKey(),
            'search' => $this->search,
        ], withChanges: $full) : null;

        return [
            ...$scope,
            'load' => $load,
            'state' => $state,
            'board' => $board,
            'careerIds' => $careerIds,
            'abilities' => [
                'start' => Gate::allows('startReanalysis', $load),
                'cancel' => Gate::allows('cancelReanalysis', $load),
                'close' => Gate::allows('close', $load) && Route::has('staffing.closing'),
            ],
            'closingUrl' => Route::has('staffing.closing') ? route('staffing.closing', ['departamento' => $load->department_id, 'modalidad' => $load->modality->code]) : null,
            'careerCodes' => $full ? '' : implode(', ', $query->careerCodes($careerIds)),
        ];
    }

    /**
     * La carga de la página, autorizada otra vez en esta petición: quien la ve completa (`view`) o, sin eso, quien ve la
     * propuesta de alguna carrera en su modalidad (RA-07). Si no, 403.
     */
    private function authorizedLoad(Actor $context, ReanalysisLoadsQuery $query): AcademicLoad
    {
        abort_if($this->loadId === null, 404);

        $load = $query->findOrFail($this->loadId);
        $careers = $query->careerScopeOf($context, $load);
        abort_if($careers === [], 403);

        return $load;
    }

    /**
     * Resuelve con la credencial el periodo, la modalidad y la carga, y deja en propiedades bloqueadas cuál se ve (o por
     * qué no hay ninguna). Un departamento pedido que el usuario no ve es 403.
     */
    private function resolve(Actor $context, ReanalysisLoadsQuery $query): void
    {
        $this->loadId = null;
        $this->loads = [];
        $this->showChanges = false;
        $term = $context->activeTerm();

        if ($term === null) {
            $this->message = 'No hay un periodo activo: elige uno en tu credencial.';

            return;
        }

        $modality = $this->requiredModality();
        $careerIds = $context->allowsAny(AcademicLoadPermission::LoadView) ? null : $query->careerIdsFor($context, $modality->getKey());

        if ($careerIds === []) {
            $this->message = "No ves la propuesta de ninguna carrera en {$modality->name}.";

            return;
        }

        $loads = $query->loadsFor($context, $term->getKey(), $modality->getKey(), $careerIds);
        $this->loads = $loads->map(fn (AcademicLoad $load): array => [
            'id' => $load->department_id,
            'name' => "{$load->department->code} · {$load->department->name} — {$load->status->label()}",
        ])->values()->all();

        $load = $this->department === ''
            ? $loads->first(fn (AcademicLoad $load): bool => $load->status === LoadStatus::Reanalysis) ?? $loads->first()
            : $loads->firstWhere('department_id', $this->department);

        if ($this->department !== '' && $load === null) {
            // Uno que el usuario ve pero sin carga publicada lo dice; uno ajeno es 403.
            abort_unless($careerIds === null && $query->seesDepartment($context, $this->department, $modality->getKey()), 403);
            $this->message = ($query->department($this->department)?->name ?? 'El departamento')." no tiene una carga publicada en {$modality->name} en {$term->name}.";

            return;
        }

        if ($load === null) {
            $this->message = $careerIds === null
                ? "No hay cargas publicadas en {$modality->name} en {$term->name}: el re-análisis empieza con una carga publicada."
                : "Ninguna carga con materias de tu carrera está publicada en {$modality->name} en {$term->name}.";

            return;
        }

        $this->message = null;
        $this->loadId = $load->getKey();
        $this->department = $load->department_id;
    }

    private function resetFilters(): void
    {
        $this->reset('class', 'review', 'career', 'search');
    }
};
