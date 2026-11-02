<?php

use App\Modules\Academic\Models\Career;
use App\Modules\AcademicLoad\Actions\Proposals\StartCareerProposal;
use App\Modules\AcademicLoad\Actions\Proposals\SubmitCareerProposal;
use App\Modules\AcademicLoad\Actions\Proposals\UpdateProposalItem;
use App\Modules\AcademicLoad\Enums\ProposalStatus;
use App\Modules\AcademicLoad\Models\CareerProposal;
use App\Modules\AcademicLoad\Models\ProposalItem;
use App\Modules\AcademicLoad\Queries\CareerProposalQuery;
use App\Modules\AcademicLoad\Queries\ProposalOverview;
use App\Modules\AcademicLoad\Rules\ProposalItemRules;
use App\Modules\AcademicLoad\Rules\SuggestedGroups;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Queries\ScopedContext;
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

    /** La fila abierta en el panel. */
    #[Locked]
    public ?string $itemId = null;

    public bool $showDrawer = false;

    public bool $showSubmit = false;

    public ?int $expected_students = null;

    public ?int $requested_groups = null;

    public string $notes = '';

    public function mount(ScopedContext $context, CareerProposalQuery $proposals): void
    {
        abort_unless($context->has(Role::Coordinador) || $context->has(Role::Subdireccion), 403);

        $this->view = in_array($this->view, self::VIEWS, true) ? $this->view : 'reticula';
        $this->startProposal($context, $proposals);
        $this->forgetUnknownDepartment($proposals);
    }

    /**
     * Cambiar de carrera arma su propuesta si falta y suelta el resaltado y el panel de la anterior.
     */
    public function updatedCareerId(ScopedContext $context, CareerProposalQuery $proposals): void
    {
        $this->reset('department', 'onlyDepartment', 'itemId', 'showDrawer', 'showSubmit');
        $this->startProposal($context, $proposals);
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
    public function with(ScopedContext $context, CareerProposalQuery $proposals): array
    {
        $situation = $this->situation($context, $proposals);
        $proposal = $situation['proposal'];

        $data = [
            'message' => $situation['message'],
            'term' => $situation['term'],
            'campus' => $situation['campus'],
            'career' => $situation['career'],
            'careers' => $situation['careers']->map(fn (Career $career): array => ['id' => $career->id, 'name' => "{$career->code} · {$career->name}"])->all(),
            'proposal' => $proposal,
            'overview' => null,
        ];

        if ($proposal === null) {
            return $data;
        }

        $overview = $proposals->overview($proposal);
        $locks = $this->locks($overview);
        $canEdit = Gate::allows('update', $proposal);

        return [
            ...$data,
            'overview' => $overview,
            'departments' => $overview->departments(),
            'locks' => $locks,
            'canEdit' => $canEdit,
            'canSubmit' => Gate::allows('submit', $proposal),
            'layout' => SemesterGrid::place(array_map(
                fn (array $items): array => array_fill_keys(array_map(fn (ProposalItem $item): string => $item->subject->code, $items), null),
                $overview->semesters,
            )),
            'tableSemesters' => $this->tableSemesters($overview),
            'selected' => $this->itemId === null ? null : $this->findItem($overview, $this->itemId),
            'suggested' => SuggestedGroups::fromSettings(),
            'maxExpected' => ProposalItemRules::MAX_EXPECTED_STUDENTS,
            'maxGroups' => ProposalItemRules::MAX_REQUESTED_GROUPS,
        ];
    }

    /**
     * El periodo, el campus y la carrera de la credencial, la propuesta si existe y, si no hay qué mostrar, por qué.
     * Una carrera pedida por la URL que el usuario no cubre es 403.
     *
     * @return array{term: ?Term, campus: ?Campus, careers: Collection<int, Career>, career: ?Career, proposal: ?CareerProposal, message: ?string}
     */
    private function situation(ScopedContext $context, CareerProposalQuery $proposals): array
    {
        $none = ['term' => null, 'campus' => null, 'careers' => new Collection, 'career' => null, 'proposal' => null];
        $campus = $context->activeCampus();

        if ($campus === null) {
            return [...$none, 'message' => 'Elige un campus en tu credencial para ver la propuesta de carrera.'];
        }

        $term = $context->activeTerm();

        if ($term === null) {
            return [...$none, 'campus' => $campus, 'message' => 'No hay un periodo activo: elige uno en tu credencial.'];
        }

        $none = [...$none, 'campus' => $campus, 'term' => $term];

        if (! $proposals->termIsOpen($term, $campus)) {
            return [...$none, 'message' => "El periodo {$term->code} no está abierto en este campus."];
        }

        $careers = $proposals->careersFor($context, $campus);
        $career = $this->careerId === '' ? $careers->first() : $careers->first(fn (Career $career): bool => $career->id === $this->careerId);

        abort_if($this->careerId !== '' && $career === null, 403);

        if ($career === null) {
            return [...$none, 'message' => 'Este campus no ofrece carreras cuya propuesta puedas ver.'];
        }

        $proposal = $proposals->findFor($term, $campus, $career);

        return [
            ...$none,
            'careers' => $careers,
            'career' => $career,
            'proposal' => $proposal,
            'message' => $proposal === null ? "{$career->code} todavía no arma su propuesta para el periodo {$term->code}." : null,
        ];
    }

    /**
     * Arma la propuesta de la carrera elegida si falta y quien entra puede armarla; guarda su id.
     */
    private function startProposal(ScopedContext $context, CareerProposalQuery $proposals): void
    {
        $situation = $this->situation($context, $proposals);
        $this->proposalId = $situation['proposal']?->id;

        if ($situation['proposal'] !== null || $situation['career'] === null) {
            return;
        }

        if (! Gate::allows('create', [CareerProposal::class, $situation['campus'], $situation['career']])) {
            return;
        }

        try {
            $this->proposalId = app(StartCareerProposal::class)->handle($situation['term'], $situation['campus'], $situation['career'], auth()->user())->id;
        } catch (ValidationException $e) {
            $this->report($e);
        }
    }

    private function forgetUnknownDepartment(CareerProposalQuery $proposals): void
    {
        if ($this->department === '' || $this->proposalId === null) {
            return;
        }

        $codes = array_map(fn (array $row): string => $row['department']->code, $proposals->overview($proposals->findOrFail($this->proposalId))->departments());

        if (! in_array($this->department, $codes, true)) {
            $this->department = '';
        }
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
                    $overview->proposal->status === ProposalStatus::Accepted => 'La propuesta ya se aceptó: ya no cambia.',
                    ! $overview->proposal->status->isEditable() => 'La propuesta ya se envió: solo se corrige si un departamento la regresa.',
                    default => "{$item->subject->department->code} ya aceptó su parte: sus materias ya no cambian.",
                };
            }
        }

        return $locks;
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
