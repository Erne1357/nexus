<?php

namespace App\Modules\AcademicLoad\Queries;

use App\Modules\Academic\Models\Department;
use App\Modules\AcademicLoad\Enums\ProposalReviewStatus;
use App\Modules\AcademicLoad\Models\CareerProposal;
use App\Modules\AcademicLoad\Models\ProposalItem;
use App\Modules\AcademicLoad\Models\ProposalReview;
use App\Modules\CatalogValidation\Enums\Catalog;

/**
 * Lo que muestra la página de la propuesta de carrera (CareerProposalQuery::overview): la propuesta con su
 * periodo, campus y carrera; sus filas por semestre, cada una con su materia (clave, nombre, `needs_lab`) y el
 * departamento de la materia, también si se borraron después; la parte de cada departamento con su estado y
 * comentario; los totales de lo que se pide, y los departamentos involucrados con catálogos sin validar, para el
 * aviso (reglas-de-negocio.md, "Propuesta de carrera": avisa, no bloquea).
 */
final readonly class ProposalOverview
{
    /**
     * @param  array<int, list<ProposalItem>>  $semesters  semestre → sus filas por clave de materia, con `subject.department`
     * @param  list<ProposalReview>  $reviews  la parte de cada departamento, por clave del departamento, con `department`
     * @param  array{subjects: int, groups: int, expected: int}  $totals  materias con grupos, grupos pedidos y alumnos esperados en ellas
     * @param  list<array{department: Department, catalogs: list<Catalog>}>  $pendingCatalogs  departamentos con grupos pedidos y catálogos sin validar, por clave
     */
    public function __construct(
        public CareerProposal $proposal,
        public array $semesters,
        public array $reviews,
        public array $totals,
        public array $pendingCatalogs,
    ) {}

    /**
     * La parte de un departamento, o null si no tiene (todavía no se envía o no se le piden grupos).
     */
    public function reviewFor(string $departmentId): ?ProposalReview
    {
        foreach ($this->reviews as $review) {
            if ($review->department_id === $departmentId) {
                return $review;
            }
        }

        return null;
    }

    /**
     * Si el coordinador puede corregir la fila: la propuesta está en borrador o regresada y el departamento de
     * la materia no ha aceptado su parte (lo mismo que exige UpdateProposalItem). Para deshabilitar la fila con
     * su motivo.
     */
    public function isEditable(ProposalItem $item): bool
    {
        return $this->proposal->status->isEditable()
            && $this->reviewFor($item->subject->department_id)?->status !== ProposalReviewStatus::Accepted;
    }
}
