<?php

namespace App\Modules\Faculty\Actions;

use App\Modules\Faculty\Enums\TeacherDegree;
use App\Modules\Faculty\Events\TeacherUpdated;
use App\Modules\Faculty\Models\Teacher;
use App\Modules\Faculty\Rules\TeacherRules;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Enums\ActivityEvent;

/**
 * Cambia los datos de un docente: nombre, correo, grado y fecha de ingreso, solo las claves que recibe. Quién puede
 * lo decide TeacherPolicy (`update`) antes de llamarla. Hace cumplir las mismas reglas que CreateTeacher
 * (TeacherRules).
 *
 * No cambia el número de empleado ni el estado: el número es el usuario del docente y la clave del SII (supuesto
 * por confirmar: no se edita), y la baja es SetTeacherActive, que además sincroniza su acceso.
 *
 * En una transacción vuelve a leer al docente con bloqueo (`lockForUpdate`), así el valor anterior de la bitácora
 * es el confirmado aunque `$teacher` llegue viejo, y lo deja en `$teacher`. Si nada cambió, no escribe, no registra
 * ni avisa; si cambió, deja en la bitácora el valor anterior y el nuevo y, después del commit, avisa con
 * TeacherUpdated.
 */
final class UpdateTeacher
{
    /** Lo que se edita y se registra en la bitácora. */
    public const array FIELDS = ['name', 'email', 'degree', 'hired_on'];

    public function __construct(private readonly TeacherRules $rules) {}

    /**
     * @param  array{name?: string, email?: ?string, degree?: TeacherDegree|string|null, hired_on?: ?string}  $data
     *
     * @throws ValidationException
     */
    public function handle(Teacher $teacher, array $data): Teacher
    {
        $data = Arr::only($data, self::FIELDS);

        if (array_key_exists('name', $data)) {
            $data['name'] = $this->rules->name($data['name']);
        }

        if (array_key_exists('degree', $data)) {
            $data['degree'] = $this->rules->degree($data['degree']);
        }

        foreach (['email', 'hired_on'] as $field) {
            if (array_key_exists($field, $data)) {
                $data[$field] = $this->rules->optional($data[$field]);
            }
        }

        return DB::transaction(function () use ($teacher, $data): Teacher {
            $teacher->setRawAttributes($teacher->newQuery()->lockForUpdate()->findOrFail($teacher->getKey())->getAttributes(), sync: true);
            $old = $this->rules->logged($teacher, self::FIELDS);

            $teacher->fill($data);
            $changed = array_keys($teacher->getDirty());

            if ($changed === []) {
                return $teacher;
            }

            $teacher->save();

            activity()
                ->performedOn($teacher)
                ->event(ActivityEvent::Updated)
                ->withChanges([
                    'attributes' => $this->rules->logged($teacher, $changed),
                    'old' => Arr::only($old, $changed),
                ])
                ->log("Actualizó al docente {$teacher->employee_number}");

            TeacherUpdated::dispatch($teacher, $changed);

            return $teacher;
        });
    }
}
