<?php

namespace App\Modules\Staffing\Actions\ChangeRequests;

use App\Modules\Core\Models\User;
use App\Modules\Scheduling\Actions\Sessions\ReplaceGroupSessions;
use App\Modules\Scheduling\Models\Group;
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
 * aprobada, bitácora, el aviso al solicitante y el regreso de la carga a asignación) con la escritura antes de marcarla:
 * con los candados tomados revisa que la alternativa siga vigente (ChooseChangeAlternative::isCurrent: la huella de la
 * entrada y sin choques duros con el validador) y escribe las sesiones de todos sus grupos con ReplaceGroupSessions
 * (su bitácora por grupo dice de qué solicitud viene). Si algo cambió, no escribe ni la marca: «Esto cambió…», y quien
 * aplica la acomoda a mano en el editor. Después del commit avisa a cada docente cuyos grupos cambiaron de horario
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
        $moved = [];

        $result = $this->mark->apply($request, $actor, function (ChangeRequest $locked) use ($actor, &$moved): void {
            $item = ChangeAlternatives::chosen($locked->alternatives);

            if ($item === null) {
                throw ValidationException::withMessages(['alternative' => 'La solicitud no se aprobó con una alternativa del motor: acomódala en el editor de horarios y márcala como aplicada.']);
            }

            if (! $this->choose->isCurrent($locked, $item)) {
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
        });

        $this->notify->teachersOfMovedGroups($result->request, $moved);

        return $result;
    }
}
