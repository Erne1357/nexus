<?php

use App\Modules\AcademicLoad\Enums\AcademicLoadPermission;
use App\Modules\AcademicLoad\Exports\CampusWorkbook;
use App\Modules\AcademicLoad\Exports\Sii\SiiFilesExport;
use App\Modules\AcademicLoad\Models\AcademicLoad;
use App\Modules\AcademicLoad\Queries\PublishedContextQuery;
use App\Modules\AcademicLoad\Queries\PublishedScheduleQuery;
use App\Modules\AcademicLoad\Queries\PublishedWeek;
use App\Modules\AcademicLoad\Rules\PublishedThrottle;
use App\Modules\Core\Authorization\Actor;
use App\Modules\Core\Authorization\Target;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Inicio de la consulta pública de horarios publicados (tarea 4.4, DESIGN.md, «Consulta publicada», variante A
 * «Fichas por materia»): lo principal es «Por carrera» (cada carrera con su retícula y los botones de semestre que
 * tienen grupos); a un lado, el buscador de docentes y el de salones. Solo lee de PublishedScheduleQuery. El
 * periodo y la modalidad son `?periodo=&modalidad=` (M1); sin ellos, la primera modalidad del primer periodo publicado.
 * `?campus=` filtra los grupos de ese campus; sin él, los de todos. Quien tiene sesión y puede ver las cargas de la
 * modalidad ve, además, qué departamentos faltan por publicar (P7).
 */
new #[Layout('layouts::public')] #[Title('Horarios')] class extends Component
{
    #[Url(as: 'periodo')]
    public string $periodo = '';

    #[Url(as: 'modalidad')]
    public string $modalidad = '';

    /** El campus del filtro (clave); '' son todos. */
    #[Url(as: 'campus')]
    public string $campus = '';

    /** La retícula elegida de cada carrera, por código de carrera; sin elegir, la de código mayor. */
    /** @var array<string, string> la retícula elegida de cada carrera (código de carrera → código de retícula) */
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
        $context = $contexts->initial($this->periodo, $this->modalidad, $this->campus);

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

        $unpublished = array_map(fn (AcademicLoad $load): array => [
            'department' => $load->department->code.' · '.$load->department->name,
            'status' => $load->status->label(),
        ], array_values(array_filter(
            $actor->user() === null ? [] : $published->unpublished($context->term, $context->modality),
            fn (AcademicLoad $load): bool => $actor->allows(AcademicLoadPermission::LoadView, Target::of(modality: $load->modality_id, unit: $load->department_id)),
        )));

        $staffParameters = ['periodo' => $context->term->code, 'modalidad' => PublishedContextQuery::modalityKey($context->modality)];

        return [
            'context' => $context,
            'careers' => $careers,
            'usesRooms' => $profile->usesRooms,
            'updated' => PublishedWeek::updatedOn($schedule->updatedAt($schedule->groups())),
            'teachers' => $teacherNeedle === '' ? [] : array_slice(array_values(array_filter($schedule->teachers(), fn (array $teacher): bool => str_contains(PublishedWeek::fold($teacher['name']), $teacherNeedle))), 0, 8),
            'rooms' => ! $profile->usesRooms || $roomNeedle === '' ? [] : array_slice(array_values(array_filter($schedule->rooms(), fn (array $room): bool => str_contains(PublishedWeek::fold($room['code'].' '.$room['name'].' '.$room['building']), $roomNeedle))), 0, 8),
            'unpublished' => $unpublished,
            'routeParameters' => $context->routeParameters(),
            'staffExports' => $actor->user() !== null && $actor->allowsAny(CampusWorkbook::requiredPermission()) && ($workbook = CampusWorkbook::fromRequest($published, $context->term, $context->modality, request())) !== null && Gate::allows('download', $workbook) ? [
                'excel' => route('published.export', [...$staffParameters, 'tipo' => 'excel']),
                'department' => route('published.export', [...$staffParameters, 'tipo' => 'departamento']),
                'departments' => collect($schedule->loads)->map(fn (array $load): array => $load['department'])->sortBy('code')->values()->all(),
                'sii' => ($sii = SiiFilesExport::fromRequest($published, $context->term, $context->modality, request())) !== null && Gate::allows('download', $sii)
                    ? route('published.export', [...$staffParameters, 'tipo' => 'sii'])
                    : null,
            ] : null,
        ];
    }
};
