<?php

namespace App\Modules\AcademicLoad\Rules\Constraints;

use App\Modules\AcademicLoad\Enums\AgreementKind;
use App\Modules\AcademicLoad\Enums\AgreementStatus;
use App\Modules\AcademicLoad\Models\AcademicLoad;
use App\Modules\AcademicLoad\Models\InterdepartmentalAgreement;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

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
 * (HC-10) y lo veta Scheduling. La guarda de CloseLoad usa reasons(); las pantallas de cierre leen la misma regla con
 * pending() (una carga) y countsByLoad() (todas las de un periodo).
 */
final class AgreementsAnswered
{
    /**
     * La guarda de CloseLoad: solo cuenta, sin traer los acuerdos (pending() es para las pantallas).
     *
     * @return list<string>
     */
    public function reasons(AcademicLoad $load): array
    {
        $proposed = $this->proposedBy($load)->count();
        $awaiting = $this->awaitedBy($load)->count();

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
     * Los acuerdos que detienen el cierre de la carga, del más antiguo al más reciente: `proposed`, los grupos
     * compartidos que propuso el departamento (con el departamento que debe responder); `awaiting`, los que esperan
     * su respuesta (con el que los pidió).
     *
     * @return array{proposed: Collection<int, InterdepartmentalAgreement>, awaiting: Collection<int, InterdepartmentalAgreement>}
     */
    public function pending(AcademicLoad $load): array
    {
        return [
            'proposed' => $this->proposedBy($load)->with('toDepartment:id,code,name')->orderBy('created_at')->get(),
            'awaiting' => $this->awaitedBy($load)->with('fromDepartment:id,code,name')->orderBy('created_at')->get(),
        ];
    }

    /**
     * Las mismas cuentas para todas las cargas de un periodo, en una consulta (el tablero «Avance del cierre»): por
     * `campus_id|department_id`, cuántos grupos compartidos propuso el departamento sin respuesta y cuántos acuerdos
     * esperan su respuesta. Una carga sin ninguno no aparece.
     *
     * @return array<string, array{proposed: int, awaiting: int}>
     */
    public function countsByLoad(string $termId): array
    {
        $counts = [];

        foreach ($this->open($termId)->toBase()->get(['campus_id', 'kind', 'from_department_id', 'to_department_id']) as $agreement) {
            if ($agreement->kind === AgreementKind::SharedGroup->value) {
                $key = "{$agreement->campus_id}|{$agreement->from_department_id}";
                $counts[$key] ??= ['proposed' => 0, 'awaiting' => 0];
                $counts[$key]['proposed']++;
            }

            $key = "{$agreement->campus_id}|{$agreement->to_department_id}";
            $counts[$key] ??= ['proposed' => 0, 'awaiting' => 0];
            $counts[$key]['awaiting']++;
        }

        return $counts;
    }

    /**
     * Los grupos compartidos que propuso el departamento y siguen sin respuesta.
     *
     * @return Builder<InterdepartmentalAgreement>
     */
    private function proposedBy(AcademicLoad $load): Builder
    {
        return $this->open($load->term_id)
            ->where('campus_id', $load->campus_id)
            ->where('kind', AgreementKind::SharedGroup)
            ->where('from_department_id', $load->department_id);
    }

    /**
     * Los acuerdos que esperan la respuesta del departamento.
     *
     * @return Builder<InterdepartmentalAgreement>
     */
    private function awaitedBy(AcademicLoad $load): Builder
    {
        return $this->open($load->term_id)
            ->where('campus_id', $load->campus_id)
            ->where('to_department_id', $load->department_id);
    }

    /**
     * @return Builder<InterdepartmentalAgreement>
     */
    private function open(string $termId): Builder
    {
        return InterdepartmentalAgreement::query()
            ->where('term_id', $termId)
            ->where('status', AgreementStatus::Proposed);
    }
}
