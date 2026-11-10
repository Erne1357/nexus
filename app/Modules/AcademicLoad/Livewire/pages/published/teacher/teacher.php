<?php

use App\Modules\AcademicLoad\Queries\PublishedContextQuery;
use App\Modules\AcademicLoad\Queries\PublishedWeek;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * La semana publicada de un docente en el campus (tarea 4.4, DESIGN.md, «Consulta publicada»): título con su nombre,
 * «N grupos · N horas a la semana» y la fecha de actualización; desde 768 px la semana con sus bloques (los que se
 * empalman, en carriles) y desde 1200 px la lista de sus grupos; en celular los días en botones y la lista de horas
 * del día (`?dia=`). El docente es el uuid del payload de lo publicado: uno mal formado o que no tiene grupos
 * publicados en el campus es 404. Solo lee de PublishedScheduleQuery.
 */
new #[Layout('layouts::public')] #[Title('Horarios')] class extends Component
{
    #[Locked]
    public string $periodo = '';

    #[Locked]
    public string $campus = '';

    #[Locked]
    public string $docente = '';

    #[Url(as: 'dia')]
    public int $day = 0;

    public function mount(string $periodo, string $campus, string $docente, PublishedContextQuery $contexts): void
    {
        abort_unless(Str::isUuid($docente), 404);

        $this->periodo = $periodo;
        $this->campus = $campus;
        $this->docente = $docente;
        $this->with($contexts);
    }

    /**
     * @return array<string, mixed>
     */
    public function with(PublishedContextQuery $contexts): array
    {
        $context = $contexts->resolve($this->periodo, $this->campus);
        $schedule = $context->schedule;
        $teacher = array_find($schedule->teachers(), fn (array $row): bool => $row['id'] === $this->docente);
        abort_if($teacher === null, 404);

        $groups = $schedule->teacher($this->docente);
        $profile = $schedule->profile();
        $week = PublishedWeek::week($groups, $profile);
        $days = $week['grid']->days;
        $day = array_find($days, fn (array $row): bool => $row['day'] === $this->day)['day'] ?? $days[0]['day'] ?? 1;

        return [
            ...$week,
            'mode' => 'teacher',
            'context' => $context,
            'title' => $teacher['name'],
            'subtitle' => null,
            'groups' => $groups,
            'profile' => $profile,
            'hours' => PublishedWeek::weeklyHours($groups),
            'selectedDay' => $day,
            'updated' => PublishedWeek::updatedOn($schedule->updatedAt($groups)),
            'backUrl' => route('published.index', ['periodo' => $this->periodo, 'campus' => $this->campus]),
        ];
    }
};
