<?php

namespace App\Modules\Academic\Actions;

use App\Modules\Academic\Enums\RequiredSpace;
use App\Modules\Academic\Events\SubjectUpdated;
use App\Modules\Academic\Models\Subject;
use App\Modules\Core\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Enums\ActivityEvent;

/**
 * Marca el tipo de espacio que pide una materia (1.33); queda en la materia para los periodos siguientes. Con
 * laboratorio, `needs_lab` queda encendido (HC-06); con aula o sala de cómputo no lo toca. Quién puede lo decide
 * SubjectPolicy antes de llamarla. Vuelve a leer la materia con bloqueo; si nada cambió, no escribe ni registra.
 */
final class SetSubjectRequiredSpace
{
    private const array LOGGED = ['required_space', 'needs_lab'];

    public function handle(Subject $subject, RequiredSpace $space, User $actor): Subject
    {
        return DB::transaction(function () use ($subject, $space, $actor): Subject {
            $subject->setRawAttributes($subject->newQuery()->lockForUpdate()->findOrFail($subject->getKey())->getAttributes(), sync: true);
            $old = $subject->only(self::LOGGED);

            $subject->required_space = $space;

            if ($space === RequiredSpace::Laboratorio) {
                $subject->needs_lab = true;
            }

            $changed = array_keys($subject->getDirty());

            if ($changed === []) {
                return $subject;
            }

            $subject->save();

            activity()
                ->performedOn($subject)
                ->causedBy($actor)
                ->event(ActivityEvent::Updated)
                ->withChanges([
                    'attributes' => $subject->only($changed),
                    'old' => Arr::only($old, $changed),
                ])
                ->log("Marcó la materia {$subject->code} como de tipo de espacio {$space->label()}");

            SubjectUpdated::dispatch($subject, $changed);

            return $subject;
        });
    }
}
