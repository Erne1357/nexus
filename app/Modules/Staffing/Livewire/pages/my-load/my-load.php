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
use Livewire\Attributes\Title;
use Livewire\Component;
use Mary\Traits\Toast;

/**
 * Mi carga (tareas 3.10 y 3.11), maqueta C «Columnas por día»: la cuenta de horas del docente como una ecuación y sus
 * grupos del periodo por día, de todos los campus, con huecos y traslados. Lo ve quien tiene el permiso personal
 * `staffing.my_load.view` y una ficha de docente con su usuario. De cada carga con versión publicada ve esa versión,
 * aunque se haya reabierto, y bajo el título «Publicado el … · vN» (P8; MyLoadQuery).
 *
 * Abajo, «Mis solicitudes» (tarea 3.11): un solo «Solicitar cambio» (con el permiso de pedir) abre un modal donde
 * elige el grupo, si es de horario o de salón, qué propone y el motivo, y llama a RequestChange. Un grupo se pide si
 * ChangeRequestPolicy::create lo deja, la carga de su departamento está en Asignación o Ajustes y no tiene ya una
 * solicitud abierta (una por docente y grupo, confirmado el 7 de noviembre de 2026); los demás quedan en el select
 * deshabilitados con su motivo, y si ninguno se puede, el botón queda deshabilitado con el suyo. La solicitud es sobre
 * el grupo vivo: un grupo publicado que después se reasignó o se canceló sale deshabilitado. Debajo, la lista de
 * sus solicitudes del periodo con el comentario del jefe. Lo que lee sale de MyLoadQuery y MyChangeRequestsQuery.
 */
