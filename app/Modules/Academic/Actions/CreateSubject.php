<?php

namespace App\Modules\Academic\Actions;

use App\Modules\Academic\Enums\SubjectKind;
use App\Modules\Academic\Events\SubjectCreated;
use App\Modules\Academic\Models\Subject;
use App\Modules\Academic\Rules\SubjectRules;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Enums\ActivityEvent;

/**
 * Da de alta una materia activa. Recibe datos ya validados en forma (el componente) y hace cumplir
 * las reglas de SubjectRules: clave normalizada y única también entre las borradas, horas y créditos
 * no negativos, tipo de SubjectKind o vacío y un departamento que existe. Una regla rota es una
 * ValidationException sobre su campo.
 *
 * En una transacción revisa la clave y el departamento, escribe y deja bitácora; si otra sesión
 * guarda la misma clave entre la revisión y el insert, la restricción única de la base responde con
 * el mismo error. Después del commit avisa con SubjectCreated. Una materia nueva no está en ninguna
 * retícula, así que no cambia el avance de nadie.
 */
final class CreateSubject
{
    public function __construct(private readonly SubjectRules $rules) {}

    /**
     * @param  array{code: string, name: string, department_id: string, theory_hours: int, practice_hours: int, credits: int, kind?: SubjectKind|string|null, needs_lab?: bool}  $data
     *
     * @throws ValidationException
     */
    public function handle(array $data): Subject
    {
        $code = $this->rules->normalizeCode($data['code']);
        $this->rules->ensureNotNegative($data);
        $kind = $this->rules->kind($data['kind'] ?? null);

        return DB::transaction(function () use ($code, $kind, $data): Subject {
            $this->rules->ensureCodeIsFree($code);
            $this->rules->ensureDepartmentExists($data['department_id']);

            try {
                $subject = Subject::create([
                    'code' => $code,
                    'name' => $data['name'],
                    'department_id' => $data['department_id'],
                    'theory_hours' => $data['theory_hours'],
                    'practice_hours' => $data['practice_hours'],
                    'credits' => $data['credits'],
                    'kind' => $kind,
                    'needs_lab' => $data['needs_lab'] ?? false,
                    'is_active' => true,
                ]);
            } catch (UniqueConstraintViolationException) {
                throw $this->rules->codeTaken($code);
            }

            activity()
                ->performedOn($subject)
                ->event(ActivityEvent::Created)
                ->withChanges(['attributes' => $subject->only(UpdateSubject::FIELDS)])
                ->log("Dio de alta la materia {$subject->code}");

            SubjectCreated::dispatch($subject);

            return $subject;
        });
    }
}
