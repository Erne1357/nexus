<?php

namespace App\Modules\AcademicLoad\Exports;

use App\Modules\AcademicLoad\Queries\PublishedGroup;
use App\Modules\AcademicLoad\Queries\PublishedSchedule;
use App\Modules\AcademicLoad\Queries\PublishedScheduleQuery;
use App\Modules\AcademicLoad\Queries\PublishedSession;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Modality;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Support\WeekGrid;

/**
 * El PDF de una carrera, retícula y semestre (tarea 4.5): la tabla tipo SII que eligió el usuario el 10 de noviembre de
 * 2026, en carta horizontal. Un renglón por materia y, debajo, uno por grupo con su docente y una columna por día del
 * perfil de la modalidad con sus horas, su salón y si es práctica o virtual; el campus de cada grupo va junto a su clave
 * (M1). Público.
 */
final class SemesterPdf extends PublishedPdf
{
    public function __construct(
        string $termId,
        string $modalityId,
        string $termCode,
        string $modalityCode,
        array $snapshotIds,
        public readonly string $careerId,
        public readonly string $careerCode,
        public readonly string $planId,
        public readonly string $planCode,
        public readonly int $semester,
        ?string $campusCode = null,
    ) {
        parent::__construct($termId, $modalityId, $termCode, $modalityCode, $snapshotIds, $campusCode);
    }

    /**
     * El PDF de esa carrera, retícula (por clave) y semestre si están publicados en la modalidad (y en el campus del
     * filtro, si hay); null si no, para responder 404 sin encolar nada.
     */
    public static function find(PublishedScheduleQuery $query, Term $term, Modality $modality, ?Campus $campus, string $careerCode, string $planCode, int $semester): ?self
    {
        $ids = $query->snapshotIds($term, $modality);

        foreach ($ids === [] ? [] : $query->scheduleOf($term, $modality, $ids)->onlyCampus($campus?->code)->careers() as $career) {
            if ($career['career']['code'] !== $careerCode) {
                continue;
            }

            foreach ($career['plans'] as $plan) {
                if ($plan['plan']['code'] === $planCode && in_array($semester, $plan['semesters'], true)) {
                    return new self($term->getKey(), $modality->getKey(), $term->code, $modality->code, $ids, $career['career']['id'], $careerCode, $plan['plan']['id'], $planCode, $semester, $campus?->code);
                }
            }
        }

        return null;
    }

    public function filename(): string
    {
        return self::clean("Horario {$this->careerCode} semestre {$this->semester} - {$this->termCode} {$this->place()}").'.pdf';
    }

    protected function kind(): string
    {
        return 'semestre';
    }

    protected function subject(): array
    {
        return [$this->careerId, $this->planId, $this->semester];
    }

    protected function document(PublishedSchedule $schedule, Term $term, Modality $modality): array
    {
        $groups = $schedule->semester($this->careerId, $this->planId, $this->semester);
        $career = collect($schedule->careers())->firstWhere('career.id', $this->careerId)['career'] ?? ['name' => $this->careerCode];

        return ['academic-load::pdf.semester', [
            'title' => $career['name'],
            'subtitle' => "Semestre {$this->semester} · Retícula {$this->planCode}",
            'updated' => self::updated($schedule->updatedAt($groups)),
            'days' => self::days($groups, $schedule),
            'subjects' => self::subjects($groups, $schedule),
        ], 'landscape'];
    }

    /**
     * Los días del perfil de la modalidad más los de las sesiones que caen fuera (un sábado), como las páginas.
     *
     * @param  list<PublishedGroup>  $groups
     * @return list<array{day: int, name: string}>
     */
    public static function days(array $groups, PublishedSchedule $schedule): array
    {
        $ranges = [];

        foreach ($groups as $group) {
            foreach ($group->sessions as $session) {
                $ranges[] = ['day' => $session->day, 'starts_at' => $session->startsAt, 'ends_at' => $session->endsAt];
            }
        }

        return array_map(fn (array $day): array => ['day' => $day['day'], 'name' => $day['name']], WeekGrid::of($schedule->profile())->covering($ranges)->days);
    }

    /**
     * Las materias del semestre en orden de clave, cada una con su color y sus grupos.
     *
     * @param  list<PublishedGroup>  $groups
     *                                        La capacidad y la marca de préstamo solo las pinta el PDF del personal (DepartmentPdf).
     * @return list<array{code: string, name: string, credits: int, color: string, groups: list<array{code: string, campus: ?string, teacher: string, capacity: int, cells: array<int, list<array{hours: string, place: string, kind: ?string, loan: bool}>>}>}>
     */
    public static function subjects(array $groups, PublishedSchedule $schedule): array
    {
        $usesTimeBlocks = $schedule->profile()->usesTimeBlocks;
        $colors = self::colors($groups);
        $subjects = [];

        foreach ($groups as $group) {
            $code = $group->subject['code'];
            $subjects[$code] ??= [
                'code' => $code,
                'name' => $group->subject['name'],
                'credits' => $group->subject['credits'],
                'color' => $colors[$code],
                'groups' => [],
            ];

            $cells = [];

            foreach ($usesTimeBlocks ? $group->sessions : [] as $session) {
                /** @var PublishedSession $session */
                $cells[$session->day][] = [...self::session($session), 'loan' => $session->isLoanedTo($group->departmentId)];
            }

            $subjects[$code]['groups'][] = ['code' => $group->code, 'campus' => $group->campus['code'] ?? null, 'teacher' => self::teacherOf($group), 'capacity' => $group->capacity, 'cells' => $cells];
        }

        return array_values($subjects);
    }
}
