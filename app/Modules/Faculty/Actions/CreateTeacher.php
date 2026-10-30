<?php

namespace App\Modules\Faculty\Actions;

use App\Modules\Academic\Models\Department;
use App\Modules\Faculty\Enums\TeacherDegree;
use App\Modules\Faculty\Events\TeacherCreated;
use App\Modules\Faculty\Models\Teacher;
use App\Modules\Faculty\Rules\TeacherRules;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Spatie\Activitylog\Enums\ActivityEvent;

/**
 * Da de alta un docente activo adscrito al departamento dado, con esa adscripción como principal; sin campus,
 * porque el docente da clase en cualquier campus o modalidad (confirmado el 30 de octubre de 2026). Recibe datos ya
 * validados en forma (el componente) y hace cumplir TeacherRules: número de empleado sin espacios y único también
 * entre los borrados, nombre no vacío y grado de TeacherDegree o vacío. Quién puede, y en qué departamento, lo
 * decide TeacherPolicy (`create`) antes de llamarla.
 *
 * En una transacción revisa el número, escribe y deja bitácora; si otra sesión guarda el mismo número entre la
 * revisión y el insert, la restricción única de la base responde con el mismo error. Luego AddAdscription pone la
 * adscripción principal y SyncTeacherAccess le da usuario (username = número, contraseña por defecto y cambio
 * obligatorio) con el rol `docente`. Sin NEXUS_DEFAULT_PASSWORD falla y no deja nada a medias. Después del commit
 * avisa con TeacherCreated.
 */
final class CreateTeacher
{
    /** Lo que se escribe al dar de alta y se registra en la bitácora. */
    public const array FIELDS = ['employee_number', 'name', 'email', 'degree', 'hired_on', 'is_active'];

    public function __construct(
        private readonly TeacherRules $rules,
        private readonly AddAdscription $addAdscription,
        private readonly SyncTeacherAccess $syncTeacherAccess,
    ) {}

    /**
     * @param  array{employee_number: string, name: string, email?: ?string, degree?: TeacherDegree|string|null, hired_on?: ?string}  $data
     *
     * @throws ValidationException
     * @throws RuntimeException si falta NEXUS_DEFAULT_PASSWORD
     */
    public function handle(array $data, Department $department): Teacher
    {
        $number = $this->rules->normalizeEmployeeNumber($data['employee_number']);
        $name = $this->rules->name($data['name']);
        $degree = $this->rules->degree($data['degree'] ?? null);

        return DB::transaction(function () use ($data, $department, $number, $name, $degree): Teacher {
            $this->rules->ensureEmployeeNumberIsFree($number);

            try {
                $teacher = Teacher::create([
                    'employee_number' => $number,
                    'name' => $name,
                    'email' => $this->rules->optional($data['email'] ?? null),
                    'degree' => $degree,
                    'hired_on' => $this->rules->optional($data['hired_on'] ?? null),
                    'is_active' => true,
                ]);
            } catch (UniqueConstraintViolationException) {
                throw $this->rules->employeeNumberTaken($number);
            }

            activity()
                ->performedOn($teacher)
                ->event(ActivityEvent::Created)
                ->withChanges(['attributes' => $this->rules->logged($teacher, self::FIELDS)])
                ->log("Dio de alta al docente {$teacher->employee_number}");

            $this->addAdscription->handle($teacher, $department, primary: true);
            $this->syncTeacherAccess->handle($teacher);

            TeacherCreated::dispatch($teacher);

            return $teacher;
        });
    }
}
