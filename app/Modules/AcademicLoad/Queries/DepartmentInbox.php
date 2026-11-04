<?php

namespace App\Modules\AcademicLoad\Queries;

use App\Modules\Academic\Models\Career;
use App\Modules\Academic\Models\Department;
use App\Modules\Academic\Models\Subject;
use App\Modules\AcademicLoad\Enums\InboxStatus;
use App\Modules\AcademicLoad\Models\AcademicLoad;
use App\Modules\AcademicLoad\Models\CareerProposal;
use App\Modules\AcademicLoad\Models\ProposalItem;
use App\Modules\AcademicLoad\Models\ProposalReview;
use App\Modules\AcademicLoad\Rules\ParallelGroups;
use App\Modules\AcademicLoad\Rules\ProposalAnomalies;
use App\Modules\CatalogValidation\Enums\Catalog;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Term;

/**
 * Lo que muestra la bandeja de un departamento en un campus y un periodo (DepartmentInboxQuery::inbox;
 * reglas-de-negocio.md, "Propuesta de carrera"):
 *
 * - la carga del departamento en el campus, o null si no existe, y sus catálogos sin validar (HC-14), que
 *   bloquean aceptar (canAccept());
 * - cada carrera ofrecida en el campus, y la que ya no se ofrece pero tiene una parte del departamento, con el
 *   estado de lo que le pide (InboxStatus), si la parte se puede decidir (pendiente, con la propuesta enviada o
 *   regresada por otro departamento; ProposalReviewRules::isDecidable), sus totales para el departamento y sus
 *   filas (rowsFor()). Sin parte, totales en cero y sin filas;
 * - por materia del departamento pedida en las partes que cuentan (aceptadas, y las que se pueden decidir), la
 *   sugerencia de paralelos (ParallelGroups) y sus esperados.
 */
final readonly class DepartmentInbox
{
    /**
     * @param  list<Catalog>  $pendingCatalogs  catálogos del departamento sin validar, en el orden del enum Catalog
     * @param  list<array{career: Career, status: InboxStatus, decidable: bool, correctionBy: list<string>, proposal: ?CareerProposal, review: ?ProposalReview, subjects: int, groups: int, rows: list<ProposalItem>, anomalies: int}>  $careers  pendientes primero (InboxStatus::order), luego por clave; cada fila con `subject`, por semestre y clave; `anomalies` es cuántas filas tienen algo raro (ProposalAnomalies)
     * @param  list<array{subject: Subject, expected: int, parallels: ParallelGroups}>  $subjects  por clave de materia; los paralelos por clave de carrera
     */
    public function __construct(
        public Term $term,
        public Campus $campus,
        public Department $department,
        public ?AcademicLoad $load,
        public array $pendingCatalogs,
        public array $careers,
        public array $subjects,
        public ProposalAnomalies $anomalies = new ProposalAnomalies,
    ) {}

    /**
     * Si el departamento puede aceptar partes: tiene su carga en el campus y sus catálogos validados (lo mismo que
     * exige AcceptProposalReview). Para deshabilitar el botón con su motivo.
     */
    public function canAccept(): bool
    {
        return $this->load !== null && $this->pendingCatalogs === [];
    }

    /**
     * La carrera de la bandeja con ese id (que puede llegar del cliente), o null si no está.
     *
     * @return array{career: Career, status: InboxStatus, decidable: bool, correctionBy: list<string>, proposal: ?CareerProposal, review: ?ProposalReview, subjects: int, groups: int, rows: list<ProposalItem>, anomalies: int}|null
     */
    public function career(string $careerId): ?array
    {
        foreach ($this->careers as $row) {
            if ($row['career']->getKey() === $careerId) {
                return $row;
            }
        }

        return null;
    }

    /**
     * Las filas con grupos que la carrera le pide al departamento, cada una con el total de su materia en las
     * partes que cuentan para los paralelos (en cero si ninguna la cuenta) y
     *
     * con lo raro de la fila para marcarlo sin bloquear la decisión (ProposalAnomalies).
     *
     * @return list<array{item: ProposalItem, total: array{careers: int, groups: int, expected: int}, anomalies: list<string>}>
     */
    public function rowsFor(string $careerId): array
    {
        return array_map(
            fn (ProposalItem $item): array => [
                'item' => $item,
                'total' => $this->totalFor($item->subject_id),
                'anomalies' => $this->anomalies->for($item->eligible_students, $item->expected_students, $item->requested_groups),
            ],
            $this->career($careerId)['rows'] ?? [],
        );
    }

    /**
     * Lo que piden de una materia las partes que cuentan para los paralelos: cuántas carreras, cuántos grupos y
     * cuántos alumnos esperados.
     *
     * @return array{careers: int, groups: int, expected: int}
     */
    public function totalFor(string $subjectId): array
    {
        foreach ($this->subjects as $row) {
            if ($row['subject']->getKey() === $subjectId) {
                return ['careers' => count($row['parallels']->careers), 'groups' => $row['parallels']->requested, 'expected' => $row['expected']];
            }
        }

        return ['careers' => 0, 'groups' => 0, 'expected' => 0];
    }
}
