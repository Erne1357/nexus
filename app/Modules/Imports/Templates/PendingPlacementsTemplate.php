<?php

namespace App\Modules\Imports\Templates;

use App\Modules\Academic\Models\Specialty;
use App\Modules\Academic\Models\StudyPlanSubject;
use App\Modules\Academic\Models\StudyPlanSubjectPending;
use App\Modules\Academic\Models\Subject;
use Illuminate\Database\Eloquent\Model;

/**
 * «Materias pendientes de retícula» (D18 y D19 de la migración del SII): los campos de `subjects` sin semestre. Una
 * materia del SII sin semestre y sin materia con el mismo nombre de la que tomarlo no se pone en la retícula de su
 * plan, que nunca queda sin semestre: queda como pendiente (`study_plan_subject_pendings`) hasta que Servicios
 * Escolares le asigne uno. La materia sí debe existir (el kárdex la necesita): se da de alta o se cambia por su
 * clave igual que en `subjects` (UpsertsSubjects), con su especialidad si viene.
 *
 * Si el plan ya tiene la materia en su retícula, avisa y no crea pendiente; una materia quitada de la retícula es
 * error, como en `subjects`. Repetir el renglón no duplica: el pendiente es único por plan y materia, y uno ya
 * resuelto no se reabre (su materia ya está en la retícula). La especialidad del archivo se pone al crear el
 * pendiente, y en uno sin resolver se actualiza si el archivo la trae (supuesto por confirmar); lo mismo la retícula
 * del SII de la que salió (`sii_grid`, «carrera/retícula»), que Servicios Escolares ve al validarla. Nunca desactiva.
 * `position` (renglón del SII, de 1 a 99, opcional; D21) se guarda en el pendiente para no perder el dato; al
 * resolverlo, la materia queda al final del semestre que se le asigne (supuesto por confirmar). `suggested_semester`
 * (opcional, de 1 en adelante) es el semestre que la migración sugiere en una retícula viva (D18, confirmado el 10 de
 * noviembre de 2026). Solo la migración lo calcula, así que en un pendiente sin resolver se escribe tal cual: vacío lo
 * borra (la retícula pasó a histórica o ya no hay de dónde sugerirlo).
 */
class PendingPlacementsTemplate extends ImportTemplate
{
    use UpsertsSubjects;

    /** @var array<string, true> pendientes cuya última fila aplicada solo cambió la materia o la especialidad */
    private array $subjectOnly = [];

    public function key(): string
    {
        return 'pending_placements';
    }

    public function label(): string
    {
        return 'Materias pendientes de retícula';
    }

    public function fields(): array
    {
        return [
            'plan' => 'clave de plan',
            'code' => 'clave de materia',
            'name' => 'nombre',
            'theory_hours' => 'horas teoría',
            'practice_hours' => 'horas práctica',
            'credits' => 'créditos',
            'department' => 'departamento',
            'specialty' => 'especialidad',
            'specialty_name' => 'nombre de especialidad',
            'kind' => 'tipo',
            'sii_grid' => 'retícula del sii',
            'position' => 'renglón',
            'suggested_semester' => 'semestre sugerido',
        ];
    }

    public function naturalKey(): array
    {
        return ['plan', 'code'];
    }

    public function model(): string
    {
        return StudyPlanSubjectPending::class;
    }

    public function deactivatesMissing(): bool
    {
        return false;
    }

    public function consistentAcrossRows(): ?array
    {
        return ['group' => ['code'], 'errors' => ['credits', 'theory_hours', 'practice_hours', 'department'], 'warnings' => ['name']];
    }

    public function twinsAcrossRows(): ?array
    {
        return ['name' => 'name', 'key' => 'code'];
    }

    public function rules(): array
    {
        return [
            ...$this->subjectRules(),
            'sii_grid' => ['nullable', 'string', 'max:50'],
            'position' => ['nullable', 'integer', 'min:1', 'max:99'],
            'suggested_semester' => ['nullable', 'integer', 'min:1', 'max:32767'],
        ];
    }

