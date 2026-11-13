<?php

use App\Modules\Academic\Models\Department;
use App\Modules\Academic\Models\Subject;
use App\Modules\AcademicLoad\Actions\Proposals\AcceptProposalReview;
use App\Modules\AcademicLoad\Actions\Proposals\ReturnProposalReview;
use App\Modules\AcademicLoad\Enums\AcademicLoadPermission;
use App\Modules\AcademicLoad\Enums\InboxStatus;
use App\Modules\AcademicLoad\Models\ProposalItem;
use App\Modules\AcademicLoad\Models\ProposalReview;
use App\Modules\AcademicLoad\Queries\CareerProposalQuery;
use App\Modules\AcademicLoad\Queries\DepartmentInbox;
use App\Modules\AcademicLoad\Queries\DepartmentInboxQuery;
use App\Modules\AcademicLoad\Rules\ParallelGroups;
use App\Modules\AcademicLoad\Rules\ProposalReviewRules;
use App\Modules\Core\Authorization\Actor;
use App\Modules\Core\Livewire\Concerns\WithModalityScope;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Modality;
use App\Modules\Core\Rules\SelfApproval;
use Illuminate\Support\Collection;
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
 * a un departamento en el periodo activo de la credencial y la modalidad de la pantalla (`?modalidad=`,
 * WithModalityScope; M1, M6): una propuesta por carrera, con las filas de cada campus. A la izquierda las carreras
 * con el estado de su parte; a la derecha lo que pide la elegida, materia por materia y campus contra el total de la
 * materia en ese campus, con la sugerencia de paralelos y los botones Aceptar y Regresar (maqueta C).
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
    use WithModalityScope;

    private const array FILTERS = ['todas', 'pendientes'];

    /** Código del departamento; '' es el primero que puede ver. */
    #[Url(as: 'depto')]
    public string $department = '';

    /** Id de la carrera elegida; '' es la primera pendiente. */
    #[Url(as: 'carrera')]
    public string $careerId = '';

    /** 'todas' o 'pendientes'. */
    #[Url(as: 'filtro')]
    public string $filter = 'todas';

    /** Si el detalle muestra solo las materias con anomalías. */
    #[Url(as: 'anomalias')]
    public bool $onlyAnomalies = false;

    /** El periodo, la modalidad y el departamento con los que entró. El navegador no los puede cambiar. */
    #[Locked]
    public ?string $termId = null;

    #[Locked]
    public ?string $modalityId = null;

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

    /** La materia de la que se ven los paralelos, y su campus. */
    #[Locked]
    public ?string $parallelsSubjectId = null;

    #[Locked]
    public ?string $parallelsCampusId = null;

    public bool $showParallels = false;

    public function mount(Actor $context, DepartmentInboxQuery $inbox, CareerProposalQuery $proposals): void
    {
        abort_unless($context->allowsAny(AcademicLoadPermission::ProposalReviewView), 403);

        $this->mobileDetail = $this->careerId !== '';
        $this->updatedFilter();
        $this->resolve($context, $inbox, $proposals);
    }

    /**
     * Otra modalidad: la página se abre de nuevo en ella.
     */
    public function updatedModalidad(): void
    {
        $this->redirectRoute('academic-load.inbox', ['modalidad' => $this->requiredModality()->code], navigate: true);
    }

    /**
     * Cambiar de departamento suelta la carrera y la parte de la anterior.
     */
    public function updatedDepartment(Actor $context, DepartmentInboxQuery $inbox, CareerProposalQuery $proposals): void
    {
        $this->reset('careerId', 'reviewId', 'showAccept', 'showReturn', 'comment', 'mobileDetail', 'onlyAnomalies', 'showParallels', 'parallelsSubjectId', 'parallelsCampusId');
        $this->resolve($context, $inbox, $proposals);
    }

    /**
     * Cerrar la confirmación o el drawer (Esc, el velo, Cancelar) suelta la parte que se decidía.
     */
    public function updatedShowAccept(bool $open): void
    {
        if (! $open && ! $this->showReturn) {
            $this->reviewId = null;
        }
    }

    public function updatedShowReturn(bool $open): void
    {
        if (! $open && ! $this->showAccept) {
            $this->reviewId = null;
        }
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
        $this->onlyAnomalies = false;
        $this->mobileDetail = true;
        $this->dispatch('inbox-detail-opened');
    }

    public function back(): void
    {
        $this->mobileDetail = false;
    }

    /**
     * Abre el detalle de los paralelos de una materia de la bandeja en un campus (sin campus, la columna única de una
     * modalidad sin salones).
     */
    public function openParallels(string $subjectId, DepartmentInboxQuery $query, string $campusId = ''): void
    {
        $subject = collect($this->inbox($query)->subjects)->first(fn (array $row): bool => $row['subject']->getKey() === $subjectId && ($row['campus']?->getKey() ?? '') === $campusId);

        abort_if($subject === null, 404);

        $this->parallelsSubjectId = $subject['subject']->getKey();
        $this->parallelsCampusId = $subject['campus']?->getKey();
        $this->showAccept = $this->showReturn = false;
        $this->showParallels = true;
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
            $this->reviewId = null;
            $this->report($e, 'No se aceptó');

            return;
        }

        $this->showAccept = false;
        $this->reviewId = null;
        $this->goToNext($query, $review);
        $this->success("Aceptaste la parte de {$review->proposal->career->code}", e($review->department->name));
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
        $this->reviewId = null;
        $this->comment = '';
        $this->goToNext($query, $review);
        $this->warning("Regresaste la parte de {$review->proposal->career->code}", e($review->department->name));
    }

    /**
     * @return array<string, mixed>
     */
    public function with(DepartmentInboxQuery $query): array
    {
        if ($this->departmentId === null) {
            return ['inbox' => null, ...$this->modalityViewData()];
        }

        $inbox = $this->inbox($query);
        $rows = $inbox->careers;
        $selected = $this->selected($inbox);
        $canDecide = $selected !== null && $selected['decidable'] && Gate::allows('decide', $selected['review']);
        $allItems = $selected === null ? [] : $inbox->rowsFor($selected['career']->getKey());
        $anomalous = $this->anomalousRows($allItems);

        if ($anomalous === []) {
            $this->onlyAnomalies = false;
        }

        $items = $this->onlyAnomalies ? $anomalous : $allItems;
        $submitted = array_values(array_filter($rows, fn (array $row): bool => $row['status'] !== InboxStatus::NotSubmitted));
        $target = $this->reviewId === null ? null : $this->targetOf($rows, $this->reviewId);
        $catalogsUrl = Route::has('catalog-validation.review') ? route('catalog-validation.review', ['department' => $inbox->department->getKey()]) : null;

        return [
            ...$this->modalityViewData(),
            'inbox' => $inbox,
            'rows' => $rows,
            'visible' => $this->filter === 'pendientes'
                ? array_values(array_filter($rows, fn (array $row): bool => $row['decidable']))
                : $rows,
            'pendingCount' => count(array_filter($rows, fn (array $row): bool => $row['decidable'])),
            'acceptedCount' => count(array_filter($rows, fn (array $row): bool => $row['status'] === InboxStatus::Accepted)),
            'submittedCount' => count($submitted),
            'selected' => $selected,
            'items' => $items,
            'anomalousItemCount' => count($anomalous),
            'canDecide' => $canDecide,
            'acceptBlock' => $inbox->canAccept() ? null : $this->acceptBlock($inbox),
            'catalogsUrl' => $catalogsUrl,
            'chips' => $this->chips($inbox),
            'anomalyCounts' => array_combine(array_map(fn (array $row): string => $row['career']->getKey(), $rows), array_column($rows, 'anomalies')),
            'target' => $target,
            'selfApproval' => $target !== null && SelfApproval::applies(auth()->user(), $target['proposal']?->submitted_by),
            'targetAnomalies' => $target === null ? [] : $this->anomalousRows($inbox->rowsFor($target['career']->getKey())),
            'parallelsTarget' => $this->parallelsTarget($inbox),
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
     * Resuelve con la credencial el periodo, con la página la modalidad y el departamento, y deja en propiedades
     * bloqueadas con qué pinta y escribe la página (o por qué no hay bandeja). Un departamento pedido por la URL que el
     * usuario no ve es 403.
     */
    private function resolve(Actor $context, DepartmentInboxQuery $inbox, CareerProposalQuery $proposals): void
    {
        $this->termId = $this->modalityId = $this->departmentId = null;
        $this->departments = [];

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

        $departments = $inbox->departmentsFor($context, $modality, $term);
        $department = $this->department === '' ? $departments->first() : $departments->first(fn (Department $department): bool => $department->code === $this->department);

        abort_if($this->department !== '' && $department === null, 403);

        $this->departments = $departments->map(fn (Department $department): array => ['code' => $department->code, 'name' => "{$department->code} · {$department->name}"])->all();

        if ($department === null) {
            $this->message = "No tienes un departamento con bandeja en {$modality->name}.";

            return;
        }

        $this->department = $department->code;
        $this->termId = $term->getKey();
        $this->modalityId = $modality->getKey();
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

        $inbox = $query->inboxOf((string) $this->termId, (string) $this->modalityId, $this->departmentId);
        $this->authorize('viewAny', [ProposalReview::class, $inbox->modality, $inbox->department]);

        return $inbox;
    }

    /**
     * La parte del departamento de la página, en su periodo y modalidad, por un id del cliente, con las policies de
     * ver la bandeja y de decidir la parte; sin armar la bandeja.
     */
    private function decidable(string $id, DepartmentInboxQuery $query): ProposalReview
    {
        abort_if($this->departmentId === null || $this->modalityId === null, 403);

        [$modality, $department] = $query->scopeOf($this->modalityId, $this->departmentId);
        $this->authorize('viewAny', [ProposalReview::class, $modality, $department]);

        $review = $query->reviewOrFail($department, $id, $this->termId, $this->modalityId);
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

    private function acceptBlock(DepartmentInbox $inbox): string
    {
        if ($inbox->pendingCatalogs !== []) {
            return 'Valida los catálogos del departamento para poder aceptar.';
        }

        return "{$inbox->department->code} no tiene carga en ".($inbox->modality?->name ?? 'esta modalidad').' para este periodo.';
    }

    /**
     * El chip de paralelos de cada materia que piden varias carreras en un campus, por «<id de materia>|<id de campus>»;
     * solo la que ahorra grupos o arma un grupo mixto. `label` es su texto y `saved`, los grupos que ahorra (negativo si
     * hacen falta).
     *
     * @return array<string, array{label: string, saved: int}>
     */
    private function chips(DepartmentInbox $inbox): array
    {
        $chips = [];

        foreach ($inbox->subjects as $row) {
            $parallels = $row['parallels'];

            if (count($parallels->careers) < 2 || ($parallels->saved() <= 0 && $parallels->mixed === 0)) {
                continue;
            }

            $saved = $parallels->saved();
            $groups = fn (int $n): string => $n === 1 ? '1 grupo' : "{$n} grupos";
            $rest = $parallels->isMixed() ? 'Con grupos mixtos' : '1 grupo más de '.array_key_first($parallels->mixedShares);

            $chips[$row['subject']->getKey().'|'.($row['campus']?->getKey() ?? '')] = [
                'saved' => $saved,
                'label' => match (true) {
                    $parallels->suggested === 0 => 'Sin alumnos esperados',
                    $saved > 0 => 'Ahorra '.$groups($saved).' juntando carreras',
                    $saved < 0 => 'Necesita '.$groups(-$saved).' más juntando carreras',
                    default => $rest,
                },
            ];
        }

        return $chips;
    }

    /**
     * La materia que se ve en el drawer de paralelos, con su campus, su sugerencia, su resumen y el reparto por carrera.
     *
     * @return array{subject: Subject, campus: ?Campus, parallels: ParallelGroups, summary: string, mixed: ?string}|null
     */
    private function parallelsTarget(DepartmentInbox $inbox): ?array
    {
        if ($this->parallelsSubjectId === null) {
            return null;
        }

        foreach ($inbox->subjects as $row) {
            if ($row['subject']->getKey() === $this->parallelsSubjectId && $row['campus']?->getKey() === $this->parallelsCampusId) {
                return ['subject' => $row['subject'], 'campus' => $row['campus'], 'parallels' => $row['parallels'], 'summary' => $this->describe($row['parallels']), 'mixed' => $this->describeMixed($row['parallels'])];
            }
        }

        return null;
    }

    /**
     * De las filas de una carrera, las que tienen alguna anomalía.
     *
     * @param  list<array{item: ProposalItem, total: array{careers: int, groups: int, expected: int}, anomalies: list<string>}>  $rows
     * @return list<array{item: ProposalItem, total: array{careers: int, groups: int, expected: int}, anomalies: list<string>}>
     */
    private function anomalousRows(array $rows): array
    {
        return array_values(array_filter($rows, fn (array $entry): bool => $entry['anomalies'] !== []));
    }

    /**
     * Pasa a la carrera que sigue por decidir después de decidir la de la parte: la siguiente por clave entre las que
     * se pueden decidir, o la primera si era la última; si no queda otra, se queda donde estaba. Una consulta
     * (DepartmentInboxQuery::decidableCareers), sin armar la bandeja.
     */
    private function goToNext(DepartmentInboxQuery $query, ProposalReview $review): void
    {
        $left = $query->decidableCareers((string) $this->termId, (string) $this->modalityId, $review->department);
        $code = $review->proposal->career->code;
        $next = collect($left)->first(fn (string $other): bool => $other > $code) ?? collect($left)->first();

        if ($next !== null) {
            $this->careerId = (string) array_search($next, $left, true);
            $this->onlyAnomalies = false;
            $this->dispatch('inbox-detail-opened');
        }
    }

    private function describe(ParallelGroups $parallels): string
    {
        if ($parallels->suggested === 0) {
            return "Piden {$parallels->requested} · sin alumnos esperados";
        }

        $verb = match (true) {
            $parallels->suggested < $parallels->requested => 'bastan',
            $parallels->suggested > $parallels->requested => 'hacen falta',
            default => 'son',
        };

        return "Piden {$parallels->requested} · juntando carreras {$verb} {$parallels->suggested}";
    }

    /**
     * Los grupos con los restos: «2 mixtos (5 de IIND + 25 de ISC)» si juntan alumnos de varias carreras, o
     * «1 más de ISC (25)» si son de una sola; null si no hay restos.
     */
    private function describeMixed(ParallelGroups $parallels): ?string
    {
        if ($parallels->mixed === 0) {
            return null;
        }

        if (! $parallels->isMixed()) {
            return '1 más de '.array_key_first($parallels->mixedShares).' ('.array_sum($parallels->mixedShares).')';
        }

        $groups = $parallels->mixed.($parallels->mixed === 1 ? ' mixto' : ' mixtos');

        // Con muchas carreras, el reparto ya está en la columna «Al mixto» de la tabla: aquí solo el resumen.
        return count($parallels->mixedShares) > 3
            ? $groups.' con '.array_sum($parallels->mixedShares).' alumnos de '.count($parallels->mixedShares).' carreras'
            : $groups.' ('.collect($parallels->mixedShares)->map(fn (int $share, string $code): string => "{$share} de {$code}")->implode(' + ').')';
    }

    private function report(ValidationException $e, string $title): void
    {
        foreach ($e->errors() as $field => $messages) {
            $this->addError($field, $messages[0]);
        }

        $this->error($title, e(collect($e->errors())->flatten()->first()));
    }
};
