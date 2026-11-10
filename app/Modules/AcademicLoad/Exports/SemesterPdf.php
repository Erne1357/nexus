<?php

namespace App\Modules\AcademicLoad\Exports;

use App\Modules\AcademicLoad\Queries\PublishedGroup;
use App\Modules\AcademicLoad\Queries\PublishedSchedule;
use App\Modules\AcademicLoad\Queries\PublishedScheduleQuery;
use App\Modules\AcademicLoad\Queries\PublishedSession;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Term;

/**
 * El PDF de una carrera, retícula y semestre (tarea 4.5): la tabla tipo SII que eligió el usuario el 10 de noviembre de
 * 2026, en carta horizontal. Un renglón por materia y, debajo, uno por grupo con su docente y una columna por día del
 * perfil del campus con sus horas, su salón y si es práctica o virtual. Público.
 */
final class SemesterPdf extends PublishedPdf
{
    public function __construct(
        string $termId,
        string $campusId,
        string $termCode,
        string $campusCode,
        string $version,
        public readonly string $careerId,
        public readonly string $careerCode,
        public readonly string $planId,
        public readonly string $planCode,
        public readonly int $semester,
    ) {
        parent::__construct($termId, $campusId, $termCode, $campusCode, $version);
    }

    /**
     * El PDF de esa carrera, retícula (por clave) y semestre si están publicados; null si no, para responder 404 sin
     * encolar nada.
     */
    public static function find(PublishedScheduleQuery $query, Term $term, Campus $campus, string $careerCode, string $planCode, int $semester): ?self
    {
        $version = $query->version($term, $campus);

        if ($version === null) {
            return null;
        }

        foreach ($query->schedule($term, $campus)->careers() as $career) {
            if ($career['career']['code'] !== $careerCode) {
                continue;
            }

            foreach ($career['plans'] as $plan) {
                if ($plan['plan']['code'] === $planCode && in_array($semester, $plan['semesters'], true)) {
                    return new self($term->getKey(), $campus->getKey(), $term->code, $campus->code, $version, $career['career']['id'], $careerCode, $plan['plan']['id'], $planCode, $semester);
                }
            }
        }

        return null;
    }

    public function filename(): string
    {
        return self::clean("Horario {$this->careerCode} semestre {$this->semester} - {$this->termCode} {$this->campusCode}").'.pdf';
    }

    protected function kind(): string
    {
        return 'semestre';
    }

    protected function subject(): array
    {
        return [$this->careerId, $this->planId, $this->semester];
    }

    protected function document(PublishedSchedule $schedule, Term $term, Campus $campus): array
    {
        $groups = $schedule->semester($this->careerId, $this->planId, $this->semester);
        $career = collect($schedule->careers())->firstWhere('career.id', $this->careerId)['career'] ?? ['name' => $this->careerCode];

        return ['academic-load::pdf.semester', [
            'title' => $career['name'],
            'subtitle' => "Semestre {$this->semester} · Retícula {$this->planCode}",
            'updated' => self::updated($schedule->updatedAt($groups)),
            'days' => self::days($schedule),
            'subjects' => self::subjects($groups, $schedule),
        ], 'landscape'];
    }

    /**
     * @return list<array{day: int, name: string}>
     */
    private static function days(PublishedSchedule $schedule): array
    {
        $names = [1 => 'Lunes', 2 => 'Martes', 3 => 'Miércoles', 4 => 'Jueves', 5 => 'Viernes', 6 => 'Sábado'];

        return array_map(fn (int $day): array => ['day' => $day, 'name' => $names[$day] ?? (string) $day], $schedule->profile()->days);
    }

    /**
     * Las materias del semestre en orden de clave, cada una con su color y sus grupos.
     *
     * @param  list<PublishedGroup>  $groups
     * @return list<array{code: string, name: string, credits: int, color: string, groups: list<array{code: string, teacher: string, cells: array<int, list<array{hours: string, place: string, kind: ?string}>>}>}>
     */
    public static function subjects(array $groups, PublishedSchedule $schedule): array
    {
        $usesTimeBlocks = $schedule->profile()->usesTimeBlocks;
        $subjects = [];

        foreach ($groups as $group) {
            $code = $group->subject['code'];
            $subjects[$code] ??= [
                'code' => $code,
                'name' => $group->subject['name'],
                'credits' => $group->subject['credits'],
                'color' => self::CHAINS[count($subjects) % count(self::CHAINS)],
                'groups' => [],
            ];

            $cells = [];

            foreach ($usesTimeBlocks ? $group->sessions : [] as $session) {
                /** @var PublishedSession $session */
                $cells[$session->day][] = self::session($session);
            }

            $subjects[$code]['groups'][] = ['code' => $group->code, 'teacher' => self::teacherOf($group), 'cells' => $cells];
        }

        return array_values($subjects);
    }
}
