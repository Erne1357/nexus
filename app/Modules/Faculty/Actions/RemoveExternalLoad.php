<?php

namespace App\Modules\Faculty\Actions;

use App\Modules\Faculty\Events\ExternalLoadChanged;
use App\Modules\Faculty\Models\TeacherExternalLoad;
use App\Modules\Faculty\Rules\ExternalLoadRules;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Enums\ActivityEvent;

/**
 * Quita unas horas fuera del ITCJ: borrado lógico, con su valor en la bitácora. Dejan de restarse de lo que se le
 * puede asignar. Quién puede lo decide TeacherPolicy (`manageExternalLoads`) antes de llamarla. Una ya quitada es un
 * error en `load`. Después del commit avisa con ExternalLoadChanged.
 */
final class RemoveExternalLoad
{
    public function __construct(private readonly ExternalLoadRules $rules) {}

    /**
     * @throws ValidationException
     */
    public function handle(TeacherExternalLoad $load): void
    {
        DB::transaction(function () use ($load): void {
            $locked = TeacherExternalLoad::query()->lockForUpdate()->find($load->getKey())
                ?? throw ValidationException::withMessages(['load' => 'Esas horas ya se quitaron.']);

            $locked->delete();

            activity()
                ->performedOn($locked)
                ->event(ActivityEvent::Deleted)
                ->withChanges(['old' => $this->rules->logged($locked)])
                ->withProperties(['teacher_id' => $locked->teacher_id, 'term_id' => $locked->term_id])
                ->log('Quitó unas horas fuera del ITCJ del docente');

            ExternalLoadChanged::dispatch($locked);
        });
    }
}
