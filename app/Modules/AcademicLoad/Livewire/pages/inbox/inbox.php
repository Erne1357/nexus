<?php

use App\Modules\Academic\Models\Department;
use App\Modules\AcademicLoad\Actions\Proposals\AcceptProposalReview;
use App\Modules\AcademicLoad\Actions\Proposals\ReturnProposalReview;
use App\Modules\AcademicLoad\Enums\InboxStatus;
use App\Modules\AcademicLoad\Models\ProposalReview;
use App\Modules\AcademicLoad\Queries\CareerProposalQuery;
use App\Modules\AcademicLoad\Queries\DepartmentInbox;
use App\Modules\AcademicLoad\Queries\DepartmentInboxQuery;
use App\Modules\AcademicLoad\Rules\ParallelGroups;
use App\Modules\AcademicLoad\Rules\ProposalReviewRules;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Queries\ScopedContext;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Mary\Traits\Toast;

/**
 * Bandeja del departamento (tarea 2.8, reglas-de-negocio.md, "Propuesta de carrera"): lo que piden las carreras
 * de un campus a un departamento en el periodo y el campus activos de la credencial. A la izquierda las carreras
 * con el estado de su parte; a la derecha lo que pide la elegida, materia por materia contra el total de la
 * materia, con la sugerencia de paralelos y los botones Aceptar y Regresar (maqueta C).
 *
 * El departamento es el del jefe; con varios, o para subdirección y admin, `?depto=<código>` lo elige. `?carrera=<id>`
 * abre una carrera: son los parámetros con los que llega la notificación de una propuesta enviada. Ven la bandeja
 * quienes ven la carga (ProposalReviewPolicy::viewAny) y deciden el jefe del departamento y el admin
 * (ProposalReviewPolicy::decide), con la policy antes de cada método. Todo lo que muestra sale de
 * DepartmentInboxQuery; las escrituras, de AcceptProposalReview y ReturnProposalReview.
 */
