<?php

namespace App\Modules\AcademicLoad\Rules\Constraints;

use App\Modules\AcademicLoad\Enums\AgreementKind;
use App\Modules\AcademicLoad\Enums\AgreementStatus;
use App\Modules\AcademicLoad\Models\AcademicLoad;
use App\Modules\AcademicLoad\Models\InterdepartmentalAgreement;
use Illuminate\Database\Eloquent\Builder;

/**
 * Lo que la carga de un departamento deja pendiente con otros departamentos al cerrarse (HC-15, tarea 4.1), en el
 * periodo y el campus de la carga:
 *
 * - un grupo compartido que el departamento propuso y sigue sin respuesta, porque aparta lugares del grupo
 *   (P6, confirmado el 10 de noviembre de 2026);
 * - un acuerdo, préstamo o grupo compartido, que espera la respuesta del propio departamento (supuesto por
 *   confirmar): el departamento que lo pidió no debe quedarse esperando, con su choque HC-10 o sus lugares
 *   apartados, mientras el que decide ya cerró.
 *
 * Un préstamo que el departamento pidió y sigue sin respuesta no se cuenta aquí: ya es choque duro de su sesión
 * (HC-10) y lo veta Scheduling.
 */
final class AgreementsAnswered
{
    /**
     * @return list<string>
     */
    public function reasons(AcademicLoad $load): array
    {
        $proposed = $this->pending($load)
            ->where('kind', AgreementKind::SharedGroup)
            ->where('from_department_id', $load->department_id)
            ->count();
        $awaiting = $this->pending($load)
            ->where('to_department_id', $load->department_id)
            ->count();

        return array_values(array_filter([
            $proposed === 0 ? null : ($proposed === 1
                ? '1 grupo compartido que propuso el departamento sigue sin respuesta: espera la respuesta o cancélalo en Acuerdos.'
                : "{$proposed} grupos compartidos que propuso el departamento siguen sin respuesta: espera la respuesta o cancélalos en Acuerdos."),
            $awaiting === 0 ? null : ($awaiting === 1
                ? '1 acuerdo espera la respuesta del departamento: acéptalo o recházalo en Acuerdos.'
                : "{$awaiting} acuerdos esperan la respuesta del departamento: acéptalos o recházalos en Acuerdos."),
        ]));
    }

    /**
     * @return Builder<InterdepartmentalAgreement>
     */
    private function pending(AcademicLoad $load): Builder
    {
        return InterdepartmentalAgreement::query()
            ->where('term_id', $load->term_id)
            ->where('campus_id', $load->campus_id)
            ->where('status', AgreementStatus::Proposed);
    }
}
