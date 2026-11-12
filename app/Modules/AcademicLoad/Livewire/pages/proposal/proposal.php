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
use App\Modules\AcademicLoad\Queries\ProposalOverview;
use App\Modules\AcademicLoad\Rules\ProposalItemRules;
use App\Modules\AcademicLoad\Rules\SuggestedGroups;
use App\Modules\Core\Authorization\Actor;
use App\Modules\Core\Livewire\Concerns\WithCampusFilter;
use App\Modules\Core\Support\SemesterGrid;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Mary\Traits\Toast;

/**
 * Propuesta de carrera (tarea 2.7, reglas-de-negocio.md, "Propuesta de carrera"): lo que una carrera pide a
 * los departamentos en el periodo y el campus activos de la credencial, en dos vistas que el usuario alterna
 * (`?vista=reticula|tabla`): la retícula por semestre con un panel lateral para corregir una materia, y la
 * tabla por semestre con captura en línea. En las dos, el resumen de cada departamento elige sus materias
 * (`?depto=<código>`) y el filtro de grupos, las que piden o no piden grupos (`?grupos=con|sin`). En la retícula
 * las elegidas se marcan como una cadena de seriación resaltada, con la barra de selección (maqueta C, DESIGN.md,
 * "Retícula de la propuesta de carrera"); en la tabla el filtro oculta las demás filas y el departamento las
 * atenúa, o las oculta con `?filtrar=1`. Una cadena de la leyenda manda sobre esa marca mientras está resaltada.
 *
 * La carrera es la del coordinador en ese campus; con varias, o para subdirección y admin, `?carrera=<id>`
 * elige entre las ofrecidas en el campus. Lo ven el coordinador, subdirección y el admin; el rol sin alcance
 * recibe 403 y un coordinador no puede pedir por la URL una carrera que no cubre. Si la propuesta no existe y
 * el usuario puede armarla (CareerProposalPolicy::create), la arma al entrar (StartCareerProposal). Todo lo que
 * muestra sale de CareerProposalQuery::overview; las escrituras pasan por UpdateProposalItem y
 * SubmitCareerProposal, con la policy de la propuesta antes de cada una.
 */
