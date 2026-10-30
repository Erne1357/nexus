<?php

namespace App\Modules\Faculty\Actions;

use App\Modules\Faculty\Events\PositionChanged;
use App\Modules\Faculty\Models\TeacherPosition;
use App\Modules\Faculty\Rules\PositionRules;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Enums\ActivityEvent;

/**
 * Quita una plaza de un docente: borrado lógico, con su valor en la bitácora. Dejan de contar sus horas base. Quién
 * puede lo decide TeacherPolicy (`managePositions`) antes de llamarla. Una plaza ya quitada es un error en
 * `position`. Después del commit avisa con PositionChanged.
 */
final class RemovePosition
{
    public function __construct(private readonly PositionRules $rules) {}

    /**
     * @throws ValidationException
     */
    public function handle(TeacherPosition $position): void
    {
        DB::transaction(function () use ($position): void {
            $locked = TeacherPosition::query()->lockForUpdate()->find($position->getKey())
                ?? throw ValidationException::withMessages(['position' => 'Esa plaza ya se quitó.']);

            $locked->delete();

            activity()
                ->performedOn($locked)
                ->event(ActivityEvent::Deleted)
                ->withChanges(['old' => $this->rules->logged($locked)])
                ->withProperties(['teacher_id' => $locked->teacher_id])
                ->log('Quitó una plaza del docente');

            PositionChanged::dispatch($locked);
        });
    }
}
