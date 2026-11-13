<?php

use App\Modules\Academic\Models\Career;
use App\Modules\Academic\Models\Department;
use App\Modules\AcademicLoad\Actions\Proposals\StartCareerProposal;
use App\Modules\AcademicLoad\Actions\Proposals\SubmitCareerProposal;
use App\Modules\AcademicLoad\Actions\Proposals\UpdateProposalItem;
use App\Modules\AcademicLoad\Enums\AcademicLoadPermission;
use App\Modules\AcademicLoad\Enums\ProposalLock;
use App\Modules\AcademicLoad\Enums\ProposalReviewStatus;
use App\Modules\AcademicLoad\Enums\ProposalStatus;
use App\Modules\AcademicLoad\Enums\ProposalTileState;
use App\Modules\AcademicLoad\Models\CareerProposal;
use App\Modules\AcademicLoad\Models\ProposalItem;
use App\Modules\AcademicLoad\Models\ProposalReview;
use App\Modules\AcademicLoad\Queries\CareerProposalQuery;
use App\Modules\AcademicLoad\Queries\ProposalHeader;
use App\Modules\AcademicLoad\Queries\ProposalOverview;
use App\Modules\AcademicLoad\Queries\ProposalSubject;
use App\Modules\AcademicLoad\Rules\ProposalItemRules;
use App\Modules\AcademicLoad\Rules\SuggestedGroups;
use App\Modules\Core\Authorization\Actor;
use App\Modules\Core\Livewire\Concerns\WithModalityScope;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Modality;
use App\Modules\Core\Support\SemesterGrid;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Mary\Traits\Toast;

/**
 * Propuesta de carrera (tarea 2.7, reglas-de-negocio.md, "Propuesta de carrera"): lo que una carrera pide a los
 * departamentos en el periodo activo de la credencial y la modalidad de la pantalla (`?modalidad=`, WithModalityScope).
 * Es una por carrera y modalidad, con una columna por campus donde se ofrece la carrera (M6, MS5; maqueta U1, opción A
 * «Columnas por campus», DESIGN.md, "Propuesta de carrera por modalidad"), en dos vistas que el usuario alterna
 * (`?vista=reticula|tabla`): la retícula por semestre con un panel lateral para corregir una materia en sus campus, y
 * la tabla con las columnas de cada campus lado a lado y captura en línea. En las dos, el resumen de cada departamento
 * elige sus materias (`?depto=<código>`) y el filtro de grupos, las que piden o no piden grupos en algún campus
 * (`?grupos=con|sin`). En la retícula las elegidas se marcan como una cadena de seriación resaltada, con la barra de
 * selección; en la tabla el filtro oculta las demás filas y el departamento las atenúa, o las oculta con `?filtrar=1`.
 *
 * La carrera es la del coordinador; con varias, o para subdirección y admin, `?carrera=<id>` elige entre las ofrecidas.
 * Lo ven el coordinador de la carrera en la modalidad (con o sin campus: ve toda la propuesta, M7), subdirección y el
 * admin; el rol sin alcance recibe 403 y un coordinador no puede pedir por la URL una carrera que no cubre. Si la
 * propuesta no existe y el usuario puede armarla (CareerProposalPolicy::create), la arma al entrar
 * (StartCareerProposal). Corregir es por fila (CareerProposalPolicy::update sobre la fila, M7): un coordinador con
 * campus solo cambia su columna, y la de otro campus se ve de solo lectura con quién la captura; uno sin campus cambia
 * todas. En la columna de un campus inactivo, quien envía la propuesta solo deja en cero los grupos de cada fila
 * («Dejar en 0», `zeroRow`; CareerProposalPolicy::zeroInactiveCampus, M14). Enviar es de cualquier coordinador de la
 * carrera (M6). Todo lo que muestra sale de
 * CareerProposalQuery::overview; las escrituras pasan por UpdateProposalItem y SubmitCareerProposal, con la policy de
 * la propuesta o de la fila antes de cada una.
 */
