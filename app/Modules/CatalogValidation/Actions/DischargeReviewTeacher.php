<?php

namespace App\Modules\CatalogValidation\Actions;

use App\Modules\CatalogValidation\Enums\DischargeReason;
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
 * «Quitar de <departamento>» en la revisión de docentes (CV8, confirmado el 12 de noviembre de 2026; el nombre, D10,
 * el 14 de noviembre de 2026): el jefe quita al docente de su departamento con DischargeTeacherFromDepartment (si no le
 * queda otra adscripción, queda dado de baja y su cuenta se inhabilita; si le queda, solo sale de este departamento y,
 * si hacía falta, tiene otra principal). El docente sale de la revisión, así que ya no queda por revisar (CV7), y se le
 * quita la marca: si vuelve a la revisión, vuelve sin revisar (CV-S7). Quién puede lo deciden
 * CatalogReviewPolicy::correct y TeacherPolicy::removeAdscription (CV-S8) antes de llamarla.
 *
 * Lleva un motivo opcional (D8, confirmado el 14 de noviembre de 2026): jubilación, renuncia, cambio de departamento u
 * otro, que pide su texto (confirmado el 14 de noviembre de 2026). Queda en la bitácora del docente (evento `discharged`), en
 * `properties.reason` como `{kind, text?}` o null, con la revisión, el departamento y si quedó dado de baja; la ficha
 * del docente lo muestra. El texto solo va con «Otro».
 *
 * En una transacción relee la revisión con bloqueo, exige que no esté validada y que el docente sea suyo (solo la de
 * docentes lo contiene), lo quita, deja la bitácora y pasa la revisión a en revisión (StartReview, CV-S4). Devuelve lo
 * de DischargeTeacherFromDepartment.
 */
final class DischargeReviewTeacher
{
    /** Lo más largo que se guarda del texto de «Otro». */
    public const int REASON_MAX = 500;

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
    public function handle(CatalogReview $review, Teacher $teacher, User $actor, ?DischargeReason $reason = null, ?string $reasonText = null): array
    {
        $logged = $this->reason($reason, $reasonText);

        return DB::transaction(function () use ($review, $teacher, $actor, $logged): array {
            $review->setRawAttributes($review->newQuery()->lockForUpdate()->findOrFail($review->getKey())->getAttributes(), sync: true);

            $this->rules->ensureOpen($review);
            $this->rules->ensureContains($review, $teacher);
            $this->rules->ensureNoInactiveTeachers($review, [$teacher->getKey()]); // CV14

            $result = $this->discharge->handle($this->records->teacherAdscriptions($review, [$teacher->getKey()])[$teacher->getKey()]);
            $review->marks()->where('record_id', $teacher->getKey())->delete();

            activity()
                ->performedOn($teacher)
                ->causedBy($actor)
                ->event('discharged')
                ->withProperties([
                    'review_id' => $review->getKey(),
                    'department_id' => $review->department_id,
                    'deactivated' => $result['deactivated'],
                    'reason' => $logged,
                ])
                ->log("Quitó al docente {$teacher->rfc} de {$review->department?->name} en la validación de docentes");

            $this->startReview->handle($review, $actor);

            return $result;
        });
    }

    /**
     * El motivo como va a la bitácora: null sin motivo; el texto, recortado, solo con «Otro», que lo exige.
     *
     * @return ?array{kind: string, text?: string}
     *
     * @throws ValidationException
     */
    private function reason(?DischargeReason $reason, ?string $text): ?array
    {
        if ($reason === null) {
            return null;
        }

        if ($reason !== DischargeReason::Other) {
            return ['kind' => $reason->value];
        }

        $text = trim((string) $text);

        if ($text === '') {
            throw ValidationException::withMessages(['reason' => 'Escribe el motivo.']);
        }

        return ['kind' => $reason->value, 'text' => mb_substr($text, 0, self::REASON_MAX)];
    }
}
