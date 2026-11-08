<?php

use App\Modules\Core\Authorization\Actor;
use App\Modules\Core\Authorization\Target;
use App\Modules\Faculty\Models\Teacher;
use App\Modules\Scheduling\Models\Group;
use App\Modules\Staffing\Actions\ChangeRequests\RequestChange;
use App\Modules\Staffing\Enums\ChangeRequestKind;
use App\Modules\Staffing\Enums\StaffingPermission;
use App\Modules\Staffing\Models\ChangeRequest;
use App\Modules\Staffing\Queries\MyChangeRequestsQuery;
use App\Modules\Staffing\Queries\MyLoadQuery;
use App\Modules\Staffing\Rules\ChangeRequestRules;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;
use Mary\Traits\Toast;

/**
 * Mi carga (tareas 3.10 y 3.11), maqueta C «Columnas por día»: la cuenta de horas del docente como una ecuación y sus
 * grupos del periodo por día, de todos los campus, con huecos y traslados. Lo ve quien tiene el permiso personal
 * `staffing.my_load.view` y una ficha de docente con su usuario.
 *
 * Abajo, «Mis solicitudes» (tarea 3.11): sus grupos con «Solicitar cambio», que abre un modal (horario o salón, qué
 * propone y el motivo) y llama a RequestChange; el botón aparece si ChangeRequestPolicy::create lo deja y la carga del
 * departamento del grupo está en Asignación o Ajustes, y queda deshabilitado con la insignia de su estado si el grupo ya
 * tiene una solicitud abierta (una por docente y grupo, confirmado el 7 de noviembre de 2026). Debajo, la lista de sus
 * solicitudes del periodo con el comentario del jefe. Lo que lee sale de MyLoadQuery y MyChangeRequestsQuery.
 */
new #[Title('Mi carga')] class extends Component
{
    use Toast;

    /** El grupo del modal «Solicitar cambio». */
    #[Locked]
    public ?string $requestGroupId = null;

    public bool $showRequest = false;

    /** 'schedule' o 'room' (ChangeRequestKind). */
    public string $requestKind = 'schedule';

    public string $proposal = '';

    public string $reason = '';

    public function mount(Actor $context): void
    {
        abort_unless($context->allowsAny(StaffingPermission::MyLoadView), 403);
    }

    /**
     * Abre el modal para pedir un cambio en un grupo suyo. Si el grupo ya tiene una solicitud abierta, no lo abre (el
     * botón ya se ve deshabilitado).
     */
    public function openRequest(string $groupId, Actor $context, MyChangeRequestsQuery $requests): void
    {
        [$group, $state] = $this->requestable($groupId, $context, $requests);

        if ($state['open'] !== null) {
            return;
        }

        $this->requestGroupId = $group->id;
        $this->requestKind = ChangeRequestKind::Schedule->value;
        $this->reset('proposal', 'reason');
        $this->resetErrorBag();
        $this->showRequest = true;
    }

    public function sendRequest(Actor $context, MyChangeRequestsQuery $requests, RequestChange $requestChange): void
    {
        abort_if($this->requestGroupId === null, 404);

        [$group] = $this->requestable($this->requestGroupId, $context, $requests);

        $max = ChangeRequestRules::MAX_TEXT_LENGTH;
        $this->validate([
            'requestKind' => ['required', Rule::enum(ChangeRequestKind::class)],
            'proposal' => ['required', 'string', "max:{$max}"],
            'reason' => ['required', 'string', "max:{$max}"],
        ], [
            'requestKind.*' => 'Elige si es de horario o de salón.',
            'proposal.required' => 'Describe qué cambio pides.',
            'proposal.max' => "Lo que propones lleva hasta {$max} caracteres.",
            'reason.required' => 'Escribe el motivo del cambio.',
            'reason.max' => "El motivo lleva hasta {$max} caracteres.",
        ]);

        try {
            $requestChange->handle($group, auth()->user(), ChangeRequestKind::from($this->requestKind), $this->proposal, $this->reason);
        } catch (ValidationException $e) {
            foreach ($e->errors() as $field => $messages) {
                $this->addError($field === 'description' ? 'proposal' : $field, $messages[0]);
            }

            return;
        }

        $this->showRequest = false;
        $this->requestGroupId = null;
        $this->reset('proposal', 'reason');
        $this->success("Enviaste tu solicitud de cambio del grupo {$group->code}", 'Tu jefe de departamento la revisa.');
    }

    public function with(Actor $context, MyLoadQuery $query, MyChangeRequestsQuery $requests): array
    {
        $user = $context->user();
        abort_unless($user !== null && $context->allows(StaffingPermission::MyLoadView, Target::ownedBy((string) $user->getKey())), 403);

        $term = $context->activeTerm();
        $teacher = Teacher::query()->where('user_id', $user->getKey())->where('is_active', true)->first();
        $load = $term === null || $teacher === null ? null : $query->forTeacher($teacher, $term);

        return [
            'term' => $term,
            'teacher' => $teacher,
            'load' => $load,
            'mine' => $load === null ? null : $requests->forTeacher($teacher, (string) $user->getKey(), $term),
            'canRequest' => $context->allows(StaffingPermission::ChangeRequestRequestOwn, Target::ownedBy((string) $user->getKey())),
            'requestGroup' => $load === null || $this->requestGroupId === null ? null : collect($load['groups'])->firstWhere('id', $this->requestGroupId),
            'maxText' => ChangeRequestRules::MAX_TEXT_LENGTH,
        ];
    }

    /**
     * «2 h» o «1 h 30 min».
     */
    public static function span(int $minutes): string
    {
        $hours = intdiv($minutes, 60);
        $rest = $minutes % 60;

        return trim(($hours > 0 ? $hours.' h' : '').($rest > 0 ? ' '.$rest.' min' : ''));
    }

    /**
     * Un grupo suyo del periodo activo en el que puede pedir: el de otro docente no existe (404); sin el permiso
     * (ChangeRequestPolicy::create) o con la carga fuera de Asignación y Ajustes, 403.
     *
     * @return array{0: Group, 1: array{open: mixed, load: mixed}}
     */
    private function requestable(string $groupId, Actor $context, MyChangeRequestsQuery $requests): array
    {
        $user = $context->user();
        $term = $context->activeTerm();
        $teacher = $user === null ? null : Teacher::query()->where('user_id', $user->getKey())->where('is_active', true)->first();

        abort_if($term === null || $teacher === null, 404);

        $group = $requests->groupOf($teacher, $term, $groupId);

        abort_if($group === null, 404);

        $this->authorize('create', [ChangeRequest::class, $group]);

        $state = $requests->forTeacher($teacher, (string) $user->getKey(), $term)['groups'][$group->id];

        abort_unless(ChangeRequestRules::acceptsRequests($state['load']), 403);

        return [$group, $state];
    }
};