new #[Title('Bandeja del departamento')] class extends Component
{
    use Toast;

    private const array FILTERS = ['todas', 'pendientes'];

    /** Código del departamento; '' es el primero que puede ver. */
    #[Url(as: 'depto')]
    public string $department = '';

    /** Id de la carrera elegida; '' es la primera pendiente. */
    #[Url(as: 'carrera')]
    public string $careerId = '';

    /** 'todas' o 'pendientes'. */
    public string $filter = 'todas';

    /** El periodo, el campus y el departamento con los que entró. El navegador no los puede cambiar. */
    #[Locked]
    public ?string $termId = null;

    #[Locked]
    public ?string $campusId = null;

    #[Locked]
    public ?string $departmentId = null;

    /**
     * Los departamentos del selector y, si no hay bandeja que mostrar, por qué.
     *
     * @var list<array{code: string, name: string}>
     */
    #[Locked]
    public array $departments = [];

    #[Locked]
    public ?string $message = null;

    /** En pantallas angostas, la lista y el detalle van por separado. */
    #[Locked]
    public bool $mobileDetail = false;

    /** La parte que se acepta o se regresa. */
    #[Locked]
    public ?string $reviewId = null;

    public bool $showAccept = false;

    public bool $showReturn = false;

    public string $comment = '';

    public function mount(ScopedContext $context, DepartmentInboxQuery $inbox, CareerProposalQuery $proposals): void
    {
        abort_unless($context->has(Role::JefeDepartamento) || $context->has(Role::JefeProyectoDocente) || $context->has(Role::Subdireccion), 403);

        $this->mobileDetail = $this->careerId !== '';
        $this->resolve($context, $inbox, $proposals);
    }

    /**
     * Cambiar de departamento suelta la carrera y la parte de la anterior.
     */
    public function updatedDepartment(ScopedContext $context, DepartmentInboxQuery $inbox, CareerProposalQuery $proposals): void
    {
        $this->reset('careerId', 'reviewId', 'showAccept', 'showReturn', 'comment', 'mobileDetail');
        $this->resolve($context, $inbox, $proposals);
    }

    public function updatedFilter(): void
    {
        $this->filter = in_array($this->filter, self::FILTERS, true) ? $this->filter : 'todas';
    }

    /**
     * Elige una carrera de la bandeja; en pantallas angostas abre su detalle.
     */
    public function pick(string $id, DepartmentInboxQuery $query): void
    {
        $career = $this->inbox($query)->career($id);

        abort_if($career === null, 404);

        $this->careerId = $career['career']->getKey();
        $this->mobileDetail = true;
    }

    public function back(): void
    {
        $this->mobileDetail = false;
    }

    /**
     * Abre la confirmación de aceptar una parte, con sus totales.
     */
    public function confirmAccept(string $id, DepartmentInboxQuery $query): void
    {
        $review = $this->decidable($id, $query);

        $this->reviewId = $review->getKey();
        $this->resetErrorBag();
        $this->showReturn = false;
        $this->showAccept = true;
    }

    public function accept(DepartmentInboxQuery $query, AcceptProposalReview $accept): void
    {
        $review = $this->decidable((string) $this->reviewId, $query);

        try {
            $accept->handle($review, auth()->user());
        } catch (ValidationException $e) {
            $this->showAccept = false;
            $this->report($e, 'No se aceptó');

            return;
        }

        $this->showAccept = false;
        $this->success('Parte aceptada', e($this->label($review)));
    }

    /**
     * Abre el diálogo para regresar una parte con su comentario.
     */
    public function confirmReturn(string $id, DepartmentInboxQuery $query): void
    {
        $review = $this->decidable($id, $query);

        $this->reviewId = $review->getKey();
        $this->comment = '';
        $this->resetErrorBag();
        $this->showAccept = false;
        $this->showReturn = true;
    }

    public function returnPart(DepartmentInboxQuery $query, ReturnProposalReview $return): void
    {
        $review = $this->decidable((string) $this->reviewId, $query);

        $this->validate(
            ['comment' => ['required', 'string', 'max:'.ProposalReviewRules::MAX_COMMENT_LENGTH]],
            ['comment.required' => 'Escribe por qué la regresas.', 'comment.max' => 'El comentario lleva hasta '.ProposalReviewRules::MAX_COMMENT_LENGTH.' caracteres.'],
        );

        try {
            $return->handle($review, $this->comment, auth()->user());
        } catch (ValidationException $e) {
            $this->report($e, 'No se regresó');

            return;
        }

        $this->showReturn = false;
        $this->comment = '';
        $this->warning('Parte regresada', e($this->label($review)));
    }

    /**
     * @return array<string, mixed>
     */
    public function with(DepartmentInboxQuery $query): array
    {
        if ($this->departmentId === null) {
            return ['inbox' => null];
        }

        $inbox = $this->inbox($query);
        $rows = $inbox->careers;
        $selected = $this->selected($inbox);
        $canDecide = $selected !== null && $selected['decidable'] && Gate::allows('decide', $selected['review']);
        $submitted = array_values(array_filter($rows, fn (array $row): bool => $row['status'] !== InboxStatus::NotSubmitted));
        $target = $this->reviewId === null ? null : $this->targetOf($rows, $this->reviewId);
        $catalogsUrl = Route::has('catalog-validation.review') ? route('catalog-validation.review', ['department' => $inbox->department->getKey()]) : null;

        return [
            'inbox' => $inbox,
            'rows' => $rows,
            'visible' => $this->filter === 'pendientes'
                ? array_values(array_filter($rows, fn (array $row): bool => $row['decidable']))
                : $rows,
            'pendingCount' => count(array_filter($rows, fn (array $row): bool => $row['decidable'])),
            'acceptedCount' => count(array_filter($rows, fn (array $row): bool => $row['status'] === InboxStatus::Accepted)),
            'submittedCount' => count($submitted),
            'selected' => $selected,
            'items' => $selected === null ? [] : $inbox->rowsFor($selected['career']->getKey()),
            'canDecide' => $canDecide,
            'acceptBlock' => $inbox->canAccept() ? null : $this->acceptBlock($inbox),
            'catalogsUrl' => $catalogsUrl,
            'suggestions' => $this->suggestions($inbox),
            'target' => $target,
        ];
    }

    /**
     * Resuelve con la credencial el periodo, el campus y el departamento, y deja en propiedades bloqueadas con qué
     * pinta y escribe la página (o por qué no hay bandeja). Un departamento pedido por la URL que el usuario no
     * ve es 403.
     */
    private function resolve(ScopedContext $context, DepartmentInboxQuery $inbox, CareerProposalQuery $proposals): void
    {
        $this->termId = $this->campusId = $this->departmentId = null;
        $this->departments = [];

        $campus = $context->activeCampus();

        if ($campus === null) {
            $this->message = 'Elige un campus en tu credencial para ver la bandeja del departamento.';

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

        $departments = $inbox->departmentsFor($context, $campus, $term);
        $department = $this->department === '' ? $departments->first() : $departments->first(fn (Department $department): bool => $department->code === $this->department);

        abort_if($this->department !== '' && $department === null, 403);

        $this->departments = $departments->map(fn (Department $department): array => ['code' => $department->code, 'name' => "{$department->code} · {$department->name}"])->all();

        if ($department === null) {
            $this->message = 'No tienes un departamento con bandeja en este campus.';

            return;
        }

        $this->department = $department->code;
        $this->termId = $term->getKey();
        $this->campusId = $campus->getKey();
        $this->departmentId = $department->getKey();
        $this->message = null;
    }

    /**
     * La bandeja con la que entró la página, con la policy de verla: una petición puede llegar de una página que
     * abrió otro usuario.
     */
    private function inbox(DepartmentInboxQuery $query): DepartmentInbox
    {
        abort_if($this->departmentId === null, 403);

        $inbox = $query->inboxOf((string) $this->termId, (string) $this->campusId, $this->departmentId);
        $this->authorize('viewAny', [ProposalReview::class, $inbox->campus, $inbox->department]);

        return $inbox;
    }

    /**
     * La parte del departamento de la página, en su periodo y campus, por un id del cliente, con las policies de
     * ver la bandeja y de decidir la parte; sin armar la bandeja.
     */
    private function decidable(string $id, DepartmentInboxQuery $query): ProposalReview
    {
        abort_if($this->departmentId === null || $this->campusId === null, 403);

        [$campus, $department] = $query->scopeOf($this->campusId, $this->departmentId);
        $this->authorize('viewAny', [ProposalReview::class, $campus, $department]);

        $review = $query->reviewOrFail($department, $id, $this->termId, $this->campusId);
        $review->setRelation('department', $department);
        $this->authorize('decide', $review);

        return $review;
    }

    /**
     * La carrera abierta: la elegida, o la primera que se puede decidir, o la primera con parte, o la primera.
     *
     * @return array<string, mixed>|null
     */
    private function selected(DepartmentInbox $inbox): ?array
    {
        $chosen = $this->careerId === '' ? null : $inbox->career($this->careerId);

        if ($chosen !== null) {
            return $chosen;
        }

        foreach ($inbox->careers as $row) {
            if ($row['decidable']) {
                return $row;
            }
        }

        foreach ($inbox->careers as $row) {
            if ($row['status'] !== InboxStatus::NotSubmitted) {
                return $row;
            }
        }

        return $inbox->careers[0] ?? null;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, mixed>|null
     */
    private function targetOf(array $rows, string $reviewId): ?array
    {
        foreach ($rows as $row) {
            if ($row['review']?->getKey() === $reviewId) {
                return $row;
            }
        }

        return null;
    }

    private function label(ProposalReview $review): string
    {
        return "{$review->proposal->career->code} · {$review->department->code}";
    }

    private function acceptBlock(DepartmentInbox $inbox): string
    {
        if ($inbox->pendingCatalogs !== []) {
            return 'Valida los catálogos del departamento para poder aceptar.';
        }

        return "{$inbox->department->code} no tiene carga en {$inbox->campus->code} para este periodo.";
    }

    /**
     * La sugerencia de paralelos de cada materia que piden varias carreras, por id de materia; solo la que ahorra
     * grupos o arma un grupo mixto.
     *
     * @return array<string, string>
     */
    private function suggestions(DepartmentInbox $inbox): array
    {
        $suggestions = [];

        foreach ($inbox->subjects as $row) {
            $parallels = $row['parallels'];

            if (count($parallels->careers) < 2 || ($parallels->saved() <= 0 && $parallels->mixed === 0)) {
                continue;
            }

            $suggestions[$row['subject']->getKey()] = $this->describe($parallels);
        }

        return $suggestions;
    }

    private function describe(ParallelGroups $parallels): string
    {
        $parts = [];

        foreach ($parallels->careers as $code => $career) {
            if ($career['full'] > 0) {
                $parts[] = "{$career['full']} {$code}";
            }
        }

        if ($parallels->mixed > 0) {
            $parts[] = $parallels->isMixed()
                ? $parallels->mixed.($parallels->mixed === 1 ? ' mixto' : ' mixtos').' ('.collect($parallels->mixedShares)->map(fn (int $share, string $code): string => "{$share} de {$code}")->implode(' + ').')'
                : '1 más de '.array_key_first($parallels->mixedShares).' ('.array_sum($parallels->mixedShares).')';
        }

        if ($parts === []) {
            return "Piden {$parallels->requested} · sin alumnos esperados";
        }

        $verb = match (true) {
            $parallels->suggested < $parallels->requested => 'bastan',
            $parallels->suggested > $parallels->requested => 'hacen falta',
            default => 'son',
        };

        $last = array_pop($parts);
        $list = $parts === [] ? $last : implode(', ', $parts).' y '.$last;

        return "Piden {$parallels->requested} · juntando carreras {$verb} {$parallels->suggested}: {$list}";
    }

    private function report(ValidationException $e, string $title): void
    {
        foreach ($e->errors() as $field => $messages) {
            $this->addError($field, $messages[0]);
        }

        $this->error($title, e(collect($e->errors())->flatten()->first()));
    }
};
