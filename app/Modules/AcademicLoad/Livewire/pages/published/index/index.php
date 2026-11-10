<?php

use App\Modules\AcademicLoad\Enums\AcademicLoadPermission;
use App\Modules\AcademicLoad\Models\AcademicLoad;
use App\Modules\AcademicLoad\Queries\PublishedContextQuery;
use App\Modules\AcademicLoad\Queries\PublishedScheduleQuery;
use App\Modules\AcademicLoad\Queries\PublishedWeek;
use App\Modules\AcademicLoad\Rules\PublishedThrottle;
use App\Modules\Core\Authorization\Actor;
use App\Modules\Core\Authorization\Target;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Inicio de la consulta pública de horarios publicados (tarea 4.4, DESIGN.md, «Consulta publicada», variante A
 * «Fichas por materia»): lo principal es «Por carrera» (cada carrera con su retícula y los botones de semestre que
 * tienen grupos); a un lado, el buscador de docentes y el de salones. Solo lee de PublishedScheduleQuery. El
 * periodo y el campus son `?periodo=&campus=`; sin ellos, el primer campus del primer periodo publicado. Quien tiene
 * sesión y puede ver las cargas del campus ve, además, qué departamentos faltan por publicar (P7).
 */
new #[Layout('layouts::public')] #[Title('Horarios')] class extends Component
{
    #[Url(as: 'periodo')]
    public string $periodo = '';

    #[Url(as: 'campus')]
    public string $campus = '';

    /** La retícula elegida de cada carrera, por código de carrera; sin elegir, la de código mayor. */
    public array $plans = [];

    public string $teacherSearch = '';

    public string $roomSearch = '';

    public function hydrate(): void
    {
        PublishedThrottle::hit();
    }

    /**
     * @return array<string, mixed>
     */
    public function with(PublishedContextQuery $contexts, PublishedScheduleQuery $published, Actor $actor): array
    {
        $context = $contexts->initial($this->periodo, $this->campus);

        if ($context === null) {
            return ['context' => null];
        }

        $schedule = $context->schedule;
        $profile = $schedule->profile();
        $teacherNeedle = PublishedWeek::fold(trim($this->teacherSearch));
        $roomNeedle = PublishedWeek::fold(trim($this->roomSearch));

        $careers = array_map(function (array $entry): array {
            $plans = array_reverse($entry['plans']);
            $entry['plans'] = $plans;

            return $entry;
        }, $schedule->careers());

        $campusId = $context->campus->getKey();
        $unpublished = array_map(fn (AcademicLoad $load): array => [
            'department' => $load->department->code.' · '.$load->department->name,
            'status' => $load->status->label(),
        ], array_values(array_filter(
            $actor->user() === null ? [] : $published->unpublished($context->term, $context->campus),
            fn (AcademicLoad $load): bool => $actor->allows(AcademicLoadPermission::LoadView, Target::of(campus: $campusId, unit: $load->department_id)),
        )));

        return [
            'context' => $context,
            'careers' => $careers,
            'usesRooms' => $profile->usesRooms,
            'updated' => PublishedWeek::updatedOn($schedule->updatedAt($schedule->groups())),
            'teachers' => $teacherNeedle === '' ? [] : array_slice(array_values(array_filter($schedule->teachers(), fn (array $teacher): bool => str_contains(PublishedWeek::fold($teacher['name']), $teacherNeedle))), 0, 8),
            'rooms' => ! $profile->usesRooms || $roomNeedle === '' ? [] : array_slice(array_values(array_filter($schedule->rooms(), fn (array $room): bool => str_contains(PublishedWeek::fold($room['code'].' '.$room['name'].' '.$room['building']), $roomNeedle))), 0, 8),
            'unpublished' => $unpublished,
        ];
    }
};
