<?php

namespace App\Modules\CatalogValidation\Actions;

use App\Modules\CatalogValidation\Models\CatalogReview;
use App\Modules\Faculty\Actions\InviteTeacherAccess;
use App\Modules\Faculty\Queries\TeacherAccessQuery;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Invita a activar su acceso a los docentes del departamento de una revisión de docentes validada (AC-09, confirmado
 * el 11 de noviembre de 2026): los activos adscritos a él con usuario sin contraseña, correo institucional y sin
 * invitación vigente (TeacherAccessQuery), cada uno con InviteTeacherAccess a nombre de quien validó. Con
 * `$teacherId`, solo ese docente (su correo se capturó después de validar). Los que no tienen correo institucional no
 * se invitan ni detienen a los demás (supuesto por confirmar), y a un docente en dos departamentos lo invita la
 * primera validación, porque la segunda ya lo encuentra con su invitación vigente (supuesto por confirmar).
 *
 * Si una invitación no se puede mandar (por ejemplo, el correo ya es de otro usuario), sigue con los demás. Si invitó
 * o falló alguna, deja una entrada `teachers-invited` sobre la revisión con cuántas mandó y el motivo de cada RFC que
 * falló; cada invitación deja además la suya sobre el usuario (SendAccessInvitation).
 */
final class InviteReviewTeachers
{
    public function __construct(
        private readonly TeacherAccessQuery $teachers,
        private readonly InviteTeacherAccess $inviteTeacherAccess,
    ) {}

    /**
     * @return array{invited: int, failed: array<string, string>}
     *
     * @throws RuntimeException si falta NEXUS_DEFAULT_PASSWORD
     */
    public function handle(CatalogReview $review, ?string $teacherId = null): array
    {
        $actor = $review->validator;
        $invited = 0;
        $failed = [];

        foreach ($this->teachers->awaitingInvitation($review->department_id, $teacherId) as $teacher) {
            try {
                $this->inviteTeacherAccess->handle($teacher, $actor);
                $invited++;
            } catch (ValidationException $exception) {
                $failed[$teacher->rfc] = (string) collect($exception->errors())->flatten()->first();
            }
        }

        if ($invited > 0 || $failed !== []) {
            activity()
                ->performedOn($review)
                ->causedBy($actor)
                ->event('teachers-invited')
                ->withProperties(['invited' => $invited, 'failed' => $failed])
                ->log("Invitó a {$invited} docentes de {$review->title} a activar su acceso".($failed === [] ? '' : '; '.count($failed).' no se pudieron invitar'));
        }

        return ['invited' => $invited, 'failed' => $failed];
    }
}
