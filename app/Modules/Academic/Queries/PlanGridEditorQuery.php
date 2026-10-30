<?php

namespace App\Modules\Academic\Queries;

use App\Modules\Academic\Enums\PrerequisiteKind;
use App\Modules\Academic\Models\Specialty;
use App\Modules\Academic\Models\StudyPlan;
use App\Modules\Academic\Models\StudyPlanSubject;
use App\Modules\Academic\Models\SubjectPrerequisite;
use App\Modules\Core\Queries\KeyLookup;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * La retícula de un plan para su editor (academic::pages.plan; tarea 1.8): sus materias por semestre con su
 * lugar, su seriación y su cadena, y sus especialidades. Deja fuera lo que se quitó de la retícula y las
 * materias borradas del catálogo, con la seriación que las toca; una materia inactiva sigue, con
 * `is_active` en false, porque sigue en la retícula hasta que alguien la quite.
 *
 * @phpstan-type Requisite array{prerequisite_id: string, subject_id: string, code: string, name: string, semester: int, kind: string}
 * @phpstan-type Row array{placement_id: string, subject_id: string, code: string, name: string, credits: int, theory_hours: int, practice_hours: int, department: ?string, semester: int, specialty_id: ?string, is_required: bool, is_active: bool, chain: ?int, requires: list<Requisite>, required_by: list<Requisite>}
 */
final class PlanGridEditorQuery
{
    /**
     * La retícula en tres consultas, sin importar cuántas materias tenga:
     *
     * - `semesters`: del 1 a `semesters_count`, cada uno con sus materias (vacío si no tiene): primero las del
     *   tronco común y luego las de cada especialidad, por clave de especialidad y de materia. Cada una dice qué
     *   pide (`requires`) y qué materias la piden (`required_by`), con su tipo (`seriacion` o `correquisito`), y
     *   su cadena de seriación (`chain`, el índice en `chains`).
     * - `specialties`: las del plan sin las quitadas, por clave, con cuántas filas de la retícula las usan
     *   (`subjects`), las mismas que RemoveSpecialty cuenta para no quitarlas.
     * - `chains`: cada grupo de dos o más materias unidas por su seriación (los correquisitos no bloquean y no
     *   forman cadena), en el orden de su primera materia en la retícula y nombrado por ella, como en la
     *   retícula del alumno (DESIGN.md, "Retícula del alumno").
     * - `semesters_count`: los semestres del plan o, si una materia está más allá (o no están capturados), el
     *   mayor semestre con materias; 0 en un plan sin semestres ni materias.
     *
     * @return array{semesters: array<int, list<Row>>, specialties: list<array{id: string, code: string, name: string, is_active: bool, subjects: int}>, chains: list<array{index: int, name: string, size: int}>, semesters_count: int}
     */
    public function forPlan(StudyPlan $plan): array
    {
        $rows = $this->visiblePlacements($plan)
            ->leftJoin('departments', 'departments.id', '=', 'subjects.department_id')
            ->leftJoin('specialties', 'specialties.id', '=', 'study_plan_subjects.specialty_id')
            ->orderBy('study_plan_subjects.semester')
            ->orderByRaw('specialties.code asc nulls first')
            ->orderBy('subjects.code')
            ->toBase()
            ->get([
                'study_plan_subjects.id as placement_id', 'study_plan_subjects.subject_id', 'subjects.code', 'subjects.name',
                'subjects.credits', 'subjects.theory_hours', 'subjects.practice_hours', 'departments.name as department',
                'study_plan_subjects.semester', 'study_plan_subjects.specialty_id', 'study_plan_subjects.is_required', 'subjects.is_active',
            ]);

        /** @var array<string, Row> $grid */
        $grid = [];

        foreach ($rows as $row) {
            $grid[$row->subject_id] = [
                'placement_id' => $row->placement_id,
                'subject_id' => $row->subject_id,
                'code' => $row->code,
                'name' => $row->name,
                'credits' => (int) $row->credits,
                'theory_hours' => (int) $row->theory_hours,
                'practice_hours' => (int) $row->practice_hours,
                'department' => $row->department,
                'semester' => (int) $row->semester,
                'specialty_id' => $row->specialty_id,
                'is_required' => (bool) $row->is_required,
                'is_active' => (bool) $row->is_active,
                'chain' => null,
                'requires' => [],
                'required_by' => [],
            ];
        }

        $seriation = $this->addPrerequisites($plan, $grid);
        $chains = $this->chains($grid, $seriation);

        $semestersCount = max((int) $plan->semesters_count, (int) $rows->max('semester'));
        $semesters = array_fill_keys($semestersCount > 0 ? range(1, $semestersCount) : [], []);

        foreach ($grid as $row) {
            $semesters[$row['semester']][] = $row;
        }

        return [
            'semesters' => $semesters,
            'specialties' => $this->specialties($plan),
            'chains' => $chains,
            'semesters_count' => $semestersCount,
        ];
    }

