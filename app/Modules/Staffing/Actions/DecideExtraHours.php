<?php

namespace App\Modules\Staffing\Actions;

use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\User;
use App\Modules\Faculty\Models\Teacher;
use App\Modules\Staffing\Enums\ExtraHoursDecision;
use App\Modules\Staffing\Models\TeacherExtraHoursDecision;
use App\Modules\Staffing\Queries\ExtraHoursStatusQuery;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * PL-04 (tarea 1.81): el jefe de departamento decide las horas extra de un docente en un periodo: «autorizadas como
 * honorarios» o «no autorizadas» (el docente firma el oficio de conformidad y no se le pagan; el oficio todavía no se
 * construye). Guarda las horas extra sobre las que decidió: si luego suben, la decisión se reabre como «por autorizar»
 * (supuesto por confirmar). Una fila por docente y periodo; decidir otra vez la reemplaza.
 *
 * Rechaza al docente sin horas extra y a quien tiene jefatura, subdirección o dirección (sin honorarios). Quién puede
 * decidir lo resuelve quien la llama con TeacherExtraHoursDecisionPolicy. En una transacción, con bitácora.
 */
final class DecideExtraHours
{
    public function __construct(private readonly ExtraHoursStatusQuery $statuses) {}

    /**
     * @throws ValidationException
     */
    public function handle(Teacher $teacher, Term $term, ExtraHoursDecision $decision, User $actor): TeacherExtraHoursDecision
    {
        return DB::transaction(function () use ($teacher, $term, $decision, $actor): TeacherExtraHoursDecision {
            $locked = Teacher::query()->lockForUpdate()->findOrFail($teacher->getKey());
            $status = $this->statuses->forTeacher($term, $locked->getKey());

            if (! $status->hasExtra()) {
                throw ValidationException::withMessages(['teacher' => 'El docente no tiene horas extra en este periodo.']);
            }

            if (! $status->state->isDecidable()) {
                throw ValidationException::withMessages(['teacher' => 'Quien tiene jefatura, subdirección o dirección no tiene honorarios: sus horas extra requieren el oficio del docente.']);
            }

            $record = TeacherExtraHoursDecision::query()->firstOrNew(['teacher_id' => $locked->getKey(), 'term_id' => $term->getKey()]);
            $previous = $record->exists ? $record->decision->value : null;

            $record->fill(['decision' => $decision, 'hours' => $status->extra, 'decided_by' => $actor->getKey(), 'decided_at' => now()])->save();

            activity()
                ->performedOn($record)
                ->causedBy($actor)
                ->event('extra_hours_decided')
                ->withProperties(['teacher_id' => $locked->getKey(), 'term_id' => $term->getKey(), 'decision' => $decision->value, 'hours' => $status->extra, 'previous_decision' => $previous])
                ->log(($decision === ExtraHoursDecision::Authorized ? 'Autorizó' : 'No autorizó')." {$status->extra} h extra de {$locked->name} en {$term->name}");

            return $record;
        });
    }
}