    public function normalize(array $row): array
    {
        return $this->normalizeSubjectFields(parent::normalize($row), [], ['position', 'suggested_semester']);
    }

    /**
     * Escribe la materia, la especialidad si viene y el pendiente. Devuelve el pendiente o, si el plan ya tiene la
     * materia en su retícula, ese lugar sin tocarlo; wroteAlongside() dice si la materia o la especialidad cambió.
     */
    public function apply(array $row, ImportContext $context): Model
    {
        $plan = $this->plan($row['plan']);
        $subject = $this->upsertSubject($row);
        $specialty = $this->upsertSpecialty($row, $plan);

        $placement = StudyPlanSubject::query()->where(['study_plan_id' => $plan->getKey(), 'subject_id' => $subject->getKey()])->first();
        $target = $placement ?? StudyPlanSubjectPending::query()->firstOrNew(['study_plan_id' => $plan->getKey(), 'subject_id' => $subject->getKey()]);

        if ($placement === null && $target->resolved_at === null) {
            $target->fill([
                'specialty_id' => $specialty?->getKey() ?? $target->specialty_id,
                'sii_grid' => $row['sii_grid'] ?? $target->sii_grid,
                'position' => $row['position'] ?? $target->position,
                'suggested_semester' => $row['suggested_semester'],
            ])->save();
        }

        unset($this->subjectOnly[$target->getKey()]);

        if (! $target->wasRecentlyCreated && ! $target->wasChanged()
            && ($subject->wasRecentlyCreated || $subject->wasChanged() || $specialty?->wasRecentlyCreated || $specialty?->wasChanged())) {
            $this->subjectOnly[$target->getKey()] = true;
        }

        return $target;
    }

    public function wroteAlongside(Model $model): bool
    {
        return isset($this->subjectOnly[$model->getKey()]);
    }

    protected function check(array $row, ImportContext $context, RowIssues $issues): RowIssues
    {
        $plan = $this->plan($row['plan']);
        $issues = $this->checkPlanAndDepartment($row, $issues);
        $subject = Subject::withTrashed()->where('code', $row['code'])->first(['id', 'is_active', 'deleted_at']);

        if ($subject?->trashed()) {
            return $issues->withError('code', "Hay una materia borrada con la clave {$row['code']}; una importación no la restaura.");
        }

        if ($subject !== null && ! $subject->is_active) {
            $issues = $issues->withWarning('code', "La materia {$row['code']} está inactiva: se actualizan sus datos, pero sigue inactiva.");
        }

        $issues = $this->checkSubjectTwin($row, $issues);

        if ($plan === null) {
            return $issues;
        }

        if ($subject !== null) {
            $placed = StudyPlanSubject::withTrashed()->where(['study_plan_id' => $plan->getKey(), 'subject_id' => $subject->getKey()])->first(['deleted_at']);

            if ($placed?->trashed()) {
                $issues = $issues->withError('code', "La materia {$row['code']} se quitó de la retícula del plan {$row['plan']}; una importación no la vuelve a poner.");
            } elseif ($placed !== null) {
                $issues = $issues->withWarning('code', "La materia {$row['code']} ya está en la retícula del plan {$row['plan']}: no se crea pendiente.");
            }
        }

        $specialty = $row['specialty'] === null ? null : Specialty::withTrashed()->where(['study_plan_id' => $plan->getKey(), 'code' => $row['specialty']])->first(['id', 'is_active', 'deleted_at']);

        if ($specialty?->trashed()) {
            $issues = $issues->withError('specialty', "La especialidad {$row['specialty']} del plan {$row['plan']} está borrada; una importación no la restaura.");
        } elseif ($specialty !== null && ! $specialty->is_active) {
            $issues = $issues->withWarning('specialty', "La especialidad {$row['specialty']} del plan {$row['plan']} está inactiva.");
        }

        return $issues;
    }
}
