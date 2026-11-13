<?php

namespace App\Modules\AcademicLoad\Queries;

use App\Modules\Academic\Models\Department;
use App\Modules\AcademicLoad\Enums\ProposalReviewStatus;
use App\Modules\AcademicLoad\Models\CareerProposal;
use App\Modules\AcademicLoad\Models\ProposalItem;
use App\Modules\AcademicLoad\Models\ProposalReview;
use App\Modules\AcademicLoad\Rules\ProposalAnomalies;
use App\Modules\CatalogValidation\Enums\Catalog;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\User;
use App\Modules\Core\Support\SeriationChains;

/**
 * Lo que muestra la página de la propuesta de carrera (CareerProposalQuery::overview; maqueta U1, opción A «Columnas por
 * campus»): la propuesta con su periodo, modalidad, carrera y quién la envió; sus columnas, una por campus de sus filas
 * (por clave) o una sola sin campus en una modalidad sin salones (M6, MS5); sus materias por semestre
 * (ProposalSubject), cada una con su fila de cada columna, su materia (clave, nombre, `needs_lab`) y el departamento de
 * la materia, también si se borraron después; quiénes la coordinan; la parte de cada departamento con su estado y
 * comentario; los totales de lo que se pide, con los grupos de cada campus, y los departamentos involucrados con
 * catálogos sin validar, para el aviso (reglas-de-negocio.md, "Propuesta de carrera": avisa, no bloquea).
 */
