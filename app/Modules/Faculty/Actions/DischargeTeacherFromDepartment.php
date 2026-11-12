<?php

namespace App\Modules\Faculty\Actions;

use App\Modules\Faculty\Models\Teacher;
use App\Modules\Faculty\Models\TeacherDepartment;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * La baja del docente por el jefe de su departamento (CV8, confirmado el 12 de noviembre de 2026; reglas-de-negocio.md,
 * "Validación de catálogos"): se da de baja de ese departamento (RemoveAdscription: si era la principal, la principal
 * pasa a la adscripción más antigua que le quede) y, si ya no está adscrito a otro, queda dado de baja y su cuenta se
 * inhabilita (SetTeacherActive, que sincroniza su acceso con SyncTeacherAccess). Quién puede lo decide
 * TeacherPolicy::discharge antes de llamarla: a un docente que ocupa un puesto solo lo dan de baja el admin o la
 * Subdirección (CV-S8, supuesto por confirmar).
 *
 * Todo en una transacción; cada paso deja su bitácora y su evento. Devuelve lo de RemoveAdscription y si el docente
 * quedó dado de baja por esto (`deactivated`; falso si ya lo estaba o si le queda otra adscripción).
 */
final class DischargeTeacherFromDepartment
{
    public function __construct(
        private readonly RemoveAdscription $removeAdscription,
        private readonly SetTeacherActive $setTeacherActive,
    ) {}

    /**
     * @return array{promoted: ?TeacherDepartment, remaining: int, outcome: ?string, deactivated: bool}
     *
     * @throws ValidationException
     */
    public function handle(TeacherDepartment $adscription): array
    {
        return DB::transaction(function () use ($adscription): array {
            $removal = $this->removeAdscription->handle($adscription);
            $deactivated = false;

            if ($removal['remaining'] === 0) {
                $teacher = Teacher::query()->findOrFail($adscription->teacher_id);
                $deactivated = $teacher->is_active;
                $this->setTeacherActive->handle($teacher, false);
            }

            return [...$removal, 'deactivated' => $deactivated];
        });
    }
}
