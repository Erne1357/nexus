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
 * La semana publicada de un salón del campus (tarea 4.4, DESIGN.md, «Consulta publicada»): la misma forma que la del
 * docente, con el docente de cada bloque en lugar del salón, y el edificio en los datos. El salón es el uuid del
 * payload de lo publicado: uno mal formado o sin sesiones publicadas es 404, y también lo es en un campus que no usa
 * salones (`uses_rooms`). Solo lee de PublishedScheduleQuery.
 */
new #[Layout('layouts::public')] #[Title('Horarios')] class extends Component
{
    #[Locked]
    public string $periodo = '';

    #[Locked]
    public string $campus = '';

    #[Locked]
    public string $salon = '';

    #[Url(as: 'dia')]
    public int $day = 0;

    public function mount(string $periodo, string $campus, string $salon, PublishedContextQuery $contexts): void
    {
        abort_unless(Str::isUuid($salon), 404);

        $this->periodo = $periodo;
        $this->campus = $campus;
        $this->salon = $salon;
        $this->with($contexts);
    }

    /**
     * @return array<string, mixed>
     */
    public function with(PublishedContextQuery $contexts): array
    {
        $context = $contexts->resolve($this->periodo, $this->campus);
        $schedule = $context->schedule;
        $profile = $schedule->profile();
        $room = $profile->usesRooms ? array_find($schedule->rooms(), fn (array $row): bool => $row['id'] === $this->salon) : null;
        abort_if($room === null, 404);

        $groups = $schedule->room($this->salon);
        $week = PublishedWeek::week($groups, $profile);
        $days = $week['grid']->days;
        $day = array_find($days, fn (array $row): bool => $row['day'] === $this->day)['day'] ?? $days[0]['day'] ?? 1;

        return [
            ...$week,
            'mode' => 'room',
            'context' => $context,
            'title' => $room['name'] === $room['code'] ? $room['code'] : $room['code'].' · '.$room['name'],
            'subtitle' => $room['building'],
            'groups' => $groups,
            'profile' => $profile,
            'hours' => PublishedWeek::weeklyHours($groups),
            'selectedDay' => $day,
            'updated' => PublishedWeek::updatedOn($schedule->updatedAt($groups)),
            'backUrl' => route('published.index', ['periodo' => $this->periodo, 'campus' => $this->campus]),
        ];
    }
};
