<?php

use App\Modules\Academic\Models\Department;
use App\Modules\AcademicLoad\Enums\LoadStatus;
use App\Modules\Core\Authorization\Actor;
use App\Modules\Staffing\Actions\ChangeRequests\ApproveChangeRequest;
use App\Modules\Staffing\Actions\ChangeRequests\MarkChangeRequestApplied;
use App\Modules\Staffing\Actions\ChangeRequests\RejectChangeRequest;
use App\Modules\Staffing\Enums\ChangeRequestKind;
use App\Modules\Staffing\Enums\ChangeRequestStatus;
use App\Modules\Staffing\Enums\StaffingPermission;
use App\Modules\Staffing\Models\ChangeRequest;
use App\Modules\Staffing\Queries\ChangeRequestsQuery;
use App\Modules\Staffing\Rules\ChangeRequestRules;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Mary\Traits\Toast;

/**
 * Solicitudes de cambio (tarea 3.11, maqueta A «Lista y detalle»): las solicitudes de cambio de horario y de salón de
 * un departamento en el periodo y el campus de la credencial. A la izquierda la lista (pendientes, luego aprobadas por
 * aplicar, luego el resto) con filtros de estado, tipo y texto; a la derecha el detalle de la elegida, con la foto de
 * las sesiones contra lo que se propone, el motivo y el historial. Arriba, el estado de la carga del departamento.
 *
 * Quién hace qué (reglas-de-negocio.md, "Solicitudes de cambio"; ChangeRequestPolicy antes de cada método): el jefe
 * del departamento aprueba (comentario opcional) o rechaza (comentario obligatorio); aplica el coordinador de la carrera
 * dueña si es de horario y el jefe si es de salón: «Abrir en el editor» (si existe el editor de horarios) y «Marcar
 * como aplicada». Si Scheduling veta el regreso de la carga a Asignación, la pantalla muestra los motivos.
 *
 * Entra quien puede ver, decidir, aplicar o pedir alguna; cada quien ve solo lo de su alcance (ChangeRequestsQuery).
 * El departamento es el primero que puede ver; con varios, `?depto=<código>` lo elige, y `?solicitud=<id>` (el de los
 * avisos) abre esa solicitud en su departamento.
 */
