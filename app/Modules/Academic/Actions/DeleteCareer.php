<?php

namespace App\Modules\Academic\Actions;

use App\Modules\Academic\Events\CareerDeleted;
use App\Modules\Academic\Models\Career;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Enums\ActivityEvent;

/**
 * Borra (borrado lógico) una carrera. Solo si no tiene planes de estudio, ni siquiera borrados,
 * porque los alumnos referencian sus planes; si no, una ValidationException sobre `career` que dice
 * cuántos. Si pasa, cierra los alcances de sus coordinadores con CloseRoleScopes (S2), borra y deja
 * bitácora con los datos que tenía. Después del commit avisa con CareerDeleted. Su oferta por campus
 * (`career_campus`) se conserva: la carrera borrada ya no sale en ningún catálogo.
 *
 * Vuelve a leer la carrera con bloqueo, así que los planes que cuenta son los de ese momento. Quién
 * puede borrar lo decide CareerPolicy antes de llamarla.
 */
final class DeleteCareer
{
    public function __construct(private readonly CloseRoleScopes $closeRoleScopes) {}

    /**
     * @throws ValidationException
     */
    public function handle(Career $career): Career
    {
        return DB::transaction(function () use ($career): Career {
            $career->setRawAttributes($career->newQuery()->lockForUpdate()->findOrFail($career->getKey())->getAttributes(), sync: true);

            $plans = $career->studyPlans()->withTrashed()->count();

            if ($plans > 0) {
                throw ValidationException::withMessages([
                    'career' => 'No se puede borrar: tiene '.$plans.($plans === 1 ? ' plan de estudio' : ' planes de estudio').', aunque estén borrados.',
                ]);
            }

            $this->closeRoleScopes->handle('career_id', $career->id);

            $career->delete();

            activity()
                ->performedOn($career)
                ->event(ActivityEvent::Deleted)
                ->withChanges(['old' => $career->only(['code', 'name', 'department_id', 'is_active'])])
                ->log("Borró la carrera {$career->code}");

            CareerDeleted::dispatch($career);

            return $career;
        });
    }
}
