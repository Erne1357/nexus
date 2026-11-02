<?php

use App\Modules\Academic\Models\Career;
use App\Modules\Academic\Models\Department;
use App\Modules\AcademicLoad\Actions\Proposals\StartCareerProposal;
use App\Modules\AcademicLoad\Actions\Proposals\SubmitCareerProposal;
use App\Modules\AcademicLoad\Actions\Proposals\UpdateProposalItem;
use App\Modules\AcademicLoad\Enums\ProposalReviewStatus;
use App\Modules\AcademicLoad\Enums\ProposalStatus;
use App\Modules\AcademicLoad\Models\CareerProposal;
use App\Modules\AcademicLoad\Models\ProposalItem;
use App\Modules\AcademicLoad\Models\ProposalReview;
use App\Modules\AcademicLoad\Queries\CareerProposalQuery;
use App\Modules\AcademicLoad\Queries\ProposalOverview;
use App\Modules\AcademicLoad\Rules\ProposalItemRules;
use App\Modules\AcademicLoad\Rules\SuggestedGroups;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Queries\ScopedContext;
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
 * tabla por semestre con captura en línea. En las dos, el resumen de cada departamento resalta sus materias
 * (`?depto=<código>`; en la tabla además puede filtrar, `?filtrar=1`).
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

    private const array VIEWS = ['reticula', 'tabla'];

    private const string LOCK_ACCEPTED = 'La propuesta ya se aceptó: ya no cambia.';

    private const string LOCK_SUBMITTED = 'La propuesta ya se envió: solo se corrige si un departamento la regresa.';

    /** 'reticula' o 'tabla'; otro valor muestra la retícula. */
    #[Url(as: 'vista')]
    public string $view = 'reticula';

    /** Código del departamento resaltado; '' ninguno. */
    #[Url(as: 'depto')]
    public string $department = '';

    /** En la tabla, ocultar las filas de los demás departamentos. */
    #[Url(as: 'filtrar')]
    public bool $onlyDepartment = false;

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

    /** La fila abierta en el panel. */
    #[Locked]
    public ?string $itemId = null;

    public bool $showDrawer = false;

    public bool $showSubmit = false;

    public ?int $expected_students = null;

    public ?int $requested_groups = null;

    public string $notes = '';

    /** Sube con cada rechazo: la tabla repinta sus campos con lo guardado, no con lo que se tecleó. */
    public int $rejections = 0;

    public function mount(ScopedContext $context, CareerProposalQuery $proposals): void
    {
        abort_unless($context->has(Role::Coordinador) || $context->has(Role::Subdireccion), 403);

        $this->view = in_array($this->view, self::VIEWS, true) ? $this->view : 'reticula';
        $this->resolve($context, $proposals);
    }

    /**
     * Cambiar de carrera arma su propuesta si falta y suelta el resaltado y el panel de la anterior.
     */
    public function updatedCareerId(ScopedContext $context, CareerProposalQuery $proposals): void
    {
        $this->reset('department', 'onlyDepartment', 'itemId', 'showDrawer', 'showSubmit');
        $this->resolve($context, $proposals);
    }

    public function setView(string $view): void
    {
        $this->view = in_array($view, self::VIEWS, true) ? $view : 'reticula';
    }

    /**
     * Elegir un departamento resalta sus materias; elegirlo otra vez lo quita.
     */
    public function pickDepartment(string $code): void
    {
        $this->department = $this->department === $code ? '' : $code;

        if ($this->department === '') {
            $this->onlyDepartment = false;
        }
    }

    /**
     * «Ver» en la alerta de un departamento que regresó su parte: lo resalta, sin quitarlo si ya lo estaba.
     */
    public function showDepartment(string $code): void
    {
        $this->department = $code;
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

        if (! preg_match('/^\d+$/', trim($value))) {
            $this->error('No se guardó', 'Escribe un número entero.');

            return;
        }

        $this->applyChange(
            $update,
            $item,
            $field === 'expected_students' ? (int) $value : $item->expected_students,
            $field === 'requested_groups' ? (int) $value : $item->requested_groups,
            $item->notes,
        );
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
            return ['proposal' => null, 'overview' => null];
        }

        $proposal = $this->authorizedProposal('view', $proposals);
        $overview = $proposals->overview($proposal, withPendingCatalogs: $this->showSubmit);
        $departments = $overview->departments();
        $gridSemesters = $overview->gridSemesters();

        $known = $this->department === '' || in_array($this->department, array_map(fn (array $row): string => $row['department']->code, $departments), true);

        if (! $known) {
            $this->reset('department', 'onlyDepartment');
        }

        return [
            'proposal' => $proposal,
            'overview' => $overview,
            'departments' => $departments,
            'department' => $this->department,
            'onlyDepartment' => $this->onlyDepartment,
            'locks' => $this->locks($overview),
            'generalLock' => $this->generalLock($overview),
            'returnedParts' => $this->returnedParts($overview, $departments),
            'parts' => $this->parts($overview),
            'submitReason' => $this->submitReason($overview),
            'suggested' => $this->suggested($overview),
            'canEdit' => Gate::allows('update', $proposal),
            'canSubmit' => Gate::allows('submit', $proposal),
            'gridSemesters' => $gridSemesters,
            'layout' => SemesterGrid::place(
                array_map(fn (array $items): array => collect($items)->mapWithKeys(fn (ProposalItem $item): array => [$item->subject->code => $overview->specialtyFor($item)])->all(), $gridSemesters),
                array_column($overview->specialties, 'id'),
            ),
            'specialtyNames' => array_column($overview->specialties, 'name', 'id'),
            'tableSemesters' => $this->tableSemesters($overview),
            'selected' => $this->itemId === null ? null : $this->findItem($overview, $this->itemId),
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
    private function resolve(ScopedContext $context, CareerProposalQuery $proposals): void
    {
        $this->proposalId = null;
        $this->careers = [];

        $campus = $context->activeCampus();

        if ($campus === null) {
            $this->message = 'Elige un campus en tu credencial para ver la propuesta de carrera.';

            return;
        }

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

    private function applyChange(UpdateProposalItem $update, ProposalItem $item, int $expected, int $groups, ?string $notes): bool
    {
        try {
            $update->handle($item, $expected, $groups, $notes, auth()->user());
        } catch (ValidationException $e) {
            $this->report($e);

            return false;
        }

        return true;
    }

    /**
     * Los errores de una Action: en su campo y, juntos, en un toast, porque la tabla no tiene dónde mostrarlos.
     */
    private function report(ValidationException $e): void
    {
        $this->rejections++;

        foreach ($e->errors() as $field => $messages) {
            $this->addError($field, $messages[0]);
        }

        $this->error('No se guardó', e(collect($e->errors())->flatten()->first()));
    }

    /**
     * El motivo por el que cada fila no se edita, por id; las editables no aparecen.
     *
     * @return array<string, string>
     */
    private function locks(ProposalOverview $overview): array
    {
        $locks = [];

        foreach ($overview->semesters as $items) {
            foreach ($items as $item) {
                if ($overview->isEditable($item)) {
                    continue;
                }

                $locks[$item->id] = match (true) {
                    $overview->proposal->status === ProposalStatus::Accepted => self::LOCK_ACCEPTED,
                    ! $overview->proposal->status->isEditable() => self::LOCK_SUBMITTED,
                    default => "{$item->subject->department->code} ya aceptó su parte: sus materias ya no cambian.",
                };
            }
        }

        return $locks;
    }

    /**
     * El motivo que vale para toda la propuesta y no para una fila: ya se aceptó o ya se envió. Null mientras se
     * pueda corregir (borrador o regresada): ahí solo se bloquean las partes aceptadas, cada una con su motivo.
     */
    private function generalLock(ProposalOverview $overview): ?string
    {
        return match (true) {
            $overview->proposal->status === ProposalStatus::Accepted => self::LOCK_ACCEPTED,
            ! $overview->proposal->status->isEditable() => self::LOCK_SUBMITTED,
            default => null,
        };
    }

    /**
     * Las partes que un departamento regresó, por departamento, con cuántas materias con grupos se le piden: lo
     * que dicen las alertas de arriba.
     *
     * @param  list<array{department: Department, subjects: int, groups: int, review: ?ProposalReview}>  $departments
     * @return list<array{department: Department, subjects: int, comment: ?string}>
     */
    private function returnedParts(ProposalOverview $overview, array $departments): array
    {
        return array_values(array_map(
            fn (array $row): array => ['department' => $row['department'], 'subjects' => $row['subjects'], 'comment' => $row['review']->comment],
            array_filter($departments, fn (array $row): bool => $row['review']?->status === ProposalReviewStatus::Returned),
        ));
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
     * Los grupos que sugiere la regla para los esperados que se teclean en el panel, para «Usar sugerido: N».
     */
    private function suggested(ProposalOverview $overview): ?int
    {
        $item = $this->itemId === null ? null : $this->findItem($overview, $this->itemId);

        return $item === null ? null : SuggestedGroups::fromSettings()->for(max(0, (int) $this->expected_students), (bool) $item->subject->needs_lab);
    }

    /**
     * Las filas de la tabla por semestre; con el filtro, solo las del departamento elegido.
     *
     * @return array<int, list<ProposalItem>>
     */
    private function tableSemesters(ProposalOverview $overview): array
    {
        if ($this->department === '' || ! $this->onlyDepartment) {
            return $overview->semesters;
        }

        return array_filter(array_map(
            fn (array $items): array => array_values(array_filter($items, fn (ProposalItem $item): bool => $item->subject->department->code === $this->department)),
            $overview->semesters,
        ));
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