new #[Title('Propuesta de carrera')] class extends Component
{
    use Toast;
    use WithModalityScope;

    private const array VIEWS = ['reticula', 'tabla'];

    private const array GROUP_FILTERS = ['', 'con', 'sin'];

    private const array CELL_FIELDS = ['expected_students', 'requested_groups'];

    /** 'reticula' o 'tabla'; otro valor muestra la retícula. */
    #[Url(as: 'vista')]
    public string $view = 'reticula';

    /** Código del departamento resaltado; '' ninguno. */
    #[Url(as: 'depto')]
    public string $department = '';

    /** En la tabla, ocultar las filas de los demás departamentos. */
    #[Url(as: 'filtrar')]
    public bool $onlyDepartment = false;

    /** Qué materias se eligen por sus grupos: '' todas, 'con' las que piden grupos y 'sin' las que no. */
    #[Url(as: 'grupos')]
    public string $groupsFilter = '';

    /**
     * La cadena de seriación resaltada en la retícula (su índice en ProposalOverview::chains), o null. Mientras
     * está, manda sobre la marca del departamento y del filtro de grupos, que siguen elegidos.
     */
    #[Locked]
    public ?int $chain = null;

    /**
     * Las materias (por id) guardadas desde el último cambio del filtro de grupos, de la vista o del departamento: la
     * tabla las deja a la vista aunque ya no cumplan el filtro, para que no desaparezcan mientras se capturan (y el
     * foco y el aviso «Guardado» tengan dónde quedarse).
     *
     * @var list<string>
     */
    #[Locked]
    public array $editedRows = [];

    /** Id de la carrera elegida; '' es la primera que puede ver. */
    #[Url(as: 'carrera')]
    public string $careerId = '';

    /** La propuesta que se muestra. El navegador no la puede cambiar. */
    #[Locked]
    public ?string $proposalId = null;

    /**
     * Las carreras que el usuario puede ver en la modalidad, para el selector, y por qué no hay propuesta que
     * mostrar. Se resuelven al entrar y al cambiar de carrera, no en cada pintado.
     *
     * @var list<array{id: string, name: string}>
     */
    #[Locked]
    public array $careers = [];

    #[Locked]
    public ?string $message = null;

    /**
     * Qué materia regresada va en «Ver» (índice desde 0) del departamento resaltado; null si todavía no se empieza.
     * Sube con cada «Ver» y da la vuelta.
     */
    #[Locked]
    public ?int $returnedAt = null;

    /**
     * El error de cada celda de la tabla que no se pudo guardar, por «<id de la fila>.<campo>».
     *
     * @var array<string, string>
     */
    #[Locked]
    public array $cellErrors = [];

    /** La materia abierta en el panel (su id). */
    #[Locked]
    public ?string $subjectId = null;

    /**
     * Lo que se captura en el panel, por id de fila: solo las filas que el usuario puede corregir (su campus y su
     * estado). save() vuelve a autorizar cada una, porque el navegador puede mandar otras.
     *
     * @var array<string, array{expected_students: int|string|null, requested_groups: int|string|null, notes: ?string}>
     */
    public array $form = [];

    /** El resumen que armó la acción de esta petición, para no armarlo otra vez al pintar. */
    private ?ProposalOverview $fresh = null;

    public bool $showDrawer = false;

    public bool $showSubmit = false;

    /** Sube con cada rechazo: la tabla repinta sus campos con lo guardado, no con lo que se tecleó. */
    public int $rejections = 0;

    public function mount(Actor $context, CareerProposalQuery $proposals): void
    {
        abort_unless($context->allowsAny(AcademicLoadPermission::ProposalView), 403);

        $this->view = in_array($this->view, self::VIEWS, true) ? $this->view : 'reticula';
        $this->groupsFilter = in_array($this->groupsFilter, self::GROUP_FILTERS, true) ? $this->groupsFilter : '';
        $this->resolve($context, $proposals);
    }

    /**
     * Otra modalidad: la página se abre de nuevo en ella.
     */
    public function updatedModalidad(): void
    {
        $this->redirectRoute('academic-load.proposal', ['modalidad' => $this->requiredModality()->code], navigate: true);
    }

    /**
     * Cambiar de carrera arma su propuesta si falta y suelta el resaltado y el panel de la anterior.
     */
    public function updatedCareerId(Actor $context, CareerProposalQuery $proposals): void
    {
        $this->reset('department', 'onlyDepartment', 'chain', 'editedRows', 'subjectId', 'form', 'showDrawer', 'showSubmit', 'returnedAt', 'cellErrors');
        $this->resolve($context, $proposals);
    }

    public function setView(string $view): void
    {
        $this->returnedAt = null;
        $this->chain = null;
        $this->editedRows = [];
        $this->view = in_array($view, self::VIEWS, true) ? $view : 'reticula';
        $this->cellErrors = [];
    }

    /**
     * «Mostrar solo» cambia qué filas deja la tabla: las guardadas dejan de quedarse a la vista.
     */
    public function updatedOnlyDepartment(): void
    {
        $this->editedRows = [];
    }

    /**
     * Elegir un departamento marca sus materias; elegirlo otra vez lo quita. Suelta la cadena resaltada.
     */
    public function pickDepartment(string $code): void
    {
        $this->department = $this->department === $code ? '' : $code;
        $this->returnedAt = null;
        $this->chain = null;
        $this->editedRows = [];

        if ($this->department === '') {
            $this->onlyDepartment = false;
        }
    }

    /**
     * «Todas», «Con grupos» o «Sin grupos»: marca en la retícula, o deja en la tabla, las materias que piden o no
     * piden grupos en algún campus. Suelta la cadena resaltada.
     */
    public function filterGroups(string $filter): void
    {
        $this->groupsFilter = in_array($filter, self::GROUP_FILTERS, true) ? $filter : '';
        $this->chain = null;
        $this->editedRows = [];
    }

    /**
     * Resalta una cadena de la leyenda de la retícula, por encima de la marca del departamento y del filtro;
     * tocarla otra vez la quita.
     */
    public function focusChain(int $chain): void
    {
        $this->chain = $this->chain === $chain ? null : $chain;
    }

    /**
     * «Quitar resaltado» (o Esc): con una cadena resaltada, la quita y vuelve a la marca del departamento y del
     * filtro; si no, quita esa marca.
     */
    public function clearHighlight(): void
    {
        if ($this->chain !== null) {
            $this->chain = null;

            return;
        }

        $this->reset('department', 'onlyDepartment', 'groupsFilter', 'returnedAt', 'editedRows');
    }

    /**
     * «Ver» en la alerta de un departamento que regresó su parte: lo marca (en la tabla, además, deja solo sus
     * filas) sin la cadena ni el filtro de grupos, que podrían esconder lo que se le pide, y lleva la vista a su
     * primera materia, con el foco. Con varias, cada «Ver» va a la siguiente y da la vuelta (`proposal-focus`, que
     * atiende la página).
     */
    public function showDepartment(string $code, CareerProposalQuery $proposals): void
    {
        $this->fresh = $proposals->overview($this->authorizedProposal('view', $proposals), withPendingCatalogs: false);
        $ids = $this->walk($this->fresh)['ids'][$code] ?? null;

        if ($ids === null) {
            return;
        }

        $ids = $ids['requested'] !== [] ? $ids['requested'] : $ids['all'];
        $next = $this->returnedAt === null || $this->department !== $code ? 0 : ($this->returnedAt + 1) % count($ids);

        $this->department = $code;
        $this->returnedAt = $next;
        $this->chain = null;
        $this->groupsFilter = '';
        $this->editedRows = [];

        if ($this->view === 'tabla') {
            $this->onlyDepartment = true;
        }

        $this->dispatch('proposal-focus', id: $ids[$next]);
    }

    /**
     * Abre una materia en el panel lateral con sus filas de cada campus. Lo que se captura (`form`) son solo las filas
     * que el usuario puede corregir: las de su campus (M7) que su estado deja cambiar.
     */
    public function edit(string $id, CareerProposalQuery $proposals): void
    {
        $proposal = $this->authorizedProposal('view', $proposals);
        $items = $proposals->subjectItemsOrFail($proposal, $id);
        $this->fresh = $proposals->overview($proposal, withPendingCatalogs: false);

        $this->subjectId = $items->first()->subject_id;
        $this->form = $items
            ->filter(fn (ProposalItem $item): bool => $this->fresh->isEditable($item) && Gate::allows('update', [$proposal, $item]))
            ->mapWithKeys(fn (ProposalItem $item): array => [$item->id => [
                'expected_students' => $item->expected_students,
                'requested_groups' => $item->requested_groups,
                'notes' => (string) $item->notes,
            ]])
            ->all();
        $this->resetErrorBag();
        $this->showDrawer = true;
    }

    /**
     * «Usar sugerido» de una fila del panel: los grupos que da la regla de la propuesta para los esperados escritos en
     * esa fila. Solo en una fila que el usuario puede corregir (M7).
     */
    public function useSuggested(string $id, CareerProposalQuery $proposals): void
    {
        $proposal = $this->authorizedProposal('view', $proposals);
        $item = $proposals->itemOrFail($proposal, $id)->load(['subject' => fn ($query) => $query->withTrashed()]);
        $this->authorize('update', [$proposal, $item]);

        $expected = max(0, (int) ($this->form[$item->id]['expected_students'] ?? $item->expected_students));
        $this->form[$item->id] = [
            'expected_students' => $this->form[$item->id]['expected_students'] ?? $item->expected_students,
            'requested_groups' => SuggestedGroups::fromSettings()->for($expected, (bool) $item->subject->needs_lab),
            'notes' => (string) ($this->form[$item->id]['notes'] ?? $item->notes),
        ];
    }

    /**
     * Guarda lo que se capturó en el panel, fila por fila. Antes de escribir nada autoriza cada fila (una de otro campus
     * es 403, M7) y revisa que cada número esté escrito y en su rango; si una fila no se guarda, el panel se queda
     * abierto con su error.
     */
    public function save(CareerProposalQuery $proposals, UpdateProposalItem $update, ProposalItemRules $rules): void
    {
        $proposal = $this->authorizedProposal('view', $proposals);
        $items = [];

        foreach (array_keys($this->form) as $id) {
            $item = $proposals->itemOrFail($proposal, (string) $id);
            $this->authorize('update', [$proposal, $item]);
            $items[$item->id] = $item;
        }

        $this->resetErrorBag();
        $values = [];

        foreach ($items as $id => $item) {
            $row = is_array($this->form[$id]) ? $this->form[$id] : [];

            foreach (self::CELL_FIELDS as $field) {
                if (! is_numeric($row[$field] ?? null) || (int) $row[$field] != $row[$field]) {
                    $this->addError("form.{$id}.{$field}", 'Escribe un número.');
                }
            }

            if (isset($row['notes']) && ! is_string($row['notes'])) {
                $this->addError("form.{$id}.notes", 'La nota debe ser texto.');
            }

            if ($this->getErrorBag()->isNotEmpty()) {
                continue;
            }

            try {
                $values[$id] = $rules->normalize((int) $row['expected_students'], (int) $row['requested_groups'], $row['notes'] ?? null);
            } catch (ValidationException $e) {
                foreach ($e->errors() as $field => $messages) {
                    $this->addError("form.{$id}.{$field}", $messages[0]);
                }
            }
        }

        if ($this->getErrorBag()->isNotEmpty()) {
            return;
        }

        foreach ($items as $id => $item) {
            if (! $this->applyChange($update, $item, $values[$id]['expected_students'], $values[$id]['requested_groups'], $values[$id]['notes'])) {
                return;
            }
        }

        if ($items !== []) {
            $this->keepRow((string) $this->subjectId);
            $first = reset($items);
            $this->success('Materia guardada', e($first->load(['subject' => fn ($query) => $query->withTrashed()])->subject->code));
        }

        $this->showDrawer = false;
    }

    /**
     * Guarda una celda de la tabla al salir del campo: los esperados o los grupos de una fila, sin tocar lo demás. La
     * fila de otro campus es 403 (M7).
     */
    public function saveCell(string $id, string $field, string $value, CareerProposalQuery $proposals, UpdateProposalItem $update): void
    {
        $proposal = $this->authorizedProposal('view', $proposals);
        $item = $proposals->itemOrFail($proposal, $id);
        $this->authorize('update', [$proposal, $item]);

        if (! in_array($field, self::CELL_FIELDS, true)) {
            return;
        }

        $cell = "{$item->id}.{$field}";
        unset($this->cellErrors[$cell]);

        if (! preg_match('/^\d+$/', trim($value))) {
            $this->cellErrors[$cell] = 'Escribe un número entero.';
            $this->error('No se guardó', 'Escribe un número entero.');

            return;
        }

        $saved = $this->applyChange(
            $update,
            $item,
            $field === 'expected_students' ? (int) $value : $item->expected_students,
            $field === 'requested_groups' ? (int) $value : $item->requested_groups,
            $item->notes,
            $cell,
        );

        if ($saved) {
            $this->keepRow($item->subject_id);
            unset($this->cellErrors["{$item->id}.expected_students"], $this->cellErrors["{$item->id}.requested_groups"]);
            $this->dispatch('cell-saved', id: $item->id, field: $field);
        }
    }

    /**
     * «Dejar en 0» en una fila de un campus inactivo (M14): solo los grupos pedidos, sin tocar lo demás, con
     * CareerProposalPolicy::zeroInactiveCampus. Cualquier otro cambio de esa fila sigue negado por saveCell().
     */
    public function zeroRow(string $id, CareerProposalQuery $proposals, UpdateProposalItem $update): void
    {
        $proposal = $this->authorizedProposal('view', $proposals);
        $item = $proposals->itemOrFail($proposal, $id);
        $this->authorize('zeroInactiveCampus', [$proposal, $item]);
        $cell = "{$item->id}.requested_groups";
        unset($this->cellErrors[$cell]);

        try {
            $update->zeroRequestedGroups($item, auth()->user());
        } catch (ValidationException $e) {
            $this->report($e, $item->id, $cell);

            return;
        }

        $this->keepRow($item->subject_id);
        $this->dispatch('cell-saved', id: $item->id, field: 'requested_groups');
        $this->success('Grupos en 0', e("{$item->subject->code} en {$item->campus->code}: ya no se piden grupos ahí."));
    }

    /**
     * Abre la confirmación de envío con los totales por campus, a quién se avisa y el aviso de catálogos pendientes.
     */
    public function confirmSubmit(CareerProposalQuery $proposals): void
    {
        $this->authorizedProposal('submit', $proposals);
        $this->showSubmit = true;
    }

    public function submit(CareerProposalQuery $proposals, SubmitCareerProposal $submit): void
    {
        $proposal = $this->authorizedProposal('submit', $proposals);

        try {
            $sent = $submit->handle($proposal, auth()->user());
        } catch (ValidationException $e) {
            $this->showSubmit = false;
            $this->report($e);

            return;
        }

        $this->showSubmit = false;
        $this->success($sent->status === ProposalStatus::Accepted ? 'Propuesta aceptada' : 'Propuesta enviada', e("{$proposal->career->code} · {$proposal->term->code}"));
    }

    /**
     * @return array<string, mixed>
     */
    public function with(CareerProposalQuery $proposals): array
    {
        if ($this->proposalId === null) {
            return ['proposal' => null, 'overview' => null, ...$this->modalityViewData()];
        }

        $proposal = $this->authorizedProposal('view', $proposals);
        $overview = ($this->showSubmit ? null : $this->fresh) ?? $proposals->overview($proposal, withPendingCatalogs: $this->showSubmit);
        $this->fresh = null;
        $walk = $this->walk($overview);
        $departments = $overview->departments();
        $gridSemesters = $overview->gridSemesters();
        $selected = $this->subjectId === null ? null : $overview->subject($this->subjectId);
        $rule = SuggestedGroups::fromSettings();
        $header = $overview->header((string) config('app.timezone'));
        $viewerId = auth()->id() === null ? null : (string) auth()->id();
        $columns = $this->columns($overview, $header);

        $known = $this->department === '' || in_array($this->department, array_map(fn (array $row): string => $row['department']->code, $departments), true);

        if (! $known) {
            $this->reset('department', 'onlyDepartment');
        }

        if (! in_array($this->groupsFilter, self::GROUP_FILTERS, true)) {
            $this->groupsFilter = '';
        }

        $own = $this->department === '' ? null : ($walk['ids'][$this->department] ?? null);

        if ($this->returnedAt !== null && ($own === null || $this->returnedAt >= count($own['requested'] ?: $own['all']))) {
            $this->returnedAt = null;
        }

        $chains = $overview->chains();

        if ($this->chain !== null && ($this->view === 'tabla' || ! isset($chains['chains'][$this->chain]))) {
            $this->chain = null;
        }

        $highlight = $this->highlight($gridSemesters, $chains, $departments);

        return [
            ...$this->modalityViewData(),
            'proposal' => $proposal,
            'overview' => $overview,
            'columns' => $columns,
            'coordinators' => $header->coordinatorLine($viewerId),
            'offer' => $header->offer(),
            'scopeNote' => $header->scopeNote(array_keys(array_filter($columns, fn (array $column): bool => $column['editable']))),
            'sent' => $header->sentLine(),
            'departments' => $departments,
            'department' => $this->department,
            'onlyDepartment' => $this->onlyDepartment,
            'locks' => $this->locks($overview),
            'generalLock' => $this->generalLock($overview),
            'returnedParts' => $this->returnedParts($departments, $walk['ids']),
            'parts' => $this->parts($overview),
            'majority' => $walk['majority'],
            'submitReason' => $this->submitReason($overview),
            'suggestedSize' => $selected === null ? null : $rule->sizeFor((bool) $selected->subject->needs_lab),
            'suggested' => $this->suggested($selected, $rule),
            'canSubmit' => Gate::allows('submit', $proposal),
            'submitNotice' => $this->showSubmit ? $header->submitNotice($viewerId) : null,
            'gridSemesters' => $gridSemesters,
            'chains' => $chains,
            'highlight' => $highlight,
            'figures' => $this->figures($gridSemesters, $highlight['marked']),
            'tileStates' => ProposalTileState::cases(),
            'layout' => SemesterGrid::place(
                array_map(fn (array $subjects): array => collect($subjects)->mapWithKeys(fn (ProposalSubject $subject): array => [$subject->subject->code => $overview->specialtyFor($subject)])->all(), $gridSemesters),
                array_column($overview->specialties, 'id'),
            ),
            'specialtyNames' => array_column($overview->specialties, 'name', 'id'),
            'tableSemesters' => $this->tableSemesters($overview),
            'selected' => $selected,
            'maxExpected' => ProposalItemRules::MAX_EXPECTED_STUDENTS,
            'maxGroups' => ProposalItemRules::MAX_REQUESTED_GROUPS,
        ];
    }

    /**
     * Las modalidades del selector y la que muestra la página, para `<x-core::modality-scope>`.
     *
     * @return array{modalityOptions: Collection<int, Modality>, modalityCode: string}
     */
    private function modalityViewData(): array
    {
        return ['modalityOptions' => $this->modalityOptions(), 'modalityCode' => $this->requiredModality()->code];
    }

    /**
     * Resuelve con la credencial el periodo, con la página la modalidad y la carrera, y deja en propiedades bloqueadas
     * la propuesta que se muestra (`proposalId`), las carreras del selector y, si no hay propuesta, por qué. Si falta
     * y quien entra puede armarla, la arma (StartCareerProposal). Una carrera pedida por la URL que el usuario no
     * cubre es 403. Después, la página pinta y escribe siempre en esa propuesta.
     */
    private function resolve(Actor $context, CareerProposalQuery $proposals): void
    {
        $this->proposalId = null;
        $this->careers = [];

        $modality = $this->requiredModality();
        $term = $context->activeTerm();

        if ($term === null) {
            $this->message = 'No hay un periodo activo: elige uno en tu credencial.';

            return;
        }

        if (! $proposals->termIsOpen($term, $modality)) {
            $this->message = "El periodo {$term->code} no está abierto en {$modality->name}.";

            return;
        }

        $careers = $proposals->careersFor($context, $modality);
        $career = $this->careerId === '' ? $careers->first() : $careers->first(fn (Career $career): bool => $career->id === $this->careerId);

        abort_if($this->careerId !== '' && $career === null, 403);

        $this->careers = $careers->map(fn (Career $career): array => ['id' => $career->id, 'name' => "{$career->code} · {$career->name}"])->all();

        if ($career === null) {
            $this->message = "No hay carreras de {$modality->name} cuya propuesta puedas ver.";

            return;
        }

        $proposal = $proposals->findFor($term, $modality, $career);

        if ($proposal === null && Gate::allows('create', [CareerProposal::class, $modality, $career])) {
            try {
                $proposal = app(StartCareerProposal::class)->handle($term, $modality, $career, auth()->user());
            } catch (ValidationException $e) {
                $this->report($e);
            }
        }

        $this->proposalId = $proposal?->id;
        $this->message = $proposal === null ? "{$career->code} todavía no arma su propuesta para el periodo {$term->code}." : null;
    }

    /**
     * La propuesta que se muestra, con la ability ya autorizada: una petición puede llegar de una página que
     * abrió otro usuario. Corregir no se autoriza aquí sino en cada fila (CareerProposalPolicy::update, M7).
     */
    private function authorizedProposal(string $ability, CareerProposalQuery $proposals): CareerProposal
    {
        abort_if($this->proposalId === null, 403);

        $proposal = $proposals->findOrFail($this->proposalId);
        $this->authorize($ability, $proposal);

        return $proposal->loadMissing(['term', 'career' => fn ($query) => $query->withTrashed()]);
    }

    /**
     * Deja a la vista de la tabla la materia guardada aunque ya no cumpla el filtro (`editedRows`).
     */
    private function keepRow(string $subjectId): void
    {
        if (! in_array($subjectId, $this->editedRows, true)) {
            $this->editedRows[] = $subjectId;
        }
    }

    private function applyChange(UpdateProposalItem $update, ProposalItem $item, int $expected, int $groups, ?string $notes, ?string $cell = null): bool
    {
        try {
            $update->handle($item, $expected, $groups, $notes, auth()->user());
        } catch (ValidationException $e) {
            $this->report($e, $item->id, $cell);

            return false;
        }

        return true;
    }

    /**
     * Los errores de una Action: en el panel, en el campo de su fila (`form.<fila>.<campo>`) o en el suyo; en la celda
     * de la tabla que se editó (`$cell`, o la del campo que nombra el error en la fila `$itemId`) y, juntos, en un toast.
     */
    private function report(ValidationException $e, ?string $itemId = null, ?string $cell = null): void
    {
        $this->rejections++;

        foreach ($e->errors() as $field => $messages) {
            $this->addError($itemId !== null && $cell === null && in_array($field, [...self::CELL_FIELDS, 'notes'], true) ? "form.{$itemId}.{$field}" : $field, $messages[0]);
        }

        if ($cell !== null) {
            $field = array_key_first($e->errors());
            $own = in_array($field, self::CELL_FIELDS, true) ? "{$itemId}.{$field}" : $cell;
            $this->cellErrors[$own] = (string) collect($e->errors())->flatten()->first();
        }

        $this->error('No se guardó', e(collect($e->errors())->flatten()->first()));
    }

    /**
     * Cada columna de la propuesta, por su llave (ProposalOverview::columnKey), con su campus, si el usuario la puede
     * corregir y, si no, quién la captura (ProposalHeader::capturedBy). Se decide con la policy de la fila
     * (CareerProposalPolicy::update) sobre una fila de esa columna: un coordinador con campus solo corrige la suya (M7).
     * La de un campus inactivo no la corrige nadie, pero quien envía la propuesta deja en cero sus grupos (`zeroable`,
     * M14), y eso dice en lugar de quién la captura. `zeroAlert`: el aviso de la columna, solo mientras la propuesta se
     * edita y alguna fila de ella todavía pide grupos.
     *
     * @return array<string, array{campus: ?Campus, editable: bool, zeroable: bool, zeroAlert: bool, readonly: ?string}>
     */
    private function columns(ProposalOverview $overview, ProposalHeader $header): array
    {
        $samples = [];
        $subjects = $overview->subjects();

        foreach ($subjects as $subject) {
            $samples += $subject->cells;
        }

        $requested = $overview->groupsByColumn($subjects);
        $open = $overview->proposal->status->isEditable();

        $columns = [];

        foreach ($overview->columns as $campus) {
            $key = ProposalOverview::columnKey($campus);
            $editable = isset($samples[$key]) && Gate::allows('update', [$overview->proposal, $samples[$key]]);
            $zeroable = ! $editable && isset($samples[$key]) && Gate::allows('zeroInactiveCampus', [$overview->proposal, $samples[$key]]);

            $columns[$key] = [
                'campus' => $campus,
                'editable' => $editable,
                'zeroable' => $zeroable,
                'zeroAlert' => $zeroable && $open && ($requested[$key] ?? 0) > 0,
                'readonly' => match (true) {
                    $editable => null,
                    $zeroable => "{$campus->code} está inactivo: solo se pueden dejar sus grupos en 0.",
                    default => $header->capturedBy($key),
                },
            ];
        }

        return $columns;
    }

    /**
     * El tipo de bloqueo de cada fila que no se edita por su estado, por id; las editables no aparecen. La vista arma
     * el texto (ProposalLock::message). La columna de otro campus no es un bloqueo de la fila: la dice `columns`.
     *
     * @return array<string, ProposalLock>
     */
    private function locks(ProposalOverview $overview): array
    {
        $locks = [];
        $general = $this->generalLock($overview);

        foreach ($overview->subjects() as $subject) {
            foreach ($subject->items() as $item) {
                if (! $overview->isEditable($item)) {
                    $locks[$item->id] = $general ?? ProposalLock::Department;
                }
            }
        }

        return $locks;
    }

    /**
     * El bloqueo que vale para toda la propuesta y no para una fila: ya se aceptó o ya se envió. Null mientras se
     * pueda corregir (borrador o regresada): ahí solo se bloquean las partes aceptadas, cada una con su motivo.
     */
    private function generalLock(ProposalOverview $overview): ?ProposalLock
    {
        return match (true) {
            $overview->proposal->status === ProposalStatus::Accepted => ProposalLock::Accepted,
            ! $overview->proposal->status->isEditable() => ProposalLock::Submitted,
            default => null,
        };
    }

    /**
     * Las partes que un departamento regresó, por departamento, con cuántas materias con grupos se le piden y las
     * materias que «Ver» recorre: lo que dicen las alertas de arriba.
     *
     * @param  list<array{department: Department, subjects: int, groups: int, review: ?ProposalReview}>  $departments
     * @param  array<string, array{all: list<string>, requested: list<string>}>  $ids
     * @return list<array{department: Department, subjects: int, comment: ?string, items: list<string>}>
     */
    private function returnedParts(array $departments, array $ids): array
    {
        return array_values(array_map(
            fn (array $row): array => [
                'department' => $row['department'],
                'subjects' => $row['subjects'],
                'comment' => $row['review']->comment,
                'items' => ($ids[$row['department']->code]['requested'] ?? []) ?: ($ids[$row['department']->code]['all'] ?? []),
            ],
            array_filter($departments, fn (array $row): bool => $row['review']?->status === ProposalReviewStatus::Returned),
        ));
    }

    /**
     * Una sola pasada por las materias, en el orden de la vista: por código de departamento, los ids de todas sus
     * materias y de las que piden grupos en algún campus (lo que «Ver» recorre), y el departamento con más materias,
     * cuyo código no se repite en cada mosaico.
     *
     * @return array{ids: array<string, array{all: list<string>, requested: list<string>}>, majority: ?string}
     */
    private function walk(ProposalOverview $overview): array
    {
        $ids = [];
        $counts = [];

        foreach ($this->view === 'tabla' ? $overview->semesters : $overview->gridSemesters() as $subjects) {
            foreach ($subjects as $subject) {
                $code = $subject->subject->department->code;
                $ids[$code] ??= ['all' => [], 'requested' => []];
                $ids[$code]['all'][] = $subject->id();
                $counts[$code] = ($counts[$code] ?? 0) + 1;

                if ($subject->groups() > 0) {
                    $ids[$code]['requested'][] = $subject->id();
                }
            }
        }

        arsort($counts);

        return ['ids' => $ids, 'majority' => array_key_first($counts)];
    }

    /**
     * El estado de la parte de cada departamento, por id: `returned`, `accepted` o `pending` (también antes del
     * envío, cuando todavía no hay parte). Con él se pinta cada mosaico y cada fila.
     *
     * @return array<string, string>
     */
    private function parts(ProposalOverview $overview): array
    {
        $parts = [];

        foreach ($overview->subjects() as $subject) {
            $parts[$subject->subject->department_id] ??= match ($overview->reviewFor($subject->subject->department_id)?->status) {
                ProposalReviewStatus::Returned => 'returned',
                ProposalReviewStatus::Accepted => 'accepted',
                default => 'pending',
            };
        }

        return $parts;
    }

    /**
     * Por qué no se puede enviar, a la vista junto al botón; null si se puede.
     */
    private function submitReason(ProposalOverview $overview): ?string
    {
        return match ($overview->proposal->status) {
            ProposalStatus::Submitted => 'Ya se envió',
            ProposalStatus::Accepted => 'Ya se aceptó',
            default => null,
        };
    }

    /**
     * Los grupos que sugiere la regla para los esperados de cada fila del panel que se captura, por id de fila, al
     * abrirlo y al guardar: el número de «Usar sugerido: N». Mientras se teclea lo recalcula el navegador con
     * `$suggestedSize`, el tamaño objetivo de la materia.
     *
     * @return array<string, int>
     */
    private function suggested(?ProposalSubject $selected, SuggestedGroups $rule): array
    {
        if ($selected === null) {
            return [];
        }

        return array_map(
            fn (array $row): int => $rule->for(max(0, (int) ($row['expected_students'] ?? 0)), (bool) $selected->subject->needs_lab),
            $this->form,
        );
    }

    /**
     * Las materias de la tabla por semestre: las del filtro de grupos y, con «Mostrar solo», del departamento elegido,
     * más las que se guardaron desde el último cambio de filtro (`editedRows`).
     *
     * @return array<int, list<ProposalSubject>>
     */
    private function tableSemesters(ProposalOverview $overview): array
    {
        $department = $this->onlyDepartment ? $this->department : '';

        if ($department === '' && $this->groupsFilter === '') {
            return $overview->semesters;
        }

        $edited = array_flip($this->editedRows);

        return array_filter(array_map(
            fn (array $subjects): array => array_values(array_filter($subjects, fn (ProposalSubject $subject): bool => isset($edited[$subject->id()]) || $this->matches($subject, $department))),
            $overview->semesters,
        ));
    }

    /**
     * Si la materia es del departamento (`$department`, '' cualquiera) y cumple el filtro de grupos, con los grupos de
     * todos sus campus.
     */
    private function matches(ProposalSubject $subject, string $department): bool
    {
        return ($department === '' || $subject->subject->department->code === $department)
            && match ($this->groupsFilter) {
                'con' => $subject->groups() > 0,
                'sin' => $subject->groups() === 0,
                default => true,
            };
    }

    /**
     * Lo que se marca en la retícula y lo que dice la barra de selección: la cadena resaltada, si hay; si no, las
     * materias del departamento elegido y del filtro de grupos, lo que cumpla los dos si están los dos. `marked`
     * es null sin nada marcado (la barra no se ve); va por id de materia.
     *
     * @param  array<int, list<ProposalSubject>>  $gridSemesters
     * @param  array{of: array<string, int>, chains: list<array{index: int, name: string, size: int}>}  $chains
     * @param  list<array{department: Department, subjects: int, groups: int, review: ?ProposalReview}>  $departments
     * @return array{marked: array<string, true>|null, info: string}
     */
    private function highlight(array $gridSemesters, array $chains, array $departments): array
    {
        if ($this->view === 'tabla' || ($this->chain === null && $this->department === '' && $this->groupsFilter === '')) {
            return ['marked' => null, 'info' => ''];
        }

        $subjects = array_merge([], ...array_values($gridSemesters));
        $count = fn (int $total): string => $total.' '.($total === 1 ? 'materia' : 'materias');

        if ($this->chain !== null) {
            $chain = $chains['chains'][$this->chain];
            $members = array_filter($subjects, fn (ProposalSubject $subject): bool => ($chains['of'][$subject->id()] ?? null) === $this->chain);

            return ['marked' => array_fill_keys(array_map(fn (ProposalSubject $subject): string => $subject->id(), $members), true), 'info' => "Cadena de {$chain['name']}: {$count(count($members))}."];
        }

        $members = array_values(array_filter($subjects, fn (ProposalSubject $subject): bool => $this->matches($subject, $this->department)));
        $marked = array_fill_keys(array_map(fn (ProposalSubject $subject): string => $subject->id(), $members), true);
        $filter = match ($this->groupsFilter) {
            'con' => 'con grupos',
            'sin' => 'sin grupos',
            default => null,
        };

        if ($this->department === '') {
            return ['marked' => $marked, 'info' => ucfirst((string) $filter).": {$count(count($members))}."];
        }

        $name = collect($departments)->first(fn (array $row): bool => $row['department']->code === $this->department)['department']->name;
        $groups = array_sum(array_map(fn (ProposalSubject $subject): int => $subject->groups(), $members));

        return ['marked' => $marked, 'info' => $filter === null
            ? "{$name} ({$this->department}): {$count(count($members))} · {$groups} ".($groups === 1 ? 'grupo' : 'grupos').'.'
            : "{$name} ({$this->department}), {$filter}: {$count(count($members))}."];
    }

    /**
     * Lo que dice el encabezado de cada semestre de la retícula, a la vista y para lectores de pantalla: sus
     * grupos en todos los campus; con el filtro de grupos, cuántas de sus materias lo cumplen («2 sin grupos»); con
     * solo un departamento, los grupos de ese departamento («18 de SC»). Con una cadena resaltada, sus grupos.
     *
     * @param  array<int, list<ProposalSubject>>  $gridSemesters
     * @param  array<string, true>|null  $marked
     * @return array<int, array{text: string, note: ?string}>
     */
    private function figures(array $gridSemesters, ?array $marked): array
    {
        $filter = $this->chain !== null || $marked === null ? null : match ($this->groupsFilter) {
            'con' => 'con grupos',
            'sin' => 'sin grupos',
            default => null,
        };
        $department = $this->chain !== null || $marked === null ? '' : $this->department;

        return array_map(function (array $subjects) use ($filter, $department, $marked): array {
            if ($filter !== null) {
                $total = count(array_filter($subjects, fn (ProposalSubject $subject): bool => isset($marked[$subject->id()])));

                return ['text' => "{$total} {$filter}", 'note' => 'materias'.($department === '' ? '' : " de {$department}")." {$filter}"];
            }

            $groups = array_sum(array_map(fn (ProposalSubject $subject): int => $department === '' || $subject->subject->department->code === $department ? $subject->groups() : 0, $subjects));

            return $department === ''
                ? ['text' => $groups.' '.($groups === 1 ? 'grupo' : 'grupos'), 'note' => null]
                : ['text' => "{$groups} de {$department}", 'note' => "grupos de {$department}"];
        }, $gridSemesters);
    }
};