final readonly class ProposalOverview
{
    /**
     * @param  list<Campus|null>  $columns  los campus de sus filas, por clave; [null] en una modalidad sin salones
     * @param  array<int, list<ProposalSubject>>  $semesters  semestre → sus materias por clave, con `subject.department`
     * @param  list<ProposalReview>  $reviews  la parte de cada departamento, por clave del departamento, con `department`
     * @param  array{subjects: int, subjects_total: int, groups: int, groups_by_campus: array<string, int>, eligible_students: int}  $totals  materias con grupos (en algún campus), todas las materias, grupos pedidos, los de cada columna (por columnKey) y alumnos posibles (los distintos que pueden llevar alguna materia, congelados al armarla); los alumnos esperados no se suman entre materias, porque un alumno cuenta en cada una que puede llevar
     * @param  list<array{department: Department, catalogs: list<Catalog>}>  $pendingCatalogs  departamentos con grupos pedidos y catálogos sin validar, por clave
     * @param  array<string, string>  $specialtyOf  materia → la especialidad de su plan; las del tronco común no aparecen
     * @param  list<array{id: string, name: string, subjects: int}>  $specialties  las especialidades con materias en la propuesta, por clave, con cuántas
     * @param  array<string, list<string>>  $seriation  materia → las materias de la propuesta que pide por seriación en el plan de su fila
     * @param  list<array{user: User, campuses: list<string>}>  $coordinators  quienes coordinan la carrera en la modalidad, por nombre, con las llaves de las columnas que cubren (columnKey)
     */
    public function __construct(
        public CareerProposal $proposal,
        public array $columns,
        public array $semesters,
        public array $reviews,
        public array $totals,
        public array $pendingCatalogs,
        public ProposalAnomalies $anomalies = new ProposalAnomalies,
        public array $specialtyOf = [],
        public array $specialties = [],
        public array $seriation = [],
        public array $coordinators = [],
    ) {}

    /**
     * La llave de una columna: el id del campus, o '' para la columna sin campus.
     */
    public static function columnKey(?Campus $campus): string
    {
        return $campus?->getKey() ?? '';
    }

    /**
     * Si la propuesta tiene más de una columna: entonces la tabla lleva el total entre campus.
     */
    public function isMultiCampus(): bool
    {
        return count($this->columns) > 1;
    }

    /**
     * La materia de la propuesta con ese id (que puede llegar del cliente), o null.
     */
    public function subject(string $subjectId): ?ProposalSubject
    {
        foreach ($this->semesters as $subjects) {
            foreach ($subjects as $subject) {
                if ($subject->id() === $subjectId) {
                    return $subject;
                }
            }
        }

        return null;
    }

    /**
     * Las materias de todos los semestres, en orden.
     *
     * @return list<ProposalSubject>
     */
    public function subjects(): array
    {
        return array_merge([], ...array_values($this->semesters));
    }

    /**
     * La retícula por semestre lista para acomodarse (SemesterGrid): en cada semestre primero el tronco común y
     * luego, por banda, las materias de cada especialidad, cada bloque por clave.
     *
     * @return array<int, list<ProposalSubject>>
     */
    public function gridSemesters(): array
    {
        $band = array_flip(array_column($this->specialties, 'id'));
        $position = fn (ProposalSubject $subject): int => isset($this->specialtyOf[$subject->id()]) ? $band[$this->specialtyOf[$subject->id()]] + 1 : 0;

        return array_map(function (array $subjects) use ($position): array {
            $indexed = array_map(null, $subjects, array_keys($subjects));
            usort($indexed, fn (array $a, array $b): int => [$position($a[0]), $a[1]] <=> [$position($b[0]), $b[1]]);

            return array_column($indexed, 0);
        }, $this->semesters);
    }

    /**
     * Las cadenas de seriación de la retícula (SeriationChains, las mismas de la retícula del alumno): la de cada
     * materia que está en una (`of`, por id de materia) y cada cadena en el orden de su primera materia en la
     * retícula, nombrada por ella y con cuántas tiene.
     *
     * @return array{of: array<string, int>, chains: list<array{index: int, name: string, size: int}>}
     */
    public function chains(): array
    {
        $subjects = array_merge([], ...array_values($this->gridSemesters()));
        $names = array_column(array_map(fn (ProposalSubject $subject): array => ['id' => $subject->id(), 'name' => $subject->subject->name], $subjects), 'name', 'id');
        $of = [];
        $chains = [];

        foreach (SeriationChains::of(array_keys($names), $this->seriation) as $index => $members) {
            $of += array_fill_keys($members, $index);
            $chains[] = ['index' => $index, 'name' => $names[$members[0]], 'size' => count($members)];
        }

        return ['of' => $of, 'chains' => $chains];
    }

    /**
     * La especialidad del plan en que está la materia, o null si es del tronco común.
     */
    public function specialtyFor(ProposalSubject $subject): ?string
    {
        return $this->specialtyOf[$subject->id()] ?? null;
    }

    /**
     * Lo raro de una fila para marcarlo sin bloquear el envío (ProposalAnomalies): más esperados que elegibles y
     * grupos con muy pocos alumnos.
     *
     * @return list<string>
     */
    public function anomaliesFor(ProposalItem $item): array
    {
        return $this->anomalies->for($item->eligible_students, $item->expected_students, $item->requested_groups);
    }

    /**
     * Lo raro de las filas de una materia, cada cosa con el campus de su fila si hay más de una columna.
     *
     * @return list<string>
     */
    public function subjectAnomalies(ProposalSubject $subject): array
    {
        $anomalies = [];

        foreach ($this->columns as $campus) {
            $item = $subject->cell($campus);

            foreach ($item === null ? [] : $this->anomaliesFor($item) as $anomaly) {
                $anomalies[] = $this->isMultiCampus() && $campus !== null ? "{$campus->code}: {$anomaly}" : $anomaly;
            }
        }

        return $anomalies;
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
     * El resumen de cada departamento involucrado, por clave: el que tiene materias con grupos pedidos (en algún
     * campus) o una parte, con cuántas materias y grupos se le piden y su parte (null si todavía no se envía). Es lo
     * que la página muestra junto a la retícula y la tabla para resaltar y filtrar por departamento.
     *
     * @return list<array{department: Department, subjects: int, groups: int, review: ?ProposalReview}>
     */
    public function departments(): array
    {
        $rows = [];

        foreach ($this->subjects() as $subject) {
            $department = $subject->subject->department;
            $rows[$department->getKey()] ??= ['department' => $department, 'subjects' => 0, 'groups' => 0, 'review' => $this->reviewFor($department->getKey())];

            if ($subject->groups() > 0) {
                $rows[$department->getKey()]['subjects']++;
                $rows[$department->getKey()]['groups'] += $subject->groups();
            }
        }

        $rows = array_filter($rows, fn (array $row): bool => $row['groups'] > 0 || $row['review'] !== null);
        usort($rows, fn (array $a, array $b): int => $a['department']->code <=> $b['department']->code);

        return $rows;
    }

    /**
     * Si la fila se puede corregir por su estado: la propuesta está en borrador o regresada y el departamento de la
     * materia no ha aceptado su parte (lo mismo que exige UpdateProposalItem). Quién puede corregirla, por su campus,
     * lo decide CareerProposalPolicy::update (M7). Para deshabilitar la fila con su motivo.
     */
    public function isEditable(ProposalItem $item): bool
    {
        return $this->proposal->status->isEditable()
            && $this->reviewFor($item->subject->department_id)?->status !== ProposalReviewStatus::Accepted;
    }
}