new #[Title('Mi carga')] class extends Component
{
    use Toast;

    /** El motivo de un grupo publicado que en lo vivo ya no es suyo: se reasignó o se canceló después de publicar. */
    private const string NOT_YOURS_ANYMORE = 'Reasignado o cancelado después de publicar';

    /** El grupo elegido en el modal «Solicitar cambio»; se revisa de nuevo al enviar (requestable()). */
    public string $requestGroupId = '';

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
     * Abre el modal «Solicitar cambio». Con un grupo, ese (si ya tiene una solicitud abierta no lo abre); sin grupo, el
     * único que se puede pedir de los que se ven, si es uno solo (con la carga publicada, un grupo vivo que no está en
     * su versión publicada no se ve, así que no se elige).
     */
    public function openRequest(Actor $context, MyChangeRequestsQuery $requests, MyLoadQuery $query, ?string $groupId = null): void
    {
        if ($groupId !== null) {
            [$group, $state] = $this->requestable($groupId, $context, $requests);

            if ($state['open'] !== null) {
                return;
            }

            $this->requestGroupId = $group->id;
        } else {
            $available = $this->available($context, $requests, $query);
            $this->requestGroupId = count($available) === 1 ? $available[0] : '';
        }

        $this->requestKind = ChangeRequestKind::Schedule->value;
        $this->reset('proposal', 'reason');
        $this->resetErrorBag();
        $this->showRequest = true;
    }

    public function sendRequest(Actor $context, MyChangeRequestsQuery $requests, RequestChange $requestChange): void
    {
        $max = ChangeRequestRules::MAX_TEXT_LENGTH;
        $this->validate([
            'requestGroupId' => ['required', 'string'],
            'requestKind' => ['required', Rule::enum(ChangeRequestKind::class)],
            'proposal' => ['required', 'string', "max:{$max}"],
            'reason' => ['required', 'string', "max:{$max}"],
        ], [
            'requestGroupId.*' => 'Elige el grupo.',
            'requestKind.*' => 'Elige si es de horario o de salón.',
            'proposal.required' => 'Describe qué cambio pides.',
            'proposal.max' => "Lo que propones lleva hasta {$max} caracteres.",
            'reason.required' => 'Escribe el motivo del cambio.',
            'reason.max' => "El motivo lleva hasta {$max} caracteres.",
        ]);

        [$group, $state] = $this->requestable($this->requestGroupId, $context, $requests);

        if ($state['open'] !== null) {
            $this->addError('requestGroupId', 'Ya tienes una solicitud abierta para este grupo.');

            return;
        }

        try {
            $requestChange->handle($group, auth()->user(), ChangeRequestKind::from($this->requestKind), $this->proposal, $this->reason);
        } catch (ValidationException $e) {
            foreach ($e->errors() as $field => $messages) {
                $this->addError($field === 'description' ? 'proposal' : $field, $messages[0]);
            }

            return;
        }

        $this->showRequest = false;
        $this->reset('requestGroupId', 'proposal', 'reason');
        $this->success("Enviaste tu solicitud de cambio del grupo {$group->code}", 'Tu jefe de departamento la revisa.');
    }

    public function with(Actor $context, MyLoadQuery $query, MyChangeRequestsQuery $requests): array
    {
        $user = $context->user();
        abort_unless($user !== null && $context->allows(StaffingPermission::MyLoadView, Target::ownedBy((string) $user->getKey())), 403);

        $term = $context->activeTerm();
        $teacher = Teacher::query()->where('user_id', $user->getKey())->where('is_active', true)->first();
        $load = $term === null || $teacher === null ? null : $query->forTeacher($teacher, $term);

        $mine = $load === null ? null : $requests->forTeacher($teacher, (string) $user->getKey(), $term);
        $options = $load === null ? [] : self::optionsFor($load['groups'], $mine['groups']);

        return [
            'term' => $term,
            'teacher' => $teacher,
            'load' => $load,
            'mine' => $mine,
            'canRequest' => $this->canRequest($context),
            'requestOptions' => $options,
            'requestBlocked' => self::blockedReason($options, $mine['groups'] ?? []),
            'maxText' => ChangeRequestRules::MAX_TEXT_LENGTH,
        ];
    }

    /**
     * «2 h», «1 h 30 min» o «0 min».
     */
    public static function span(int $minutes): string
    {
        $minutes = max($minutes, 0);
        $hours = intdiv($minutes, 60);
        $rest = $minutes % 60;

        return $minutes === 0 ? '0 min' : trim(($hours > 0 ? $hours.' h' : '').($rest > 0 ? ' '.$rest.' min' : ''));
    }

    private function canRequest(Actor $context): bool
    {
        $user = $context->user();

        return $user !== null && $context->allows(StaffingPermission::ChangeRequestRequestOwn, Target::ownedBy((string) $user->getKey()));
    }

    /**
     * Los grupos que se ven en Mi carga y se pueden pedir: siguen a su nombre en lo vivo, no tienen una solicitud
     * abierta y su carga está en Asignación o Ajustes. Sin el permiso de pedir, 403.
     *
     * @return list<string>
     */
    private function available(Actor $context, MyChangeRequestsQuery $requests, MyLoadQuery $query): array
    {
        abort_unless($this->canRequest($context), 403);

        $user = $context->user();
        $term = $context->activeTerm();
        $teacher = Teacher::query()->where('user_id', $user->getKey())->where('is_active', true)->first();

        abort_if($term === null || $teacher === null, 404);

        $states = $requests->forTeacher($teacher, (string) $user->getKey(), $term)['groups'];

        return array_values(array_filter(
            $query->groupIds($teacher, $term),
            fn (string $id): bool => isset($states[$id]) && $states[$id]['open'] === null && ChangeRequestRules::acceptsRequests($states[$id]['load']),
        ));
    }

    /**
     * Un grupo por opción del modal: clave, materia, «Lu Ju 7:00–9:00 · C1» y, si no se puede pedir, su motivo
     * («Solicitud abierta», «Carga en Cierre»). Un grupo publicado que en lo vivo ya no es suyo (se reasignó o se
     * canceló después de publicar) no tiene estado y no se pide.
     *
     * @param  list<array{id: string, code: string, subject_name: string, campus_code: string, schedule: list<array{days: string, time: string}>}>  $groups
     * @param  array<string, array{open: mixed, load: mixed}>  $states
     * @return list<array{id: string, code: string, subject: string, detail: string, reason: ?string}>
     */
    private static function optionsFor(array $groups, array $states): array
    {
        return array_map(function (array $group) use ($states): array {
            $state = $states[$group['id']] ?? null;

            return [
                'id' => $group['id'],
                'code' => $group['code'],
                'subject' => $group['subject_name'],
                'detail' => implode(' · ', [...array_map(fn (array $line): string => $line['days'].' '.$line['time'], $group['schedule']), $group['campus_code']]),
                'reason' => match (true) {
                    $state === null => self::NOT_YOURS_ANYMORE,
                    ! ChangeRequestRules::acceptsRequests($state['load']) => 'Carga en '.($state['load']?->label() ?? 'sin abrir'),
                    $state['open'] !== null => 'Solicitud abierta',
                    default => null,
                },
            ];
        }, $groups);
    }

    /**
     * Por qué el botón «Solicitar cambio» va deshabilitado: ningún grupo, o ninguno que se pueda pedir. Null si hay uno.
     * Cuenta solo los grupos que se ven y siguen a su nombre.
     *
     * @param  list<array{id: string, code: string, subject: string, detail: string, reason: ?string}>  $options
     * @param  array<string, array{open: mixed, load: mixed}>  $states
     */
    private static function blockedReason(array $options, array $states): ?string
    {
        if (array_any($options, fn (array $option): bool => $option['reason'] === null)) {
            return null;
        }

        if ($options === []) {
            return 'Todavía no tienes grupos asignados.';
        }

        $shown = array_intersect_key($states, array_flip(array_column($options, 'id')));
        $accepting = array_filter($shown, fn (array $state): bool => ChangeRequestRules::acceptsRequests($state['load']));

        return match (true) {
            $shown === [] => 'Ninguno de tus grupos publicados sigue a tu nombre.',
            $accepting === [] => 'Los cambios se piden con la carga en Asignación o Ajustes.',
            count($accepting) === count($options) => 'Ya tienes una solicitud abierta en cada uno de tus grupos.',
            default => 'Ya tienes una solicitud abierta en cada grupo cuya carga está en Asignación o Ajustes.',
        };
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
