<?php

namespace App\Modules\CatalogValidation\Actions;

use App\Modules\Academic\Actions\UpdateCareer;
use App\Modules\Academic\Actions\UpdateStudyPlan;
use App\Modules\Academic\Actions\UpdateSubject;
use App\Modules\CatalogValidation\Enums\Catalog;
use App\Modules\CatalogValidation\Models\CatalogReview;
use App\Modules\CatalogValidation\Rules\CatalogReviewRules;
use App\Modules\Core\Models\User;
use App\Modules\Facilities\Actions\UpdateRoom;
use App\Modules\Faculty\Actions\UpdateTeacher;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * El jefe corrige un registro de su revisión mientras no está validada (reglas-de-negocio.md, "Validación de
 * catálogos"): materias, carreras y planes de su departamento y el correo institucional de sus docentes (CV1, con
 * UpdateTeacher y las reglas de AC-06) y sus salones en cualquier campus (CV2, confirmado el 12 de noviembre de 2026),
 * con la Action de actualización del módulo dueño, que hace cumplir sus reglas y sus vetos (desactivar una materia que
 * los alumnos necesitan, por ejemplo) y deja su propia bitácora y su evento. Lo demás del docente se corrige en
 * Faculty; darlo de baja o agregarlo son DischargeReviewTeacher y AddReviewTeacher. Dar de alta sigue siendo del admin.
 * Quién puede lo decide CatalogReviewPolicy::correct antes de llamarla (y, en docentes,
 * TeacherPolicy::updateInstitutionalEmail).
 *
 * Quita lo que ata el registro al SII o a su departamento (CV-S3, supuesto por confirmar): la clave, el
 * departamento, la carrera dueña de un plan y el campus de un salón, que sigue a su edificio (UpdateRoom, CV-S10); de un
 * docente solo pasa el correo institucional. Tampoco activa ni desactiva carreras ni planes, que no tienen veto de
 * desactivación: si quiere darlos de baja, les pone la observación «Inactivo» y lo resuelve el admin. Las materias sí,
 * porque SubjectDeactivating las veta. Todo en una transacción con
 * la revisión bloqueada (así no se cruza con ValidateReview): exige que no esté validada y que el registro sea
 * suyo, delega y, si algo cambió, marca el registro como revisado (MarkRecordsReviewed, CV7) y pasa la revisión a en
 * revisión (StartReview, CV-S4). Guardar sin cambios no es una corrección: no marca (CV-S7, supuesto por confirmar).
 */
final class CorrectRecord
{
    public function __construct(
        private readonly CatalogReviewRules $rules,
        private readonly StartReview $startReview,
        private readonly MarkRecordsReviewed $markRecords,
        private readonly UpdateSubject $updateSubject,
        private readonly UpdateCareer $updateCareer,
        private readonly UpdateStudyPlan $updateStudyPlan,
        private readonly UpdateRoom $updateRoom,
        private readonly UpdateTeacher $updateTeacher,
    ) {}

    /**
     * @param  array<string, mixed>  $data  lo que acepta la Action del módulo dueño
     *
     * @throws ValidationException
     */
    public function handle(CatalogReview $review, Model $record, array $data, User $actor): Model
    {
        $data = $review->catalog === Catalog::Teachers
            ? Arr::only($data, ['institutional_email'])
            : Arr::except($data, self::lockedFields($review->catalog));

        return DB::transaction(function () use ($review, $record, $data, $actor): Model {
            $review->setRawAttributes($review->newQuery()->lockForUpdate()->findOrFail($review->getKey())->getAttributes(), sync: true);

            $this->rules->ensureOpen($review);
            $this->rules->ensureContains($review, $record);

            $record->discardChanges();
            $record->syncChanges();

            match ($review->catalog) {
                Catalog::Subjects => $this->updateSubject->handle($record, $data),
                Catalog::Careers => $this->updateCareer->handle($record, $data),
                Catalog::StudyPlans => $this->updateStudyPlan->handle($record, $data),
                Catalog::Rooms => $this->updateRoom->handle($record, $data),
                Catalog::Teachers => $this->updateTeacher->handle($record, $data),
            };

            if ($record->wasChanged()) {
                $this->markRecords->handle($review, [$record->getKey()], $actor);
                $this->startReview->handle($review, $actor);
            }

            return $record;
        });
    }

    /**
     * Lo que el jefe no cambia al corregir (CV-S3).
     *
     * @return list<string>
     */
    private static function lockedFields(Catalog $catalog): array
    {
        return match ($catalog) {
            Catalog::Subjects => ['code', 'department_id'],
            Catalog::Careers => ['code', 'department_id', 'is_active'],
            Catalog::StudyPlans => ['code', 'career_id', 'is_active'],
            Catalog::Rooms => ['code', 'department_id', 'campus_id'],
            Catalog::Teachers => [],
        };
    }
}
