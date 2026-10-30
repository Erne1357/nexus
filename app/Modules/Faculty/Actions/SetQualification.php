<?php

namespace App\Modules\Faculty\Actions;

use App\Modules\Academic\Models\Subject;
use App\Modules\Core\Models\User;
use App\Modules\Faculty\Enums\QualificationLevel;
use App\Modules\Faculty\Events\QualificationChanged;
use App\Modules\Faculty\Models\Teacher;
use App\Modules\Faculty\Models\TeacherSubjectQualification;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Enums\ActivityEvent;

/**
 * Declara que un docente puede dar una materia, como apto o preferente (S17, supuesto por confirmar): crea el
 * perfil, cambia el nivel o las notas del que ya tiene o restaura el que se había quitado. Quién puede lo decide
 * TeacherPolicy (`manageQualification`, con la materia) antes de llamarla.
 *
 * En una transacción bloquea al docente y deja en la bitácora el nivel anterior y el nuevo. Si nada cambió, no
 * escribe, no registra ni avisa; si cambió, avisa con QualificationChanged después del commit.
 */
final class SetQualification
{
    public function handle(Teacher $teacher, Subject $subject, QualificationLevel $level, User $by, ?string $notes = null): TeacherSubjectQualification
    {
        $notes = filled($notes) ? trim($notes) : null;

        return DB::transaction(function () use ($teacher, $subject, $level, $by, $notes): TeacherSubjectQualification {
            $locked = Teacher::query()->lockForUpdate()->findOrFail($teacher->getKey());

            $existing = TeacherSubjectQualification::withTrashed()
                ->where('teacher_id', $locked->getKey())
                ->where('subject_id', $subject->getKey())
                ->first();

            if ($existing === null) {
                $qualification = TeacherSubjectQualification::create([
                    'teacher_id' => $locked->getKey(),
                    'subject_id' => $subject->getKey(),
                    'level' => $level,
                    'qualified_by' => $by->getKey(),
                    'notes' => $notes,
                ]);

                return $this->log($qualification, ActivityEvent::Created, "Declaró la materia {$subject->code} en el perfil del docente {$locked->employee_number}", attributes: $this->logged($qualification));
            }

            $old = $this->logged($existing);
            $restoring = $existing->trashed();

            if (! $restoring && $existing->level === $level && $existing->notes === $notes) {
                return $existing;
            }

            $existing->forceFill(['level' => $level, 'notes' => $notes, 'qualified_by' => $by->getKey()]);
            $existing->deleted_at = null;
            $existing->save();

            return $this->log(
                $existing,
                $restoring ? ActivityEvent::Created : ActivityEvent::Updated,
                ($restoring ? 'Volvió a declarar' : 'Cambió').' la materia '.$subject->code." en el perfil del docente {$locked->employee_number}",
                attributes: $this->logged($existing),
                old: $restoring ? null : $old,
            );
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  array<string, mixed>|null  $old
     */
    private function log(TeacherSubjectQualification $qualification, ActivityEvent $event, string $message, array $attributes, ?array $old = null): TeacherSubjectQualification
    {
        activity()
            ->performedOn($qualification)
            ->event($event)
            ->withChanges(array_filter(['attributes' => $attributes, 'old' => $old], fn (?array $value): bool => $value !== null))
            ->withProperties(['teacher_id' => $qualification->teacher_id])
            ->log($message);

        QualificationChanged::dispatch($qualification);

        return $qualification;
    }

    /**
     * @return array{subject_id: string, level: string, notes: ?string}
     */
    private function logged(TeacherSubjectQualification $qualification): array
    {
        return ['subject_id' => $qualification->subject_id, 'level' => $qualification->level->value, 'notes' => $qualification->notes];
    }
}