    /**
     * Una fila de la retícula del plan que se ve en el editor: sin quitar y con su materia en el catálogo.
     *
     * @throws ModelNotFoundException<StudyPlanSubject> si no existe, es de otro plan o el id no es uuid
     */
    public function placementOrFail(StudyPlan $plan, string $id): StudyPlanSubject
    {
        return KeyLookup::findOrFail($this->visiblePlacements($plan)->select('study_plan_subjects.*'), $id);
    }

    /**
     * Una especialidad del plan, sin las quitadas.
     *
     * @throws ModelNotFoundException<Specialty> si no existe, es de otro plan o el id no es uuid
     */
    public function specialtyOrFail(StudyPlan $plan, string $id): Specialty
    {
        return KeyLookup::findOrFail(Specialty::query()->where('study_plan_id', $plan->getKey()), $id);
    }

    /**
     * Las filas sin quitar del plan cuya materia sigue en el catálogo.
     *
     * @return Builder<StudyPlanSubject>
     */
    private function visiblePlacements(StudyPlan $plan): Builder
    {
        return StudyPlanSubject::query()
            ->join('subjects', 'subjects.id', '=', 'study_plan_subjects.subject_id')
            ->whereNull('subjects.deleted_at')
            ->where('study_plan_subjects.study_plan_id', $plan->getKey());
    }

    /**
     * Llena `requires` y `required_by` con la seriación sin quitar del plan entre materias de `$grid`, y
     * devuelve las seriaciones (sin correquisitos) por materia, para las cadenas.
     *
     * @param  array<string, Row>  $grid
     * @return array<string, list<string>>
     */
    private function addPrerequisites(StudyPlan $plan, array &$grid): array
    {
        $prerequisites = SubjectPrerequisite::query()
            ->where('study_plan_id', $plan->getKey())
            ->toBase()
            ->get(['id', 'subject_id', 'prerequisite_subject_id', 'kind'])
            ->filter(fn (object $row): bool => isset($grid[$row->subject_id], $grid[$row->prerequisite_subject_id]))
            ->sortBy(fn (object $row): string => $grid[$row->prerequisite_subject_id]['code'].' '.$grid[$row->subject_id]['code']);

        $seriation = [];
        $requisite = fn (object $row, array $subject): array => [
            'prerequisite_id' => $row->id,
            'subject_id' => $subject['subject_id'],
            'code' => $subject['code'],
            'name' => $subject['name'],
            'semester' => $subject['semester'],
            'kind' => $row->kind,
        ];

        foreach ($prerequisites as $row) {
            $grid[$row->subject_id]['requires'][] = $requisite($row, $grid[$row->prerequisite_subject_id]);
            $grid[$row->prerequisite_subject_id]['required_by'][] = $requisite($row, $grid[$row->subject_id]);

            if ($row->kind === PrerequisiteKind::Seriacion->value) {
                $seriation[$row->subject_id][] = $row->prerequisite_subject_id;
            }
        }

        return $seriation;
    }

    /**
     * Las cadenas de seriación: grupos de materias unidas por su seriación (unión y búsqueda), en el orden de
     * su primera materia en la retícula. Deja en `chain` de cada materia de una cadena su índice.
     *
     * @param  array<string, Row>  $grid  en el orden de la retícula
     * @param  array<string, list<string>>  $seriation
     * @return list<array{index: int, name: string, size: int}>
     */
    private function chains(array &$grid, array $seriation): array
    {
        $ids = array_keys($grid);
        $root = array_combine($ids, $ids);
        $find = function (string $id) use (&$root): string {
            while ($root[$id] !== $id) {
                $id = $root[$id] = $root[$root[$id]];
            }

            return $id;
        };

        foreach ($seriation as $subjectId => $prerequisites) {
            foreach ($prerequisites as $prerequisite) {
                $root[$find($subjectId)] = $find($prerequisite);
            }
        }

        $groups = [];

        foreach ($ids as $id) {
            $groups[$find($id)][] = $id;
        }

        $chains = [];

        foreach (array_values(array_filter($groups, fn (array $members): bool => count($members) > 1)) as $index => $members) {
            foreach ($members as $id) {
                $grid[$id]['chain'] = $index;
            }

            $chains[] = ['index' => $index, 'name' => $grid[$members[0]]['name'], 'size' => count($members)];
        }

        return $chains;
    }

    /**
     * @return list<array{id: string, code: string, name: string, is_active: bool, subjects: int}>
     */
    private function specialties(StudyPlan $plan): array
    {
        return Specialty::query()
            ->where('study_plan_id', $plan->getKey())
            ->withCount('placements')
            ->orderBy('code')
            ->get(['id', 'code', 'name', 'is_active'])
            ->map(fn (Specialty $specialty): array => [
                'id' => $specialty->id,
                'code' => $specialty->code,
                'name' => $specialty->name,
                'is_active' => $specialty->is_active,
                'subjects' => (int) $specialty->placements_count,
            ])
            ->all();
    }
}
