<?php

namespace App\Modules\Faculty\Actions;

use App\Modules\Faculty\Events\PositionChanged;
use App\Modules\Faculty\Models\Teacher;
use App\Modules\Faculty\Models\TeacherPosition;
use App\Modules\Faculty\Rules\PositionRules;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Enums\ActivityEvent;

/**
 * Agrega una plaza a un docente: tipo, horas base y vigencia (PositionRules: S3, supuesto por confirmar). Un
 * docente puede tener varias vigentes a la vez (confirmado el 30 de octubre de 2026). Quién puede lo decide
 * TeacherPolicy (`managePositions`) antes de llamarla.
 *
 * En una transacción bloquea al docente, escribe, deja bitácora sobre la plaza (con el docente en las propiedades)
 * y, después del commit, avisa con PositionChanged.
 */
final class AddPosition
{
    public function __construct(private readonly PositionRules $rules) {}

    /**
     * @param  array{kind?: string, base_hours?: int|string|null, valid_from?: ?string, valid_to?: ?string}  $data
     *
     * @throws ValidationException
     */
    public function handle(Teacher $teacher, array $data): TeacherPosition
    {
        $data = $this->rules->normalize($data);

        return DB::transaction(function () use ($teacher, $data): TeacherPosition {
            $locked = Teacher::query()->lockForUpdate()->findOrFail($teacher->getKey());

            $position = TeacherPosition::create(['teacher_id' => $locked->getKey(), ...$data]);

            activity()
                ->performedOn($position)
                ->event(ActivityEvent::Created)
                ->withChanges(['attributes' => $this->rules->logged($position)])
                ->withProperties(['teacher_id' => $locked->getKey()])
                ->log("Agregó una plaza de {$data['kind']->label()} al docente {$locked->employee_number}");

            PositionChanged::dispatch($position);

            return $position;
        });
    }
}
