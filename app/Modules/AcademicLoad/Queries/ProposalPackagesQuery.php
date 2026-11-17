<?php

namespace App\Modules\AcademicLoad\Queries;

use App\Modules\AcademicLoad\Enums\ProposalReviewStatus;
use App\Modules\AcademicLoad\Models\CareerProposal;
use App\Modules\AcademicLoad\Models\ProposalIntake;
use App\Modules\AcademicLoad\Models\ProposalItem;
use App\Modules\AcademicLoad\Models\ProposalPackage;
use App\Modules\AcademicLoad\Models\ProposalPackageCampus;
use App\Modules\AcademicLoad\Models\ProposalReview;
use App\Modules\AcademicLoad\Rules\ProposalPackageRules;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Queries\KeyLookup;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * Lecturas de los paquetes de primer semestre y del nuevo ingreso de la propuesta de carrera para su página (tarea 5.6b;
 * reglas-de-negocio.md, «Propuesta de carrera»). Quién los ve y quién los cambia lo decide CareerProposalPolicy en el
 * componente (`view`, `managePackages` y `updateColumn`).
 */
final class ProposalPackagesQuery
{
    public function __construct(private readonly ProposalPackageRules $rules) {}

    /**
     * Lo que pinta el bloque «Paquetes de primer semestre» (ProposalPackages), con las materias y las columnas que ya leyó
     * el resumen de la propuesta (CareerProposalQuery::overview). Dos consultas: los paquetes con sus materias y sus
     * columnas, y el nuevo ingreso de cada columna. Los lugares de un campus son los paquetes que abre por el tamaño
     * objetivo normal (`$groupSize`, DemandSettings), como los paquetes sugeridos, aunque el paquete lleve una materia con
     * laboratorio: alcanzan si cubren su nuevo ingreso (supuesto por confirmar).
     */
    public function forOverview(ProposalOverview $overview, int $groupSize): ProposalPackages
    {
        $proposal = $overview->proposal;
        $packages = ProposalPackage::query()
            ->where('career_proposal_id', $proposal->getKey())
            ->with(['subjects', 'campuses'])
            ->orderBy('position')
            ->orderBy('code')
            ->get();
        $intakes = ProposalIntake::query()
            ->where('career_proposal_id', $proposal->getKey())
            ->get()
            ->keyBy(fn (ProposalIntake $intake): string => $intake->campus_id ?? '');
        $subjects = $overview->subjects();

        $entries = $packages->map(function (ProposalPackage $package) use ($subjects): array {
            $ids = array_flip($package->subjects->pluck('subject_id')->all());

            return [
                'package' => $package,
                'label' => $package->label,
                'subjects' => array_values(array_filter($subjects, fn (ProposalSubject $subject): bool => isset($ids[$subject->id()]))),
                'counts' => $package->campuses->keyBy(fn (ProposalPackageCampus $column): string => $column->campus_id ?? '')->all(),
            ];
        })->all();

        $columns = [];

        foreach ($overview->columns as $campus) {
            $key = ProposalOverview::columnKey($campus);
            $intake = $intakes->get($key);
            $opens = array_sum(array_map(fn (array $entry): int => ($entry['counts'][$key] ?? null)?->count ?? 0, $entries));
            $seats = $opens * $groupSize;

            $columns[$key] = [
                'intake' => $intake,
                'suggestedPackages' => $intake === null ? null : $this->rules->suggestedPackages($intake->new_students, $groupSize),
                'opens' => $opens,
                'seats' => $seats,
                'status' => $this->status($entries === [], $intake?->new_students, $seats),
            ];
        }

        $numbers = $packages->map(fn (ProposalPackage $package): int => (int) preg_replace('/\D/', '', $package->code));

        return new ProposalPackages(
            packages: $entries,
            columns: $columns,
            firstSemester: $overview->semesters[1] ?? [],
            secondSemester: $overview->semesters[2] ?? [],
            groupSize: $groupSize,
            nextLabel: 'Paquete '.(($numbers->max() ?? 0) + 1),
        );
    }

    /**
     * Un paquete de la propuesta por un id que llega del cliente; el de otra propuesta no existe.
     *
     * @throws ModelNotFoundException<ProposalPackage>
     */
    public function packageOrFail(CareerProposal $proposal, string $id): ProposalPackage
    {
        return KeyLookup::findOrFail(ProposalPackage::query()->where('career_proposal_id', $proposal->getKey()), $id);
    }

    /**
     * Las materias de 1.º de la propuesta que se pueden poner en un paquete, por id: las de los departamentos que no han
     * aceptado su parte (las aceptadas ya no cambian, y CreateProposalPackage las rechazaría). Es lo que marca «Armar
     * paquete con las materias de 1.º».
     *
     * @return list<string>
     */
    public function openFirstSemesterSubjectIds(CareerProposal $proposal): array
    {
        $accepted = ProposalReview::query()
            ->select('department_id')
            ->where('career_proposal_id', $proposal->getKey())
            ->where('status', ProposalReviewStatus::Accepted);

        return ProposalItem::query()
            ->where('proposal_items.career_proposal_id', $proposal->getKey())
            ->where('proposal_items.semester', 1)
            ->join('subjects', 'subjects.id', '=', 'proposal_items.subject_id')
            ->whereNotIn('subjects.department_id', $accepted)
            ->orderBy('subjects.code')
            ->distinct()
            ->pluck('subjects.code', 'proposal_items.subject_id')
            ->keys()
            ->all();
    }

    /**
     * El campus de una columna de la propuesta por su llave, que llega del cliente (ProposalOverview::columnKey: el id del
     * campus, o '' para la columna sin campus de una modalidad sin salones, MS5). Una columna que la propuesta no tiene no
     * existe.
     *
     * @throws ModelNotFoundException<ProposalItem>
     */
    public function columnOrFail(CareerProposal $proposal, string $key): ?Campus
    {
        $campus = $key === '' ? null : KeyLookup::find(Campus::withTrashed(), $key);
        $exists = ($key === '' || $campus !== null) && ProposalItem::query()
            ->where('career_proposal_id', $proposal->getKey())
            ->where(fn ($query) => $campus === null ? $query->whereNull('campus_id') : $query->where('campus_id', $campus->getKey()))
            ->exists();

        if (! $exists) {
            throw (new ModelNotFoundException)->setModel(ProposalItem::class, [$key]);
        }

        return $campus;
    }

    /**
     * Si los paquetes de una columna alcanzan para su nuevo ingreso: sin paquetes y con nuevo ingreso, no tiene grupos;
     * con menos lugares, cuántos faltan; si no, cuántos lugares da. Sin nuevo ingreso en la columna, solo los lugares.
     *
     * @return array{tone: string, text: string}|null
     */
    private function status(bool $empty, ?int $newStudents, int $seats): ?array
    {
        $places = fn (int $count): string => $count === 1 ? '1 lugar' : "{$count} lugares";

        return match (true) {
            $empty => ($newStudents ?? 0) > 0 ? ['tone' => 'warning', 'text' => 'Sin paquetes: el nuevo ingreso no tiene grupos'] : null,
            $newStudents === null => ['tone' => 'neutral', 'text' => ucfirst($places($seats))],
            $seats < $newStudents => ['tone' => 'warning', 'text' => ($newStudents - $seats === 1 ? 'Falta ' : 'Faltan ').$places($newStudents - $seats)],
            default => ['tone' => 'success', 'text' => 'Alcanza: '.$places($seats)],
        };
    }
}
