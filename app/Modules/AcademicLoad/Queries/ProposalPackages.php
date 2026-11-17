<?php

namespace App\Modules\AcademicLoad\Queries;

use App\Modules\AcademicLoad\Models\ProposalIntake;
use App\Modules\AcademicLoad\Models\ProposalPackage;
use App\Modules\AcademicLoad\Models\ProposalPackageCampus;

/**
 * Los paquetes de primer semestre de una propuesta tal como los pinta su página (tarea 5.6b; ProposalPackagesQuery):
 *
 * - `packages`, en su orden (posición y código): el paquete, cómo se llama en pantalla (su nombre o «Paquete 1»), sus
 *   materias (las de la propuesta, por semestre y clave) y cuántos abre cada columna, por llave de columna
 *   (ProposalOverview::columnKey).
 * - `columns`, por llave de columna: su nuevo ingreso (`proposal_intakes`, null si la columna no tiene), los paquetes que
 *   sugiere Nexus para él (ProposalPackageRules::suggestedPackages con el tamaño objetivo normal), cuántos abre de todos
 *   los paquetes, los lugares que dan y si alcanzan (`status`: tono y texto; null si no hay nada que decir).
 * - Las materias que el panel ofrece: las de 1.º y, «si hace falta», las de 2.º; y el nombre del siguiente paquete.
 */
final readonly class ProposalPackages
{
    /**
     * @param  list<array{package: ProposalPackage, label: string, subjects: list<ProposalSubject>, counts: array<string, ProposalPackageCampus>}>  $packages
     * @param  array<string, array{intake: ?ProposalIntake, suggestedPackages: ?int, opens: int, seats: int, status: ?array{tone: string, text: string}}>  $columns
     * @param  list<ProposalSubject>  $firstSemester
     * @param  list<ProposalSubject>  $secondSemester
     */
    public function __construct(
        public array $packages,
        public array $columns,
        public array $firstSemester,
        public array $secondSemester,
        public int $groupSize,
        public string $nextLabel,
    ) {}

    /**
     * Si el bloque va en la página: la propuesta tiene materias de 1.º o ya tiene paquetes.
     */
    public function shown(): bool
    {
        return $this->firstSemester !== [] || $this->packages !== [];
    }

    /**
     * Un paquete de la lista por su id, o null.
     *
     * @return array{package: ProposalPackage, label: string, subjects: list<ProposalSubject>, counts: array<string, ProposalPackageCampus>}|null
     */
    public function find(?string $id): ?array
    {
        foreach ($this->packages as $entry) {
            if ($entry['package']->getKey() === $id) {
                return $entry;
            }
        }

        return null;
    }

    /**
     * Las ids de las materias de 1.º, las que lleva «Armar paquete con las materias de 1.º».
     *
     * @return list<string>
     */
    public function firstSemesterIds(): array
    {
        return array_map(fn (ProposalSubject $subject): string => $subject->id(), $this->firstSemester);
    }
}
