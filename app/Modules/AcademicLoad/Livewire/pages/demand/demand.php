<?php

use App\Modules\Academic\Models\Career;
use App\Modules\Academic\Models\Department;
use App\Modules\AcademicLoad\Enums\AcademicLoadPermission;
use App\Modules\AcademicLoad\Models\ProposalReview;
use App\Modules\AcademicLoad\Queries\CareerProposalQuery;
use App\Modules\AcademicLoad\Queries\DemandBoard;
use App\Modules\AcademicLoad\Queries\DemandBoardQuery;
use App\Modules\AcademicLoad\Queries\DemandBoardSubject;
use App\Modules\Core\Authorization\Actor;
use App\Modules\Core\Authorization\Target;
use App\Modules\Core\Livewire\Concerns\WithModalityCampusFilter;
use App\Modules\Core\Models\Modality;
use App\Modules\Core\Models\Term;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Demanda por materia (tarea 5.7; DESIGN.md, «Demanda por materia»: maqueta C «Materias × carreras», elegida por el
 * usuario el 17 de noviembre de 2026): lo que proyecta Demand contra lo que piden las propuestas de carrera, de todo el
 * Tec, en el periodo activo de la credencial y la modalidad de la página (`?modalidad=`, WithModalityCampusFilter). Un
 * renglón por materia y una columna por carrera, con cada celda «pedidos/sugeridos», y «Todo el Tec» con su reparto
 * por campus; el campus es un filtro (`?campus=`). Arriba, cuatro cifras de todo el Tec; la búsqueda (`?buscar=`) y el
 * filtro «Todas · Con diferencia · Sin propuesta» (`?filtro=`). Tocar una materia abre su drawer (`?materia=<clave>`),
 * con el desglose por campus y por carrera y su historial.
 *
 * Quién la ve (confirmado el 16 de noviembre de 2026; DemandBoardPolicy), solo de consulta:
 * - la lente del departamento: el jefe y el jefe de proyecto docente, la de su departamento; Subdirección y el admin, la de
 *   cualquiera, con el selector «Departamento» (`?departamento=<clave>`);
 * - la lente de la carrera: el coordinador, las materias de su carrera en todos los departamentos (`?carrera=<clave>`
 *   si coordina varias), con «Departamento» como filtro. Quien puede las dos (el admin) ve la del departamento salvo que
 *   pida una carrera.
 *
 * Un departamento o una carrera pedidos por la URL que el usuario no ve son 403. Todo lo que muestra sale de
 * DemandBoardQuery, con la policy en cada petición.
 */
