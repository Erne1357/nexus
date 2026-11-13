<?php

use App\Modules\Academic\Models\Department;
use App\Modules\AcademicLoad\Enums\AcademicLoadPermission;
use App\Modules\AcademicLoad\Enums\LoadStatus;
use App\Modules\AcademicLoad\Models\AcademicLoad;
use App\Modules\Core\Authorization\Actor;
use App\Modules\Core\Livewire\Concerns\WithModalityScope;
use App\Modules\Core\Queries\KeyLookup;
use App\Modules\Staffing\Livewire\Concerns\ClosesLoads;
use App\Modules\Staffing\Queries\ClosingBoardQuery;
use App\Modules\Staffing\Queries\LoadClosingQuery;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\On;
use Livewire\Attributes\Session;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Mary\Traits\Toast;

/**
 * Avance del cierre (tarea 4.2; maqueta E, elegida por el usuario el 10 de noviembre de 2026): el cierre de todas las
 * cargas del Tec en el periodo activo y en la modalidad de la página (`?modalidad=`, WithModalityScope; M1: una carga
 * por departamento y modalidad, con los grupos de todos sus campus), que se elige con el select «Modalidad» (maqueta
 * U2, opción A; sin select con una sola, MS16), con la forma del avance de validación. Cuatro cifras de todo el Tec en
 * esa modalidad (con faltantes, listas para cerrar, cerradas y publicadas), que filtran la matriz como el filtro de
 * estado (`?estado=`), la búsqueda, y un renglón por departamento con su jefe (ClosingBoardQuery::heads), la insignia de
 * su carga y los faltantes sumados por columna (ClosingBoardQuery, con los choques duros vigentes de
 * `schedule_conflicts`; por eso dice cuándo se recalcularon). Tocar un renglón o una insignia abre el
 * drawer de esa carga: el selector de modalidad con las cargas del departamento que el usuario reabre (elegir otra lleva
 * la página a esa modalidad), la línea de estados, la publicación con su línea de versiones
 * (academic-load::components.publish-load, que avisa con `load-published`), «Para cerrar», «Justificaciones» e
 * «Historial», sus acciones (ClosesLoads; ahí el validador sí corre, para esa carga) y «Abrir en Cierre de la carga».
 *
 * Lo ven quienes reabren cargas (`academic_load.load.reopen`: Subdirección y el admin), que también las cierran, sin
 * ser paso obligatorio (confirmado el 10 de noviembre de 2026). Cada método vuelve a autorizar.
 */
