<?php

namespace App\Modules\Scheduling\Actions\Agreements;

use App\Modules\AcademicLoad\Actions\Agreements\RejectAgreement;
use App\Modules\AcademicLoad\Enums\AgreementKind;
use App\Modules\AcademicLoad\Models\InterdepartmentalAgreement;
use App\Modules\Core\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * El jefe del departamento de la carrera rechaza el cupo que le propusieron en un grupo (reglas-de-negocio.md,
 * "Acuerdos entre departamentos"): no hay cupo que quitar, porque nace al aceptar (AcceptSharedGroup); solo rechaza
 * el acuerdo con motivo (RejectAgreement). Quién puede lo decide InterdepartmentalAgreementPolicy.
 */
final class RejectSharedGroup
{
    public function __construct(private readonly RejectAgreement $reject) {}

    /**
     * @throws ValidationException
     */
    public function handle(InterdepartmentalAgreement $agreement, string $comment, User $actor): InterdepartmentalAgreement
    {
        if ($agreement->kind !== AgreementKind::SharedGroup) {
            throw ValidationException::withMessages(['kind' => 'Este acuerdo no es de un cupo en un grupo.']);
        }

        return $this->reject->handle($agreement, $comment, $actor);
    }
}
