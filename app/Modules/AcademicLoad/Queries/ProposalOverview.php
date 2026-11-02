<?php

namespace App\Modules\AcademicLoad\Queries;

use App\Modules\Academic\Models\Department;
use App\Modules\AcademicLoad\Enums\ProposalReviewStatus;
use App\Modules\AcademicLoad\Models\CareerProposal;
use App\Modules\AcademicLoad\Models\ProposalItem;
use App\Modules\AcademicLoad\Models\ProposalReview;
use App\Modules\AcademicLoad\Rules\ProposalAnomalies;
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
     * @param  array<string, string>  $specialtyOf  materia → la especialidad de su plan; las del tronco común no aparecen
     * @param  list<array{id: string, name: string, subjects: int}>  $specialties  las especialidades con materias en la propuesta, por clave, con cuántas
     */
    public function __construct(
        public CareerProposal $proposal,
        public array $semesters,
        public array $reviews,
        public array $totals,
        public array $pendingCatalogs,
        public ProposalAnomalies $anomalies = new ProposalAnomalies,
        public array $specialtyOf = [],
        public array $specialties = [],
    ) {}

    /**
     * La retícula por semestre lista para acomodarse (SemesterGrid): en cada semestre primero el tronco común y
     * luego, por banda, las materias de cada especialidad, cada bloque por clave.
     *
     * @return array<int, list<ProposalItem>>
     */
    public function gridSemesters(): array
    {
        $band = array_flip(array_column($this->specialties, 'id'));
        $position = fn (ProposalItem $item): int => isset($this->specialtyOf[$item->subject_id]) ? ($band[$this->specialtyOf[$item->subject_id]] ?? count($band)) + 1 : 0;

        return array_map(function (array $items) use ($position): array {
            $indexed = array_map(null, $items, array_keys($items));
            usort($indexed, fn (array $a, array $b): int => [$position($a[0]), $a[1]] <=> [$position($b[0]), $b[1]]);

            return array_column($indexed, 0);
        }, $this->semesters);
    }

    /**
     * La especialidad del plan en que está la materia de la fila, o null si es del tronco común.
     */
    public function specialtyFor(ProposalItem $item): ?string
    {
        return $this->specialtyOf[$item->subject_id] ?? null;
    }

    /**
     * Lo raro de la fila para marcarlo sin bloquear el envío (ProposalAnomalies): más esperados que elegibles y
     * grupos con muy pocos alumnos.
     *
     * @return list<string>
     */
    public function anomaliesFor(ProposalItem $item): array
    {
        return $this->anomalies->for($item->eligible_students, $item->expected_students, $item->requested_groups);
    }

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
     * El resumen de cada departamento involucrado, por clave: el que tiene materias con grupos pedidos o una
     * parte, con cuántas materias y grupos se le piden y su parte (null si todavía no se envía). Es lo que la
     * página muestra junto a la retícula y la tabla para resaltar y filtrar por departamento.
     *
     * @return list<array{department: Department, subjects: int, groups: int, review: ?ProposalReview}>
     */
    public function departments(): array
    {
        $rows = [];

        foreach ($this->semesters as $items) {
            foreach ($items as $item) {
                $department = $item->subject->department;
                $rows[$department->getKey()] ??= ['department' => $department, 'subjects' => 0, 'groups' => 0, 'review' => $this->reviewFor($department->getKey())];

                if ($item->requested_groups > 0) {
                    $rows[$department->getKey()]['subjects']++;
                    $rows[$department->getKey()]['groups'] += $item->requested_groups;
                }
            }
        }

        $rows = array_filter($rows, fn (array $row): bool => $row['groups'] > 0 || $row['review'] !== null);
        usort($rows, fn (array $a, array $b): int => $a['department']->code <=> $b['department']->code);

        return $rows;
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