new #[Title('Avance del cierre')] class extends Component
{
    use ClosesLoads, Toast, WithModalityScope;

    /** Valores de `?estado=` y la cubeta que filtran (LoadProgress::bucket). */
    private const array STATES = ['faltantes' => 'missing', 'listas' => 'ready', 'cerradas' => 'closed', 'publicadas' => 'published'];

    #[Session]
    public string $search = '';

    /** '' (todas) o una llave de STATES; lo fijan el filtro «Estado» y las cifras de arriba. */
    #[Url(as: 'estado', except: '')]
    public string $state = '';

    public bool $showDrawer = false;

    public function mount(): void
    {
        abort_unless(app(Actor::class)->allowsAny(AcademicLoadPermission::LoadReopen), 403);

        $this->modalidad = $this->requiredModality()->code;
    }

    /**
     * Otra modalidad en el select: la matriz de esa modalidad, con el drawer cerrado.
     */
    public function updatedModalidad(): void
    {
        $this->modalidad = $this->requiredModality()->code;
        $this->closeDrawer();
    }

    /**
     * Abre el drawer con la carga de un departamento, entre las que el usuario reabre (`reopen`; nunca otra): la de la
     * modalidad pedida (por clave; sin ella, la de la página; M1) o, si no tiene, la primera que sigue sin cerrar (ni
     * cerrada, ni aprobada, ni publicada o en re-análisis) y, si todas pasaron el cierre, la primera. La página pasa a la
     * modalidad de la carga abierta. Sin cargas, 404; con cargas pero ninguna que reabra, 403.
     */
    public function open(string $departmentId, LoadClosingQuery $query, string $modalityCode = ''): void
    {
        abort_unless(app(Actor::class)->allowsAny(AcademicLoadPermission::LoadReopen), 403);

        $department = KeyLookup::findOrFail(Department::query()->withTrashed(), $departmentId);
        $term = app(Actor::class)->activeTerm() ?? abort(404);
        $loads = $query->loadsOf($term->getKey(), $department->getKey());
        abort_if($loads->isEmpty(), 404);

        $reopenable = $loads->filter(fn (AcademicLoad $candidate): bool => Gate::allows('reopen', $candidate))->values();
        $wanted = $modalityCode !== '' ? $modalityCode : (string) $this->modalidad;
        $load = $reopenable->first(fn (AcademicLoad $candidate): bool => $candidate->modality->code === $wanted)
            ?? $reopenable->first(fn (AcademicLoad $candidate): bool => ! in_array($candidate->status, [LoadStatus::Closed, LoadStatus::Approved, LoadStatus::Published, LoadStatus::Reanalysis], true))
            ?? $reopenable->first()
            ?? abort(403);

        $this->authorizeLoad($load);
        $this->modalidad = $this->modalityOptions()->contains('code', $load->modality->code) ? $load->modality->code : $this->modalidad;

        $this->showLoad($load, $query);
        $this->showDrawer = true;
    }

    /**
     * Cambia el drawer a la carga del mismo departamento en otra modalidad (y la página con ella).
     */
    public function selectModality(string $modalityCode, LoadClosingQuery $query): void
    {
        $current = $this->closingLoad($query);
        $this->open($current->department_id, $query, $modalityCode);
    }

    /**
     * Una cifra de arriba filtra la matriz por su cubeta; tocar la elegida quita el filtro.
     */
    public function filterBy(string $state): void
    {
        abort_unless(array_key_exists($state, self::STATES), 404);

        $this->state = $this->state === $state ? '' : $state;
    }

    public function closeDrawer(): void
    {
        $this->showDrawer = false;
        $this->showLoad(null, app(LoadClosingQuery::class));
    }

    /**
     * Esc o el velo cierran el drawer por wire:model: entonces también se olvidan la carga y sus modales.
     */
    public function updatedShowDrawer(bool $open): void
    {
        if (! $open) {
            $this->closeDrawer();
        }
    }

    /**
     * La publicación del drawer (academic-load::components.publish-load) publicó una versión: vuelve a leer la carga
     * para la línea de estados, el historial y la insignia, y ya no calcula lo que falta.
     */
    #[On('load-published')]
    public function published(string $loadId, int $version): void
    {
        if ($this->showDrawer && $this->loadId === $loadId) {
            $this->refreshLoad(app(LoadClosingQuery::class));
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function with(ClosingBoardQuery $board, LoadClosingQuery $query, Actor $context): array
    {
        abort_unless($context->allowsAny(AcademicLoadPermission::LoadReopen), 403);

        $term = $context->activeTerm();
        $closing = null;
        $tabs = collect();

        if ($this->showDrawer && $this->loadId !== null) {
            $load = $this->closingLoad($query);
            $closing = $query->of($load, $this->cachedBlockers());
            $tabs = $query->loadsOf($load->term_id, $load->department_id)->filter(fn (AcademicLoad $candidate): bool => Gate::allows('reopen', $candidate))->values();
        }

        $modality = $this->requiredModality();
        $data = $term === null ? null : $board->board($term, $this->search, self::STATES[$this->state] ?? '', $modality);

        return [
            'modalityOptions' => $this->modalityOptions(),
            'shownModality' => $modality,
            'term' => $term,
            'board' => $data,
            'heads' => $data === null ? [] : $board->heads($data['rows']),
            'states' => self::STATES,
            'closing' => $closing,
            'tabs' => $tabs,
            'actions' => $closing === null ? [] : $this->loadActions($closing),
            'canViewLoad' => $closing !== null && Gate::allows('view', $closing->load),
        ];
    }

    protected function authorizeLoad(AcademicLoad $load): void
    {
        Gate::authorize('reopen', $load);
    }
};
