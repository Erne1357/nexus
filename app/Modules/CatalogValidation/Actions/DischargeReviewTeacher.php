<?php

namespace App\Modules\CatalogValidation\Actions;

use App\Modules\CatalogValidation\Models\CatalogReview;
use App\Modules\CatalogValidation\Queries\CatalogRecordsQuery;
use App\Modules\CatalogValidation\Rules\CatalogReviewRules;
use App\Modules\Core\Models\User;
use App\Modules\Faculty\Actions\DischargeTeacherFromDepartment;
use App\Modules\Faculty\Models\Teacher;
use App\Modules\Faculty\Models\TeacherDepartment;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * «Dar de baja de <depto>» en la revisión de docentes (CV8, confirmado el 12 de noviembre de 2026): el jefe da de baja
 * al docente de su departamento con DischargeTeacherFromDepartment (si no le queda otra adscripción, queda dado de baja
 * y su cuenta se inhabilita; si le queda, solo sale de este departamento y, si hacía falta, tiene otra principal). El
 * docente sale de la revisión, así que ya no queda por revisar (CV7), y se le quita la marca: si vuelve a la revisión, vuelve
 * sin revisar (CV-S7). Quién puede lo deciden
 * CatalogReviewPolicy::correct y TeacherPolicy::removeAdscription (CV-S8) antes de llamarla.
 *
 * En una transacción relee la revisión con bloqueo, exige que no esté validada y que el docente sea suyo (solo la de
 * docentes lo contiene), lo da de baja y pasa la revisión a en revisión (StartReview, CV-S4). Devuelve lo de
 * DischargeTeacherFromDepartment.
 */
final class DischargeReviewTeacher
{
    public function __construct(
        private readonly CatalogReviewRules $rules,
        private readonly DischargeTeacherFromDepartment $discharge,
        private readonly StartReview $startReview,
        private readonly CatalogRecordsQuery $records,
    ) {}

    /**
     * @return array{promoted: ?TeacherDepartment, remaining: int, outcome: ?string, deactivated: bool}
     *
     * @throws ValidationException
     */
    public function handle(CatalogReview $review, Teacher $teacher, User $actor): array
    {
        return DB::transaction(function () use ($review, $teacher, $actor): array {
            $review->setRawAttributes($review->newQuery()->lockForUpdate()->findOrFail($review->getKey())->getAttributes(), sync: true);

            $this->rules->ensureOpen($review);
            $this->rules->ensureContains($review, $teacher);

            $result = $this->discharge->handle($this->records->teacherAdscriptions($review, [$teacher->getKey()])[$teacher->getKey()]);
            $review->marks()->where('record_id', $teacher->getKey())->delete();
            $this->startReview->handle($review, $actor);

            return $result;
        });
    }
}
