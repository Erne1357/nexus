<?php

use App\Modules\AcademicLoad\Queries\PublishedContext;
use App\Modules\AcademicLoad\Queries\PublishedContextQuery;
use App\Modules\AcademicLoad\Queries\PublishedWeek;
use App\Modules\AcademicLoad\Rules\PublishedThrottle;
use App\Modules\Core\Support\WeekGrid;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Un semestre de una carrera publicado (tarea 4.4, DESIGN.md, «Consulta publicada», variante A «Fichas por
 * materia»): una sección por materia, en orden de clave, con las fichas de sus grupos y la mini-semana de cada uno.
 * Carrera, retícula y semestre son el estado de la URL (`/horarios/{periodo}/{modalidad}/carrera/{carrera}?campus=&reticula=&semestre=`;
 * M1: lo publicado es por modalidad y el campus es un filtro); cambiar uno navega a la URL nueva. Una carrera que no tiene
 * nada publicado en la modalidad (o en el campus del filtro) es 404. Solo lee de
 * PublishedScheduleQuery.
 */
new #[Layout('layouts::public')] #[Title('Horarios')] class extends Component
{
    #[Locked]
    public string $periodo = '';

    /** La clave de la modalidad en la URL (`pres`, M1). */
    #[Locked]
    public string $modalidad = '';

    /** El campus del filtro (`?campus=`, clave); '' son todos. */
    #[Locked]
    #[Url(as: 'campus')]
    public string $campus = '';

    /** Código de la carrera de la URL; cambiarlo navega. */
    public string $carrera = '';

    #[Url(as: 'reticula')]
    public string $plan = '';

    #[Url(as: 'semestre')]
    public string $semester = '';

    /**
     * El contexto, la carrera y la retícula resueltos una sola vez por petición (privado: Livewire no lo serializa).
     *
     * @var array{context: PublishedContext, entry: array<string, mixed>, plan: array<string, mixed>}|null
     */
    private ?array $resolved = null;

    public function mount(string $periodo, string $modalidad, string $carrera, PublishedContextQuery $contexts): void
    {
        $this->periodo = $periodo;
        $this->modalidad = $modalidad;
        $this->carrera = $carrera;
        $this->resolve($contexts, coerce: false);
    }

    public function hydrate(): void
    {
        PublishedThrottle::hit();
    }

    /**
     * Otra carrera: su retícula vigente y el mismo semestre si lo tiene, si no el primero.
     */
    public function updatedCarrera(PublishedContextQuery $contexts): void
    {
        $this->plan = '';
        $this->resolved = null;
        $this->resolve($contexts, coerce: true);
        $this->redirect($this->url(), navigate: true);
    }

    /**
     * Otra retícula: el mismo semestre si la retícula lo tiene, si no el primero.
     */
    public function updatedPlan(PublishedContextQuery $contexts): void
    {
        $this->resolved = null;
        $this->resolve($contexts, coerce: true);
    }

    /**
     * Fija la retícula que se ve (la pedida, o la de código mayor) y el semestre: sin semestre, o al cambiar de carrera
     * o retícula (`$coerce`) cuando ya no lo tiene, el primero de la retícula. Un semestre de la URL que no tiene grupos
     * se respeta: la página lo dice.
     */
    private function resolve(PublishedContextQuery $contexts, bool $coerce): void
    {
        $plan = $this->resolution($contexts)['plan'];
        $this->plan = $plan['plan']['code'];

        if ($this->semesterNumber() === 0 || ($coerce && ! in_array($this->semesterNumber(), $plan['semesters'], true))) {
            $this->semester = (string) $plan['semesters'][0];
        }
    }

    /**
     * @return array{context: PublishedContext, entry: array{career: array{id: string, code: string, name: string}, plans: list<array{plan: array{id: string, code: string}, semesters: list<int>}>}, plan: array{plan: array{id: string, code: string}, semesters: list<int>}}
     */
    private function resolution(PublishedContextQuery $contexts): array
    {
        if ($this->resolved !== null) {
            return $this->resolved;
        }

        $context = $contexts->resolve($this->periodo, $this->modalidad, $this->campus);
        $entry = array_find($context->schedule->careers(), fn (array $row): bool => $row['career']['code'] === $this->carrera);
        abort_if($entry === null, 404);

        $plan = array_find($entry['plans'], fn (array $row): bool => $row['plan']['code'] === $this->plan) ?? $entry['plans'][array_key_last($entry['plans'])];

        return $this->resolved = ['context' => $context, 'entry' => $entry, 'plan' => $plan];
    }

    /**
     * El semestre de la URL como número; 0 si no es un número (`?semestre=abc`), y entonces se toma el primero.
     */
    private function semesterNumber(): int
    {
        return ctype_digit($this->semester) ? (int) $this->semester : 0;
    }

    private function url(): string
    {
        return route('published.semester', [...$this->routeParameters(), 'carrera' => $this->carrera, 'reticula' => $this->plan, 'semestre' => $this->semester]);
    }

    /**
     * @return array<string, mixed>
     */
    public function with(PublishedContextQuery $contexts): array
    {
        ['context' => $context, 'entry' => $entry, 'plan' => $plan] = $this->resolution($contexts);
        $schedule = $context->schedule;
        $groups = $schedule->semester($entry['career']['id'], $plan['plan']['id'], $this->semesterNumber());
        $profile = $schedule->profile();

        $subjects = [];

        foreach ($groups as $group) {
            $subjects[$group->subject['code']]['subject'] = $group->subject;
            $subjects[$group->subject['code']]['groups'][] = $group;
        }

        ksort($subjects);

        return [
            'context' => $context,
            'career' => $entry['career'],
            'plans' => $entry['plans'],
            'currentPlan' => $plan,
            'subjects' => array_values($subjects),
            'chains' => PublishedWeek::chains($groups),
            'groupCount' => count($groups),
            'profile' => $profile,
            'days' => WeekGrid::of($profile)->covering(PublishedWeek::sessions($groups))->days,
            'updated' => PublishedWeek::updatedOn($schedule->updatedAt($groups)),
            'careerOptions' => array_map(fn (array $row): array => ['id' => $row['career']['code'], 'name' => $row['career']['name']], $schedule->careers()),
            'planOptions' => array_map(fn (array $row): array => ['id' => $row['plan']['code'], 'name' => $row['plan']['code']], array_reverse($entry['plans'])),
            'semesterOptions' => array_map(fn (int $semester): array => ['id' => $semester, 'name' => 'Semestre '.$semester], $plan['semesters']),
            'backUrl' => route('published.index', $this->routeParameters()),
            'pdfUrl' => $groups === [] ? null : route('published.semester.pdf', [...$this->routeParameters(), 'carrera' => $this->carrera, 'reticula' => $this->plan, 'semestre' => $this->semester]),
        ];
    }

    /**
     * El periodo, la modalidad y, si hay, el campus del filtro, para las rutas de la consulta.
     *
     * @return array<string, string>
     */
    private function routeParameters(): array
    {
        return ['periodo' => $this->periodo, 'modalidad' => $this->modalidad, ...($this->campus === '' ? [] : ['campus' => $this->campus])];
    }
};