new #[Title('Solicitudes de cambio')] class extends Component
{
    use Toast;

    /** Valores de `?estado=` y el estado que filtran. */
    private const array STATUSES = [
        'pendientes' => ChangeRequestStatus::Pending,
        'aprobadas' => ChangeRequestStatus::Approved,
        'rechazadas' => ChangeRequestStatus::Rejected,
        'aplicadas' => ChangeRequestStatus::Applied,
    ];

    /** Valores de `?tipo=` y el tipo que filtran. */
    private const array KINDS = [
        'horario' => ChangeRequestKind::Schedule,
        'salon' => ChangeRequestKind::Room,
    ];

    /** Código del departamento; '' es el primero que puede ver. */
    #[Url(as: 'depto')]
    public string $department = '';

    /** Id de la solicitud abierta; '' es la primera de la lista. */
    #[Url(as: 'solicitud')]
    public string $requestId = '';

    #[Url(as: 'estado')]
    public string $status = '';

    #[Url(as: 'tipo')]
    public string $kind = '';

    #[Url(as: 'buscar')]
    public string $search = '';

    /** El comentario del jefe al decidir. */
    public string $comment = '';

    /** El periodo, el campus y el departamento con los que entró. El navegador no los puede cambiar. */
    #[Locked]
    public ?string $termId = null;

    #[Locked]
    public ?string $campusId = null;

    #[Locked]
    public ?string $departmentId = null;

    /** @var list<array{code: string, name: string, label: string}> */
    #[Locked]
    public array $departments = [];

    #[Locked]
    public ?string $message = null;

    /** En pantallas angostas, la lista y el detalle van por separado. */
    #[Locked]
    public bool $mobileDetail = false;

    /**
     * Los motivos con que Scheduling vetó el regreso de la carga a Asignación al aplicar la última solicitud.
     *
     * @var list<string>
     */
    #[Locked]
    public array $vetoReasons = [];

    public function mount(Actor $context, ChangeRequestsQuery $query): void
    {
        abort_unless($this->canEnter($context), 403);

        $this->mobileDetail = $this->requestId !== '';
        $this->updatedStatus();
        $this->updatedKind();
        $this->resolve($context, $query);
    }

    public function updatedDepartment(Actor $context, ChangeRequestsQuery $query): void
    {
        $this->reset('requestId', 'comment', 'mobileDetail', 'vetoReasons');
        $this->resolve($context, $query);
    }

    public function updatedStatus(): void
    {
        $this->status = array_key_exists($this->status, self::STATUSES) ? $this->status : '';
    }

    public function updatedKind(): void
    {
        $this->kind = array_key_exists($this->kind, self::KINDS) ? $this->kind : '';
    }

    /**
     * Abre una solicitud de la lista; en pantallas angostas, su detalle.
     */
    public function pick(string $id, Actor $context, ChangeRequestsQuery $query): void
    {
        $request = $this->visibleOrFail($id, $context, $query);

        $this->requestId = $request->id;
        $this->comment = '';
        $this->vetoReasons = [];
        $this->resetErrorBag();
        $this->mobileDetail = true;
        $this->dispatch('request-detail-opened');
    }

    public function back(): void
    {
        $this->mobileDetail = false;
    }

    public function approve(Actor $context, ChangeRequestsQuery $query, ApproveChangeRequest $approve): void
    {
        $request = $this->selectedOrFail($context, $query);
        $this->authorize('decide', $request);
        $this->validateComment(required: false);

        try {
            $approve->handle($request, auth()->user(), $this->comment);
        } catch (ValidationException $e) {
            $this->report($e, 'No se aprobó');

            return;
        }

        $this->comment = '';
        $load = $query->loadOf((string) $this->termId, (string) $this->campusId, (string) $this->departmentId)['load'];
        $this->success('Aprobaste la solicitud', $load?->status === LoadStatus::Adjusting
            ? 'La carga del departamento está en Ajustes. Se avisó a quien la aplica.'
            : 'Se avisó a quien la aplica.');
    }

    public function reject(Actor $context, ChangeRequestsQuery $query, RejectChangeRequest $reject): void
    {
        $request = $this->selectedOrFail($context, $query);
        $this->authorize('decide', $request);
        $this->validateComment(required: true);

        try {
            $reject->handle($request, auth()->user(), $this->comment);
        } catch (ValidationException $e) {
            $this->report($e, 'No se rechazó');

            return;
        }

        $this->comment = '';
        $this->warning('Rechazaste la solicitud', 'Se avisó a quien la pidió.');
    }

    public function markApplied(Actor $context, ChangeRequestsQuery $query, MarkChangeRequestApplied $markApplied): void
    {
        $request = $this->selectedOrFail($context, $query);
        $this->authorize('apply', $request);

        try {
            $result = $markApplied->handle($request, auth()->user());
        } catch (ValidationException $e) {
            $this->report($e, 'No se marcó como aplicada');

            return;
        }

        $this->vetoReasons = $result->vetoReasons;

        match (true) {
            $result->vetoReasons !== [] => $this->warning('Marcaste la solicitud como aplicada', 'La carga sigue en Ajustes: '.e(implode(' ', $result->vetoReasons))),
            $result->returnedToStaffing => $this->success('Marcaste la solicitud como aplicada', 'No quedan cambios por aplicar: la carga regresó a Asignación.'),
            default => $this->success('Marcaste la solicitud como aplicada'),
        };
    }

    /**
     * @return array<string, mixed>
     */
    public function with(Actor $context, ChangeRequestsQuery $query): array
    {
        if ($this->departmentId === null) {
            return ['requests' => null];
        }

        $requests = $query->listFor($context, (string) $this->termId, (string) $this->campusId, $this->departmentId);
        $visible = $this->filtered($requests);
        $selected = ($this->requestId === '' ? null : $requests->firstWhere('id', $this->requestId)) ?? $visible->first();
        $department = collect($this->departments)->firstWhere('code', $this->department);

        return [
            'requests' => $requests,
            'visible' => $visible,
            'counts' => ['' => $requests->count()] + array_map(fn (ChangeRequestStatus $status): int => $requests->where('status', $status)->count(), self::STATUSES),
            'selected' => $selected,
            'departmentName' => $department['label'] ?? $this->department,
            'loadState' => $query->loadOf((string) $this->termId, (string) $this->campusId, $this->departmentId),
            'canDecide' => $selected !== null && $selected->status === ChangeRequestStatus::Pending && Gate::allows('decide', $selected),
            'canApply' => $selected !== null && $selected->status === ChangeRequestStatus::Approved && Gate::allows('apply', $selected),
            'editorUrl' => $selected !== null && Route::has('scheduling.editor') ? route('scheduling.editor', ['grupo' => $selected->group_id]) : null,
            'campusName' => $context->activeCampus()?->name,
            'termName' => $context->activeTerm()?->name,
            'maxText' => ChangeRequestRules::MAX_TEXT_LENGTH,
        ];
    }

    /**
     * La lista con los filtros de estado, tipo y texto (docente, quien pidió, grupo o materia, sin importar mayúsculas
     * ni acentos), en el mismo orden.
     *
     * @param  Collection<int, ChangeRequest>  $requests
     * @return Collection<int, ChangeRequest>
     */
    private function filtered(Collection $requests): Collection
    {
        $status = self::STATUSES[$this->status] ?? null;
        $kind = self::KINDS[$this->kind] ?? null;
        $needle = self::fold($this->search);

        return $requests->filter(fn (ChangeRequest $request): bool => ($status === null || $request->status === $status)
            && ($kind === null || $request->kind === $kind)
            && ($needle === '' || str_contains(self::fold(implode(' ', [
                $request->teacher?->name, $request->requestedBy?->name, $request->group->code, $request->group->subject->code, $request->group->subject->name,
            ])), $needle)))->values();
    }

    /**
     * Resuelve con la credencial el periodo, el campus y el departamento, y deja en propiedades bloqueadas con qué
     * pinta y escribe la página (o por qué no hay lista). `?solicitud=` que no ve en el periodo y el campus es 404; un
     * departamento pedido por `?depto=` que no puede elegir, 403.
     */
    private function resolve(Actor $context, ChangeRequestsQuery $query): void
    {
        $this->termId = $this->campusId = $this->departmentId = null;
        $this->departments = [];

        $campus = $context->activeCampus();
        $term = $context->activeTerm();

        if ($campus === null || $term === null) {
            $this->message = $campus === null ? 'Elige un campus en tu credencial para ver las solicitudes de cambio.' : 'No hay un periodo activo: elige uno en tu credencial.';

            return;
        }

        $departments = $query->departmentsFor($context, $campus, $term);
        $requestedDepartment = null;

        if ($this->requestId !== '') {
            $requestedDepartment = $query->departmentOf($context, $this->requestId, $term->id, $campus->id);

            abort_if($requestedDepartment === null, 404);
        }

        $department = match (true) {
            $requestedDepartment !== null => $departments->firstWhere('id', $requestedDepartment),
            $this->department !== '' => $departments->first(fn (Department $department): bool => $department->code === $this->department),
            default => $departments->first(),
        };

        abort_if($department === null && ($this->department !== '' || $requestedDepartment !== null), 403);

        $this->departments = $departments->map(fn (Department $department): array => ['code' => $department->code, 'name' => "{$department->code} · {$department->name}", 'label' => $department->name])->all();

        if ($department === null) {
            $this->message = 'No tienes solicitudes de cambio en este campus.';

            return;
        }

        $this->department = $department->code;
        $this->termId = $term->id;
        $this->campusId = $campus->id;
        $this->departmentId = $department->id;
        $this->message = null;
    }

    /**
     * La solicitud abierta en el detalle (la elegida o la primera de la lista), con las mismas reglas de la lista.
     */
    private function selectedOrFail(Actor $context, ChangeRequestsQuery $query): ChangeRequest
    {
        abort_if($this->departmentId === null, 403);

        $id = $this->requestId !== '' ? $this->requestId : (string) $this->filtered($query->listFor($context, (string) $this->termId, (string) $this->campusId, $this->departmentId))->first()?->id;

        return $this->visibleOrFail($id, $context, $query);
    }

    private function visibleOrFail(string $id, Actor $context, ChangeRequestsQuery $query): ChangeRequest
    {
        abort_if($this->departmentId === null, 403);

        return $query->find($context, $id, (string) $this->termId, (string) $this->campusId, $this->departmentId) ?? abort(404);
    }

    private function validateComment(bool $required): void
    {
        $max = ChangeRequestRules::MAX_TEXT_LENGTH;

        $this->validate(
            ['comment' => [$required ? 'required' : 'nullable', 'string', "max:{$max}"]],
            ['comment.required' => 'Para rechazar, escribe un comentario.', 'comment.max' => "El comentario lleva hasta {$max} caracteres."],
        );
    }

    private function canEnter(Actor $context): bool
    {
        foreach ([
            StaffingPermission::ChangeRequestView, StaffingPermission::ChangeRequestDecide, StaffingPermission::ChangeRequestApplySchedule,
            StaffingPermission::ChangeRequestApplyRoom, StaffingPermission::ChangeRequestRequest, StaffingPermission::ChangeRequestRequestOwn,
        ] as $permission) {
            if ($context->allowsAny($permission)) {
                return true;
            }
        }

        return false;
    }

    private function report(ValidationException $e, string $title): void
    {
        foreach ($e->errors() as $field => $messages) {
            $this->addError($field, $messages[0]);
        }

        $this->error($title, e(collect($e->errors())->flatten()->first()));
    }

    private static function fold(string $text): string
    {
        $ascii = transliterator_transliterate('NFD; [:Nonspacing Mark:] Remove; NFC; Lower()', $text);

        return trim($ascii === false ? mb_strtolower($text) : $ascii);
    }
};