new #[Title('Propuesta de carrera')] class extends Component
{
    use Toast;
    use WithCampusFilter;

    private const array VIEWS = ['reticula', 'tabla'];

    private const array GROUP_FILTERS = ['', 'con', 'sin'];

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
     * Las filas guardadas desde el último cambio del filtro de grupos, de la vista o del departamento: la tabla las
     * deja a la vista aunque ya no cumplan el filtro, para que no desaparezcan mientras se capturan (y el foco y el
     * aviso «Guardado» tengan dónde quedarse).
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
     * Las carreras que el usuario puede ver en el campus, para el selector, y por qué no hay propuesta que
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

    /** La fila abierta en el panel. */
    #[Locked]
    public ?string $itemId = null;

    /** El resumen que armó la acción de esta petición, para no armarlo otra vez al pintar. */
    private ?ProposalOverview $fresh = null;

    public bool $showDrawer = false;

    public bool $showSubmit = false;

    public ?int $expected_students = null;

    public ?int $requested_groups = null;

    public string $notes = '';

    /** Sube con cada rechazo: la tabla repinta sus campos con lo guardado, no con lo que se tecleó. */
    public int $rejections = 0;

    public function mount(Actor $context, CareerProposalQuery $proposals): void
    {
        abort_unless($context->allowsAny(AcademicLoadPermission::ProposalView), 403);

        $this->view = in_array($this->view, self::VIEWS, true) ? $this->view : 'reticula';
        $this->groupsFilter = in_array($this->groupsFilter, self::GROUP_FILTERS, true) ? $this->groupsFilter : '';
        $this->resolve($context, $proposals);
    }

    public function updatedCampus(): void
    {
        $this->reopenOnCampus('academic-load.proposal');
    }

    /**
     * Cambiar de carrera arma su propuesta si falta y suelta el resaltado y el panel de la anterior.
     */
    public function updatedCareerId(Actor $context, CareerProposalQuery $proposals): void
    {
        $this->reset('department', 'onlyDepartment', 'chain', 'editedRows', 'itemId', 'showDrawer', 'showSubmit', 'returnedAt', 'cellErrors');
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
     * piden grupos. Suelta la cadena resaltada.
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
        $this->fresh = $proposals->overview($this->authorizedProposal('view', $proposals));
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
     * Abre una materia en el panel lateral.
     */
    public function edit(string $id, CareerProposalQuery $proposals): void
    {
        $proposal = $this->authorizedProposal('view', $proposals);
        $item = $proposals->itemOrFail($proposal, $id);

        $this->itemId = $item->id;
        $this->expected_students = $item->expected_students;
        $this->requested_groups = $item->requested_groups;
        $this->notes = (string) $item->notes;
        $this->resetErrorBag();
        $this->showDrawer = true;
    }

    /**
     * «Usar sugerido»: los grupos que da la regla de la propuesta para los esperados escritos.
     */
    public function useSuggested(CareerProposalQuery $proposals): void
    {
        $proposal = $this->authorizedProposal('update', $proposals);
        $item = $proposals->itemOrFail($proposal, (string) $this->itemId)->load(['subject' => fn ($query) => $query->withTrashed()]);

        $this->requested_groups = SuggestedGroups::fromSettings()->for(max(0, (int) $this->expected_students), (bool) $item->subject->needs_lab);
    }

    /**
     * Guarda lo que se capturó en el panel.
     */
    public function save(CareerProposalQuery $proposals, UpdateProposalItem $update): void
    {
        $proposal = $this->authorizedProposal('update', $proposals);
        $item = $proposals->itemOrFail($proposal, (string) $this->itemId);

        $this->resetErrorBag();

        if ($this->expected_students === null || $this->requested_groups === null) {
            $this->addError($this->expected_students === null ? 'expected_students' : 'requested_groups', 'Escribe un número.');

            return;
        }

        if ($this->applyChange($update, $item, $this->expected_students, $this->requested_groups, $this->notes)) {
            $this->keepRow($item);
            $this->showDrawer = false;
            $this->success('Materia guardada', e($item->load(['subject' => fn ($query) => $query->withTrashed()])->subject->code));
        }
    }

    /**
     * Guarda una celda de la tabla al salir del campo: los esperados o los grupos, sin tocar lo demás.
     */
    public function saveCell(string $id, string $field, string $value, CareerProposalQuery $proposals, UpdateProposalItem $update): void
    {
        $proposal = $this->authorizedProposal('update', $proposals);
        $item = $proposals->itemOrFail($proposal, $id);

        if (! in_array($field, ['expected_students', 'requested_groups'], true)) {
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
            $this->keepRow($item);
            unset($this->cellErrors["{$item->id}.expected_students"], $this->cellErrors["{$item->id}.requested_groups"]);
            $this->dispatch('cell-saved', id: $item->id, field: $field);
        }
    }

    /**
     * Abre la confirmación de envío con los totales y el aviso de catálogos pendientes.
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
            return ['proposal' => null, 'overview' => null, ...$this->campusViewData()];
        }

        $proposal = $this->authorizedProposal('view', $proposals);
        $overview = ($this->showSubmit ? null : $this->fresh) ?? $proposals->overview($proposal, withPendingCatalogs: $this->showSubmit);
        $this->fresh = null;
        $walk = $this->walk($overview);
        $departments = $overview->departments();
        $gridSemesters = $overview->gridSemesters();
        $selected = $this->itemId === null ? null : $this->findItem($overview, $this->itemId);
        $rule = SuggestedGroups::fromSettings();

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
            ...$this->campusViewData(),
            'proposal' => $proposal,
            'overview' => $overview,
            'departments' => $departments,
            'department' => $this->department,
            'onlyDepartment' => $this->onlyDepartment,
            'locks' => $this->locks($overview),
            'generalLock' => $this->generalLock($overview),
            'returnedParts' => $this->returnedParts($departments, $walk['ids']),
            'parts' => $this->parts($overview),
            'majority' => $walk['majority'],
            'submitReason' => $this->submitReason($overview),
            'suggested' => $this->suggested($selected, $rule),
            'suggestedSize' => $selected === null ? null : $rule->sizeFor((bool) $selected->subject->needs_lab),
            'canEdit' => Gate::allows('update', $proposal),
            'canSubmit' => Gate::allows('submit', $proposal),
            'gridSemesters' => $gridSemesters,
            'chains' => $chains,
            'highlight' => $highlight,
            'figures' => $this->figures($gridSemesters, $highlight['marked']),
            'tileStates' => ProposalTileState::cases(),
            'layout' => SemesterGrid::place(
                array_map(fn (array $items): array => collect($items)->mapWithKeys(fn (ProposalItem $item): array => [$item->subject->code => $overview->specialtyFor($item)])->all(), $gridSemesters),
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
     * Resuelve con la credencial el periodo, el campus y la carrera, y deja en propiedades bloqueadas la
     * propuesta que se muestra (`proposalId`), las carreras del selector y, si no hay propuesta, por qué. Si falta
     * y quien entra puede armarla, la arma (StartCareerProposal). Una carrera pedida por la URL que el usuario
     * no cubre es 403. Después, la página pinta y escribe siempre en esa propuesta.
     */
    private function resolve(Actor $context, CareerProposalQuery $proposals): void
    {
        $this->proposalId = null;
        $this->careers = [];

        $campus = $this->requiredCampus();

        $term = $context->activeTerm();

        if ($term === null) {
            $this->message = 'No hay un periodo activo: elige uno en tu credencial.';

            return;
        }

        if (! $proposals->termIsOpen($term, $campus)) {
            $this->message = "El periodo {$term->code} no está abierto en este campus.";

            return;
        }

        $careers = $proposals->careersFor($context, $campus);
        $career = $this->careerId === '' ? $careers->first() : $careers->first(fn (Career $career): bool => $career->id === $this->careerId);

        abort_if($this->careerId !== '' && $career === null, 403);

        $this->careers = $careers->map(fn (Career $career): array => ['id' => $career->id, 'name' => "{$career->code} · {$career->name}"])->all();

        if ($career === null) {
            $this->message = 'Este campus no ofrece carreras cuya propuesta puedas ver.';

            return;
        }

        $proposal = $proposals->findFor($term, $campus, $career);

        if ($proposal === null && Gate::allows('create', [CareerProposal::class, $campus, $career])) {
            try {
                $proposal = app(StartCareerProposal::class)->handle($term, $campus, $career, auth()->user());
            } catch (ValidationException $e) {
                $this->report($e);
            }
        }

        $this->proposalId = $proposal?->id;
        $this->message = $proposal === null ? "{$career->code} todavía no arma su propuesta para el periodo {$term->code}." : null;
    }

    /**
     * La propuesta que se muestra, con la ability ya autorizada: una petición puede llegar de una página que
     * abrió otro usuario.
     */
    private function authorizedProposal(string $ability, CareerProposalQuery $proposals): CareerProposal
    {
        abort_if($this->proposalId === null, 403);

        $proposal = $proposals->findOrFail($this->proposalId);
        $this->authorize($ability, $proposal);

        return $proposal->loadMissing(['term', 'career' => fn ($query) => $query->withTrashed()]);
    }

    /**
     * Deja a la vista de la tabla la fila guardada aunque ya no cumpla el filtro (`editedRows`).
     */
    private function keepRow(ProposalItem $item): void
    {
        if (! in_array($item->id, $this->editedRows, true)) {
            $this->editedRows[] = $item->id;
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
     * Los errores de una Action: en su campo, en la celda de la tabla que se editó (`$cell`, o la del campo que
     * nombra el error en la fila `$itemId`) y, juntos, en un toast.
     */
    private function report(ValidationException $e, ?string $itemId = null, ?string $cell = null): void
    {
        $this->rejections++;

        foreach ($e->errors() as $field => $messages) {
            $this->addError($field, $messages[0]);
        }

        if ($cell !== null) {
            $field = array_key_first($e->errors());
            $own = in_array($field, ['expected_students', 'requested_groups'], true) ? "{$itemId}.{$field}" : $cell;
            $this->cellErrors[$own] = (string) collect($e->errors())->flatten()->first();
        }

        $this->error('No se guardó', e(collect($e->errors())->flatten()->first()));
    }

    /**
     * El tipo de bloqueo de cada fila que no se edita, por id; las editables no aparecen. La vista arma el texto
     * (ProposalLock::message).
     *
     * @return array<string, ProposalLock>
     */
    private function locks(ProposalOverview $overview): array
    {
        $locks = [];
        $general = $this->generalLock($overview);

        foreach ($overview->semesters as $items) {
            foreach ($items as $item) {
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
     * filas que «Ver» recorre: lo que dicen las alertas de arriba.
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
     * Una sola pasada por las filas, en el orden de la vista: por código de departamento, los ids de todas sus
     * filas y de las que piden grupos (lo que «Ver» recorre), y el departamento con más materias, cuyo código no
     * se repite en cada mosaico.
     *
     * @return array{ids: array<string, array{all: list<string>, requested: list<string>}>, majority: ?string}
     */
    private function walk(ProposalOverview $overview): array
    {
        $ids = [];
        $counts = [];

        foreach ($this->view === 'tabla' ? $overview->semesters : $overview->gridSemesters() as $items) {
            foreach ($items as $item) {
                $code = $item->subject->department->code;
                $ids[$code] ??= ['all' => [], 'requested' => []];
                $ids[$code]['all'][] = $item->id;
                $counts[$code] = ($counts[$code] ?? 0) + 1;

                if ($item->requested_groups > 0) {
                    $ids[$code]['requested'][] = $item->id;
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

        foreach ($overview->semesters as $items) {
            foreach ($items as $item) {
                $parts[$item->subject->department_id] ??= match ($overview->reviewFor($item->subject->department_id)?->status) {
                    ProposalReviewStatus::Returned => 'returned',
                    ProposalReviewStatus::Accepted => 'accepted',
                    default => 'pending',
                };
            }
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
     * Los grupos que sugiere la regla para los esperados del panel, al abrirlo y al guardar: el número de
     * «Usar sugerido: N». Mientras se teclea lo recalcula el navegador con `$size`, el tamaño objetivo de la materia.
     */
    private function suggested(?ProposalItem $selected, SuggestedGroups $rule): ?int
    {
        return $selected === null ? null : $rule->for(max(0, (int) $this->expected_students), (bool) $selected->subject->needs_lab);
    }

    /**
     * Las filas de la tabla por semestre: las del filtro de grupos y, con «Mostrar solo», del departamento elegido,
     * más las que se guardaron desde el último cambio de filtro (`editedRows`).
     *
     * @return array<int, list<ProposalItem>>
     */
    private function tableSemesters(ProposalOverview $overview): array
    {
        $department = $this->onlyDepartment ? $this->department : '';

        if ($department === '' && $this->groupsFilter === '') {
            return $overview->semesters;
        }

        $edited = array_flip($this->editedRows);

        return array_filter(array_map(
            fn (array $items): array => array_values(array_filter($items, fn (ProposalItem $item): bool => isset($edited[$item->id]) || $this->matches($item, $department))),
            $overview->semesters,
        ));
    }

    /**
     * Si la fila es del departamento (`$department`, '' cualquiera) y cumple el filtro de grupos.
     */
    private function matches(ProposalItem $item, string $department): bool
    {
        return ($department === '' || $item->subject->department->code === $department)
            && match ($this->groupsFilter) {
                'con' => $item->requested_groups > 0,
                'sin' => $item->requested_groups === 0,
                default => true,
            };
    }

    /**
     * Lo que se marca en la retícula y lo que dice la barra de selección: la cadena resaltada, si hay; si no, las
     * materias del departamento elegido y del filtro de grupos, lo que cumpla los dos si están los dos. `marked`
     * es null sin nada marcado (la barra no se ve).
     *
     * @param  array<int, list<ProposalItem>>  $gridSemesters
     * @param  array{of: array<string, int>, chains: list<array{index: int, name: string, size: int}>}  $chains
     * @param  list<array{department: Department, subjects: int, groups: int, review: ?ProposalReview}>  $departments
     * @return array{marked: array<string, true>|null, info: string}
     */
    private function highlight(array $gridSemesters, array $chains, array $departments): array
    {
        if ($this->view === 'tabla' || ($this->chain === null && $this->department === '' && $this->groupsFilter === '')) {
            return ['marked' => null, 'info' => ''];
        }

        $items = array_merge(...array_values($gridSemesters));
        $count = fn (int $subjects): string => $subjects.' '.($subjects === 1 ? 'materia' : 'materias');

        if ($this->chain !== null) {
            $chain = $chains['chains'][$this->chain];
            $members = array_filter($items, fn (ProposalItem $item): bool => ($chains['of'][$item->subject_id] ?? null) === $this->chain);

            return ['marked' => array_fill_keys(array_map(fn (ProposalItem $item): string => $item->id, $members), true), 'info' => "Cadena de {$chain['name']}: {$count(count($members))}."];
        }

        $members = array_values(array_filter($items, fn (ProposalItem $item): bool => $this->matches($item, $this->department)));
        $marked = array_fill_keys(array_map(fn (ProposalItem $item): string => $item->id, $members), true);
        $filter = match ($this->groupsFilter) {
            'con' => 'con grupos',
            'sin' => 'sin grupos',
            default => null,
        };

        if ($this->department === '') {
            return ['marked' => $marked, 'info' => ucfirst((string) $filter).": {$count(count($members))}."];
        }

        $name = collect($departments)->first(fn (array $row): bool => $row['department']->code === $this->department)['department']->name;
        $groups = array_sum(array_map(fn (ProposalItem $item): int => $item->requested_groups, $members));

        return ['marked' => $marked, 'info' => $filter === null
            ? "{$name} ({$this->department}): {$count(count($members))} · {$groups} ".($groups === 1 ? 'grupo' : 'grupos').'.'
            : "{$name} ({$this->department}), {$filter}: {$count(count($members))}."];
    }

    /**
     * Lo que dice el encabezado de cada semestre de la retícula, a la vista y para lectores de pantalla: sus
     * grupos; con el filtro de grupos, cuántas de sus materias lo cumplen («2 sin grupos»); con solo un
     * departamento, los grupos de ese departamento («18 de SC»). Con una cadena resaltada, sus grupos.
     *
     * @param  array<int, list<ProposalItem>>  $gridSemesters
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

        return array_map(function (array $items) use ($filter, $department, $marked): array {
            if ($filter !== null) {
                $subjects = count(array_filter($items, fn (ProposalItem $item): bool => isset($marked[$item->id])));

                return ['text' => "{$subjects} {$filter}", 'note' => 'materias'.($department === '' ? '' : " de {$department}")." {$filter}"];
            }

            $groups = array_sum(array_map(fn (ProposalItem $item): int => $department === '' || $item->subject->department->code === $department ? $item->requested_groups : 0, $items));

            return $department === ''
                ? ['text' => $groups.' '.($groups === 1 ? 'grupo' : 'grupos'), 'note' => null]
                : ['text' => "{$groups} de {$department}", 'note' => "grupos de {$department}"];
        }, $gridSemesters);
    }

    private function findItem(ProposalOverview $overview, string $id): ?ProposalItem
    {
        foreach ($overview->semesters as $items) {
            foreach ($items as $item) {
                if ($item->id === $id) {
                    return $item;
                }
            }
        }

        return null;
    }
};