new #[Title('Demanda por materia')] class extends Component
{
    use WithModalityCampusFilter;

    /** Valores de `?filtro=` y el filtro de DemandBoard::visible() que aplican. */
    private const array FILTERS = ['diferencia' => 'difference', 'sin-propuesta' => 'pending'];

    /** Clave del departamento: el de la lente o, en la lente de carrera, el filtro de sus materias; '' es el primero o ninguno. */
    #[Url(as: 'departamento', except: '')]
    public string $departmentCode = '';

    /** Clave de la carrera de la lente de carrera; '' es la primera que puede ver. */
    #[Url(as: 'carrera', except: '')]
    public string $careerCode = '';

    #[Url(as: 'buscar', except: '')]
    public string $search = '';

    /** '' (todas), 'diferencia' o 'sin-propuesta'. */
    #[Url(as: 'filtro', except: '')]
    public string $filter = '';

    /** Clave de la materia del drawer. */
    #[Url(as: 'materia', except: '')]
    public string $subjectCode = '';

    public bool $showDrawer = false;

    /** El periodo, la modalidad y la lente con que entró: el navegador no los puede cambiar. */
    #[Locked]
    public ?string $termId = null;

    #[Locked]
    public ?string $modalityId = null;

    #[Locked]
    public ?string $departmentId = null;

    #[Locked]
    public ?string $careerId = null;

    /**
     * Las opciones de los selectores «Departamento» (lente del departamento) y «Carrera» (lente de carrera).
     *
     * @var list<array{code: string, name: string}>
     */
    #[Locked]
    public array $departments = [];

    /** @var list<array{code: string, name: string}> */
    #[Locked]
    public array $careers = [];

    /** Por qué no hay tablero que mostrar. */
    #[Locked]
    public ?string $message = null;

    public function mount(Actor $context, DemandBoardQuery $query, CareerProposalQuery $proposals): void
    {
        $this->authorize('viewAny', DemandBoard::class);

        $this->updatedFilter();
        $this->resolve($context, $query, $proposals);
        $this->showDrawer = $this->subjectCode !== '' && $this->termId !== null;
    }

    /**
     * Otra modalidad: la página se abre de nuevo en ella, con la misma lente y el mismo campus si la otra lo tiene.
     */
    public function updatedModalidad(): void
    {
        $this->redirectRoute('academic-load.demand', array_filter([
            'modalidad' => $this->requiredModality()->code,
            'departamento' => $this->departmentCode,
            'carrera' => $this->careerCode,
            'campus' => $this->campusFilter()?->code,
        ]), navigate: true);
    }

    /**
     * Otro departamento: en la lente del departamento cambia la lente (y suelta el drawer); en la de carrera solo filtra.
     */
    public function updatedDepartmentCode(Actor $context, DemandBoardQuery $query, CareerProposalQuery $proposals): void
    {
        if ($this->careerId === null) {
            $this->closeDrawer();
            $this->resolve($context, $query, $proposals);
        }
    }

    public function updatedCareerCode(Actor $context, DemandBoardQuery $query, CareerProposalQuery $proposals): void
    {
        $this->closeDrawer();
        $this->departmentCode = '';
        $this->resolve($context, $query, $proposals);
    }

    public function updatedFilter(): void
    {
        $this->filter = array_key_exists($this->filter, self::FILTERS) ? $this->filter : '';
    }

    /**
     * Abre el drawer de una materia del tablero, con la policy de verlo. Si la clave no es del tablero, no se abre.
     */
    public function open(string $code, DemandBoardQuery $query): void
    {
        $this->authorizeBoard($query);

        $this->subjectCode = $code;
        $this->showDrawer = true;
    }

    public function closeDrawer(): void
    {
        $this->showDrawer = false;
        $this->subjectCode = '';
    }

    /**
     * Esc o el velo cierran el drawer por wire:model: entonces también se olvida la materia.
     */
    public function updatedShowDrawer(bool $open): void
    {
        if (! $open) {
            $this->closeDrawer();
        }
    }

    /**
     * Quita la búsqueda y el filtro (no el campus ni el departamento).
     */
    public function clearFilters(): void
    {
        $this->search = '';
        $this->filter = '';
    }

    /**
     * @return array<string, mixed>
     */
    public function with(DemandBoardQuery $query, Actor $context): array
    {
        $scope = $this->scopeViewData();

        if ($this->termId === null) {
            return [...$scope, 'board' => null];
        }

        $board = $this->board($query);
        $filterDepartment = $board->isCareerLens() ? collect($board->departments())->first(fn (Department $department): bool => $department->code === $this->departmentCode) : null;

        if ($board->isCareerLens() && $filterDepartment === null) {
            // Un departamento que no tiene materias en la lente (de otra modalidad, o que ya no está) no filtra: se suelta.
            $this->departmentCode = '';
        }

        $shown = $board->withinDepartment($filterDepartment);
        $campusId = $scope['shownCampus']?->getKey();
        $visible = $shown->visible($this->search, self::FILTERS[$this->filter] ?? '', $campusId);
        $figures = $shown->figures();
        $drawer = $this->showDrawer ? $board->subject($this->subjectCode) : null;

        if ($this->showDrawer && $drawer === null) {
            $this->closeDrawer();
        }

        return [
            ...$scope,
            'board' => $shown,
            'figures' => $figures,
            'visible' => $visible,
            'footer' => $shown->footer($visible, $campusId),
            'filterCounts' => $shown->filterCounts($campusId),
            'campusId' => $campusId,
            'filterDepartments' => $board->isCareerLens() ? $board->departments() : [],
            'filterDepartment' => $filterDepartment,
            'drawer' => $drawer,
            'history' => $drawer === null ? null : $query->history($board, $drawer),
            'links' => $drawer === null ? [] : $this->links($board, $drawer, $context),
        ];
    }

    /**
     * Resuelve con la credencial el periodo, con la página la modalidad y la lente (departamento o carrera), y deja en
     * propiedades bloqueadas con qué pinta la página (o por qué no hay tablero). Un departamento o una carrera pedidos por
     * la URL que el usuario no ve son 403.
     */
    private function resolve(Actor $context, DemandBoardQuery $query, CareerProposalQuery $proposals): void
    {
        $this->termId = $this->modalityId = $this->departmentId = $this->careerId = null;
        $this->departments = $this->careers = [];

        $modality = $this->requiredModality();
        $this->modalidad = $modality->code;
        $term = $context->activeTerm();

        if ($term === null) {
            $this->message = 'No hay un periodo activo: elige uno en tu credencial.';

            return;
        }

        if (! $proposals->termIsOpen($term, $modality)) {
            $this->message = "El periodo {$term->name} no está abierto en {$modality->name}.";

            return;
        }

        $careerLens = ($this->careerCode !== '' && $context->allowsAny(AcademicLoadPermission::DemandViewCareer))
            || ! $context->allowsAny(AcademicLoadPermission::DemandViewDepartment);

        if ($careerLens) {
            $careers = $proposals->careersFor($context, $modality, AcademicLoadPermission::DemandViewCareer);
            $career = $this->careerCode === '' ? $careers->first() : $careers->first(fn (Career $career): bool => $career->code === $this->careerCode);

            abort_if($this->careerCode !== '' && $career === null, 403);

            $this->careers = $careers->map(fn (Career $career): array => ['code' => $career->code, 'name' => "{$career->code} · {$career->name}"])->all();

            if ($career === null) {
                $this->message = "No tienes una carrera de {$modality->name} cuya demanda puedas ver.";

                return;
            }

            $this->careerCode = $career->code;
            $this->careerId = $career->getKey();
        } else {
            $departments = $query->departmentsFor($context, $modality);
            $department = $this->departmentCode === '' ? $departments->first() : $departments->first(fn (Department $department): bool => $department->code === $this->departmentCode);

            abort_if($this->departmentCode !== '' && $department === null, 403);

            $this->departments = $departments->map(fn (Department $department): array => ['code' => $department->code, 'name' => "{$department->code} · {$department->name}"])->all();

            if ($department === null) {
                $this->message = "No tienes un departamento cuya demanda puedas ver en {$modality->name}.";

                return;
            }

            $this->careerCode = '';
            $this->departmentCode = $department->code;
            $this->departmentId = $department->getKey();
        }

        $this->termId = $term->getKey();
        $this->modalityId = $modality->getKey();
        $this->message = null;
    }

    /**
     * El tablero con que entró la página, con la policy de verlo: una petición puede llegar de una página que abrió otro
     * usuario.
     */
    private function board(DemandBoardQuery $query): DemandBoard
    {
        [$term, $modality, $department, $career] = $this->authorizeBoard($query);

        return $career !== null
            ? $query->forCareer($term, $modality, $career)
            : $query->forDepartment($term, $modality, $department);
    }

    /**
     * @return array{0: Term, 1: Modality, 2: ?Department, 3: ?Career}
     */
    private function authorizeBoard(DemandBoardQuery $query): array
    {
        abort_if($this->termId === null || $this->modalityId === null || ($this->departmentId === null && $this->careerId === null), 403);

        $scope = $query->scopeOf($this->termId, $this->modalityId, $this->departmentId, $this->careerId);
        [, $modality, $department, $career] = $scope;

        $career !== null
            ? $this->authorize('viewCareer', [DemandBoard::class, $modality, $career])
            : $this->authorize('viewDepartment', [DemandBoard::class, $modality, $department]);

        return $scope;
    }

    /**
     * Los enlaces del pie del drawer: la bandeja del departamento de la materia, si el usuario la ve, y en la lente de
     * carrera la propuesta de la carrera, si la ve.
     *
     * @return array{inbox: ?string, proposal: ?string}
     */
    private function links(DemandBoard $board, DemandBoardSubject $subject, Actor $context): array
    {
        $department = $subject->subject->department;
        $modality = $board->modality;

        return [
            'inbox' => $department !== null && Gate::allows('viewAny', [ProposalReview::class, $modality, $department])
                ? route('academic-load.inbox', ['modalidad' => $modality->code, 'depto' => $department->code])
                : null,
            'proposal' => $board->career !== null && $context->allows(AcademicLoadPermission::ProposalView, Target::of(modality: $modality->getKey(), career: $board->career->getKey()))
                ? route('academic-load.proposal', ['modalidad' => $modality->code, 'carrera' => $board->career->getKey()])
                : null,
        ];
    }
};
