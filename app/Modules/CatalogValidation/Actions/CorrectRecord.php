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
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * El jefe corrige un registro de su revisión mientras no está validada (reglas-de-negocio.md, "Validación de
 * catálogos"): materias, carreras y planes de su departamento (CV1) y sus salones en el campus de la revisión
 * (CV2), con la Action de actualización del módulo dueño, que hace cumplir sus reglas y sus vetos (desactivar
 * una materia que los alumnos necesitan, por ejemplo) y deja su propia bitácora y su evento. Los docentes no:
 * se corrigen en Faculty, que ya los abre a los jefes. Dar de alta sigue siendo del admin. Quién puede lo decide
 * CatalogReviewPolicy::correct antes de llamarla.
 *
 * Quita lo que ata el registro al SII o a su departamento (CV-S3, supuesto por confirmar): la clave, el
 * departamento, la carrera dueña de un plan y el edificio y el campus de un salón. Todo en una transacción con
 * la revisión bloqueada (así no se cruza con ValidateReview): exige que no esté validada y que el registro sea
 * suyo, delega y, si algo cambió, pasa la revisión a en revisión (StartReview, CV-S4).
 */
final class CorrectRecord
{
    public function __construct(
        private readonly CatalogReviewRules $rules,
        private readonly StartReview $startReview,
        private readonly UpdateSubject $updateSubject,
        private readonly UpdateCareer $updateCareer,
        private readonly UpdateStudyPlan $updateStudyPlan,
        private readonly UpdateRoom $updateRoom,
    ) {}

    /**
     * @param  array<string, mixed>  $data  lo que acepta la Action del módulo dueño
     *
     * @throws ValidationException
     */
    public function handle(CatalogReview $review, Model $record, array $data, User $actor): Model
    {
        if ($review->catalog === Catalog::Teachers) {
            throw ValidationException::withMessages(['record' => 'Los docentes se corrigen en Docentes.']);
        }

        $data = Arr::except($data, self::lockedFields($review->catalog));

        return DB::transaction(function () use ($review, $record, $data, $actor): Model {
            $review->setRawAttributes($review->newQuery()->lockForUpdate()->findOrFail($review->getKey())->getAttributes(), sync: true);

            $this->rules->ensureOpen($review);
            $this->rules->ensureContains($review, $record);

            $record->discardChanges();

            match ($review->catalog) {
                Catalog::Subjects => $this->updateSubject->handle($record, $data),
                Catalog::Careers => $this->updateCareer->handle($record, $data),
                Catalog::StudyPlans => $this->updateStudyPlan->handle($record, $data),
                Catalog::Rooms => $this->updateRoom->handle($record, $data),
            };

            if ($record->wasChanged()) {
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
            Catalog::Subjects, Catalog::Careers => ['code', 'department_id'],
            Catalog::StudyPlans => ['code', 'career_id'],
            Catalog::Rooms => ['code', 'department_id', 'building_id', 'campus_id'],
            Catalog::Teachers => [],
        };
    }
}
