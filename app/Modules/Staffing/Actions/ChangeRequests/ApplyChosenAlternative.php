<?php

namespace App\Modules\Staffing\Actions\ChangeRequests;

use App\Modules\Core\Models\User;
use App\Modules\Scheduling\Actions\Sessions\ReplaceGroupSessions;
use App\Modules\Scheduling\Models\Group;
use App\Modules\Scheduling\Models\GroupSession;
use App\Modules\Staffing\Enums\ChangeRequestStatus;
use App\Modules\Staffing\Models\ChangeRequest;
use App\Modules\Staffing\Support\ChangeAlternatives;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * CR-04 (tarea 8.7; supuesto por confirmar): aplica de una vez la alternativa del motor que eligió el jefe (CR-03) en
 * una solicitud aprobada. La aplica quien hoy la marca aplicada según su tipo (ChangeRequestPolicy::apply, que esta
 * Action vuelve a revisar): como el jefe ya aprobó la cadena completa, quien aplica escribe también los grupos de otras
 * carreras de esa alternativa, solo esos y solo esas sesiones.
 *
 * Es el flujo de MarkChangeRequestApplied (los candados de LocksChangeRequestScope en su orden y guard(), la solicitud
 * aprobada, bitácora, el aviso al solicitante y el regreso de la carga a asignación) con la escritura antes de marcarla.
 * Que la alternativa siga vigente se revisa como al elegirla, en dos tiempos: lo pesado sin candados
 * (ChooseChangeAlternative::isCurrent: la huella de la entrada y sin choques duros nuevos con el validador) y lo barato
 * con ellos (ChooseChangeAlternative::sessionsUnchanged: sus grupos y sesiones siguen igual). Luego escribe las sesiones
 * de todos sus grupos con ReplaceGroupSessions, que vuelve a revisar las exclusiones de la base (su bitácora por grupo
 * dice de qué solicitud viene). Si algo cambió, no escribe ni la marca: «Esto cambió…», y quien
 * aplica la acomoda a mano en el editor. Guarda en `alternatives.applied` quién, cuándo y las sesiones de antes de cada
 * grupo (ReplaceGroupSessions les da ids nuevos), para que la pantalla diga qué cambió ya aplicada. Después del commit avisa a cada docente cuyos grupos cambiaron de horario
 * (decidido por el usuario el 18 de noviembre de 2026).
 */
final class ApplyChosenAlternative
{
    public const string STALE = 'Esto cambió desde que se aprobó la alternativa: el horario de esos grupos o lo que los rodea ya no es el mismo. Acomódala a mano en el editor de horarios y luego márcala como aplicada.';

    public function __construct(
        private readonly MarkChangeRequestApplied $mark,
        private readonly ChooseChangeAlternative $choose,
        private readonly ReplaceGroupSessions $replace,
        private readonly NotifyChangeRequest $notify,
    ) {}

    /**
     * @throws ValidationException si la solicitud no está aprobada, no tiene una alternativa elegida o ya no está vigente
     */
    public function handle(ChangeRequest $request, User $actor): AppliedChangeRequest
    {
        Gate::forUser($actor)->authorize('apply', $request);
        $request->refresh();

        if ($request->status !== ChangeRequestStatus::Approved) {
            throw ValidationException::withMessages(['status' => 'La solicitud no está aprobada.']);
        }

        if (! $this->choose->isCurrent($request, self::chosen($request))) {
            throw ValidationException::withMessages(['alternative' => self::STALE]);
        }

        $moved = [];

        $result = $this->mark->apply($request, $actor, function (ChangeRequest $locked) use ($actor, &$moved): void {
            $item = self::chosen($locked);

            if (! $this->choose->sessionsUnchanged($item)) {
                throw ValidationException::withMessages(['alternative' => self::STALE]);
            }

            $groups = Group::query()->with(['subject:id,code,name', 'sessions.room:id,code'])->whereIn('id', array_column($item['groups'], 'group_id'))->get()->keyBy('id');
            $before = $groups->map(fn (Group $group): string => ChangeAlternatives::line(ChangeAlternatives::live($group)));
            $letter = ChangeAlternatives::letter($locked->alternatives, $item['id']);

            $written = $this->replace->handle(ChangeAlternatives::candidates($item, replacing: false), $actor,
                "con la alternativa {$letter} de la solicitud de cambio", ['change_request_id' => $locked->getKey()]);

            foreach ($item['groups'] as $entry) {
                $group = $groups[$entry['group_id']] ?? null;

                if ($group !== null && isset($written['groups'][$group->id])) {
                    $moved[] = ['teacher_id' => $group->teacher_id, 'label' => ChangeAlternatives::label($group), 'subject' => $group->subject?->name,
                        'from' => $before[$group->id], 'to' => ChangeAlternatives::line($entry['sessions'])];
                }
            }

            $alternatives = $locked->alternatives;
            $alternatives['applied'] = [
                'applied_by' => $actor->getKey(),
                'applied_at' => now()->toIso8601String(),
                'before' => $groups->map(fn (Group $group): array => $group->sessions->map(fn (GroupSession $session): array => [
                    'id' => $session->id,
                    'day' => $session->day,
                    'starts_at' => substr((string) $session->starts_at, 0, 5),
                    'ends_at' => substr((string) $session->ends_at, 0, 5),
                    'kind' => $session->kind->value,
                    'room_id' => $session->room_id,
                    'room' => $session->room?->code,
                ])->values()->all())->all(),
            ];
            $locked->forceFill(['alternatives' => $alternatives]);
        });

        $this->notify->teachersOfMovedGroups($result->request, $moved);

        return $result;
    }

    /**
     * La alternativa que eligió el jefe.
     *
     * @return array<string, mixed>
     *
     * @throws ValidationException si la solicitud no se aprobó con una alternativa del motor
     */
    private static function chosen(ChangeRequest $request): array
    {
        return ChangeAlternatives::chosen($request->alternatives)
            ?? throw ValidationException::withMessages(['alternative' => 'La solicitud no se aprobó con una alternativa del motor: acomódala en el editor de horarios y márcala como aplicada.']);
    }
}
