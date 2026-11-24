<?php

namespace App\Modules\Staffing\Actions\Selection;

use App\Modules\Core\Models\User;
use App\Modules\Faculty\Actions\InviteTeacherAccess;
use App\Modules\Staffing\Models\Selection;
use Illuminate\Validation\ValidationException;

/**
 * SEL-13 (confirmado el 24 de noviembre de 2026): al publicar la selección, invita a activar su acceso a cada docente
 * de la cola que no lo tiene ni tiene una invitación vigente (InviteTeacherAccess::forSelection: al institucional si su
 * departamento ya validó el catálogo de docentes, AC-09; si no, al personal). El correo sale de la cola después del
 * commit de cada invitación (AccessInvitation). Una que no se puede mandar no detiene a las demás.
 *
 * Deja una entrada `selection_teachers_invited` sobre la selección con cuántas mandó, cuántos ya tenían acceso o una
 * invitación vigente, cuántos no tienen correo y el motivo de cada RFC que no se pudo invitar. El monitor del admin
 * muestra el estado de cada uno (SelectionMonitorQuery). Lo corre InviteSelectionTeachersJob.
 */
final class InviteSelectionTeachers
{
    public function __construct(private readonly InviteTeacherAccess $invite) {}

    /**
     * @return array{invited: int, skipped: int, without_email: int, failed: array<string, string>}
     */
    public function handle(Selection $selection, ?User $actor = null): array
    {
        $result = ['invited' => 0, 'skipped' => 0, 'without_email' => 0, 'failed' => []];

        foreach ($selection->entries()->whereNotNull('teacher_id')->with('teacher')->orderBy('position')->get() as $entry) {
            if ($entry->teacher === null) {
                continue;
            }

            try {
                $this->invite->forSelection($entry->teacher, $actor) === null ? $result['skipped']++ : $result['invited']++;
            } catch (ValidationException $exception) {
                $reason = (string) collect($exception->errors())->flatten()->first();

                match ($reason) {
                    InviteTeacherAccess::HAS_ACCESS => $result['skipped']++,
                    InviteTeacherAccess::NO_EMAIL => $result['without_email']++,
                    default => $result['failed'][$entry->teacher->rfc] = $reason,
                };
            }
        }

        activity()
            ->performedOn($selection)
            ->causedBy($actor)
            ->event('selection_teachers_invited')
            ->withProperties($result)
            ->log('Invitó a '.($result['invited'] === 1 ? '1 docente' : "{$result['invited']} docentes").' de la cola a activar su acceso'
                .($result['without_email'] > 0 ? "; {$result['without_email']} sin correo" : '')
                .($result['failed'] !== [] ? '; '.count($result['failed']).' no se pudieron invitar' : ''));

        return $result;
    }
}
