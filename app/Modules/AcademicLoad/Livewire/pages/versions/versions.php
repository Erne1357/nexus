<?php

use App\Modules\AcademicLoad\Enums\AcademicLoadPermission;
use App\Modules\AcademicLoad\Exports\CampusWorkbook;
use App\Modules\AcademicLoad\Exports\DepartmentPdf;
use App\Modules\AcademicLoad\Models\AcademicLoad;
use App\Modules\AcademicLoad\Queries\LoadVersionsQuery;
use App\Modules\AcademicLoad\Queries\PublishedContextQuery;
use App\Modules\AcademicLoad\Queries\PublishedScheduleQuery;
use App\Modules\AcademicLoad\Rules\SnapshotDiff;
use App\Modules\AcademicLoad\Rules\VersionChanges;
use App\Modules\Core\Authorization\Actor;
use App\Modules\Core\Authorization\Target;
use App\Modules\Core\Livewire\Concerns\WithModalityScope;
use App\Modules\Core\Queries\CampusQuery;
use App\Modules\Core\Queries\KeyLookup;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Versiones publicadas (tarea 8.10; DESIGN.md «Versiones publicadas»: maqueta C «Línea y detalle», elegida por el usuario
 * el 17 de noviembre de 2026, en .impeccable/mocks/versiones/c.html): la trazabilidad de lo que vieron docentes y alumnos
 * en cada momento. Arriba, la línea de versiones de la carga (como la de «Publicación», de la primera a la vigente), con
 * la fecha, el motivo y lo que cambió; debajo, la versión elegida (`?version=`, la vigente sin elegir): quién la publicó y
 * cuándo, su comentario y la reapertura que la originó, sus cifras, a quién se avisó o se va a avisar (RA-05, RA-06: si
 * el aviso ya salió, LoadVersionsQuery::noticeChannels(), lo mismo que lee el drawer), sus archivos y lo que
 * cambió contra la anterior por tipo (VersionChanges). «Ver por docente o carrera» abre en un drawer lo que se le dijo a
 * cada uno.
 *
 * La carga es la del departamento (`?departamento=<id>`; el primero que ve sin elegir) en el periodo activo y en la
 * modalidad de la pantalla (`?modalidad=`, WithModalityScope; M1); el selector «Departamento» ofrece las cargas con alguna
 * versión que el usuario ve. Entra quien ve la carga (`academic_load.load.view`, AcademicLoadPolicy::view: el jefe de su
 * departamento, el jefe de proyecto docente, Subdirección y el admin); el coordinador y el docente no. Un departamento
 * cuya carga no ve es 403. Cada método vuelve a autorizar con la carga recargada. Solo lee (LoadVersionsQuery).
 *
 * Los archivos: el PDF del departamento y el Excel de la modalidad se arman con lo publicado hoy (PublishedScheduleQuery
 * lee la última versión de cada carga), así que solo se descargan en la versión vigente; las anteriores los muestran sin
 * enlace, con el motivo.
 */
new #[Title('Versiones publicadas')] class extends Component
{
    use WithModalityScope;

    /** El id del departamento; '' es el primero que puede ver. */
    #[Url(as: 'departamento', except: '')]
    public string $department = '';

    /** La versión elegida; null es la vigente. */
    #[Url(as: 'version', except: null)]
    public ?int $version = null;

    /** La carga con que entró la página. El navegador no la puede cambiar. */
    #[Locked]
    public ?string $loadId = null;

    /**
     * Las cargas del selector «Departamento», para quien ve varias.
     *
     * @var list<array{id: string, name: string}>
     */
    #[Locked]
    public array $departments = [];

    /** Por qué no hay versiones que mostrar. */
    #[Locked]
    public ?string $message = null;

    public bool $showAffected = false;

    protected ?AcademicLoad $loaded = null;

    public function mount(Actor $context, LoadVersionsQuery $query): void
    {
        abort_unless($context->allowsAny(AcademicLoadPermission::LoadView), 403);

        $this->resolve($context, $query);
    }

    public function updatedDepartment(Actor $context, LoadVersionsQuery $query): void
    {
        $this->version = null;
        $this->showAffected = false;
        $this->resolve($context, $query);
    }

    public function updatedModalidad(Actor $context, LoadVersionsQuery $query): void
    {
        $this->department = '';
        $this->updatedDepartment($context, $query);
    }

    /**
     * Elige una versión de la línea; una que la carga no tiene deja la vigente.
     */
    public function selectVersion(int $version): void
    {
        $load = $this->load();
        $this->authorize('view', $load);

        $this->version = $version >= 1 && $version <= $load->current_version ? $version : null;
        $this->showAffected = false;
    }

    /**
     * Abre «Ver por docente o carrera» de la versión elegida.
     */
    public function openAffected(): void
    {
        $this->authorize('view', $this->load());

        $this->showAffected = true;
    }

    /**
     * @return array<string, mixed>
     */
    public function with(LoadVersionsQuery $query, PublishedScheduleQuery $published, CampusQuery $campuses): array
    {
        $scope = ['modalityOptions' => $this->modalityOptions()];

        if ($this->loadId === null) {
            return ['load' => null, ...$scope];
        }

        $load = $this->load();
        $this->authorize('view', $load);

        $versions = $query->board($load);
        $number = $this->version !== null && $this->version <= $load->current_version ? $this->version : $load->current_version;
        $selected = collect($versions)->firstWhere('version', $number) ?? ($versions === [] ? null : $versions[array_key_last($versions)]);

        if ($selected === null) {
            return ['load' => null, 'message' => 'La carga figura con versión publicada, pero no hay ninguna foto que mostrar.', ...$scope];
        }

        $diff = $query->diff($load, $selected['version']);
        $next = collect($versions)->firstWhere('version', $selected['version'] + 1);
        $notices = $selected['legacy'] || $diff === null
            ? ['bell' => false, 'mail' => false, 'sent' => false]
            : $query->noticeChannels($load, $selected['publishedAt'], $next['publishedAt'] ?? null);

        return [
            'load' => $load,
            'versions' => $versions,
            'current' => $load->current_version,
            'selected' => $selected,
            'diff' => $diff,
            'sections' => $diff === null ? [] : VersionChanges::byKind($diff),
            'notices' => $notices,
            'affected' => $this->showAffected && $diff !== null ? $this->affected($diff, $query, $notices) : null,
            'until' => $next['publishedAt'] ?? null,
            'campuses' => $campuses->of($load->modality)->keyBy('code'),
            'files' => $this->files($load, $published),
            ...$scope,
        ];
    }

    /**
     * Lo que se le dijo a cada docente y a cada carrera: solo los que están en el diff de la versión (RA-05), y si el aviso
     * ya salió y por dónde les llegó a los docentes (`$notices`, LoadVersionsQuery::noticeChannels(), lo mismo que lee el
     * resumen; nada en una versión de antes de los avisos).
     *
     * @param  array<string, mixed>  $diff
     * @param  array{bell: bool, mail: bool, sent: bool}  $notices
     * @return array{teachers: list<array{name: string, lines: list<string>}>, careers: list<array{code: string, name: ?string, lines: list<string>}>, channels: array{bell: bool, mail: bool, sent: bool}}
     */
    private function affected(array $diff, LoadVersionsQuery $query, array $notices): array
    {
        $careers = SnapshotDiff::careers($diff);

        return [
            'teachers' => VersionChanges::byTeacher($diff),
            'careers' => VersionChanges::byCareer($diff, $query->careerNames(array_keys($careers)), $careers),
            'channels' => $notices,
        ];
    }

    /**
     * Los archivos de lo publicado hoy de la carga: el PDF del departamento y el Excel de la modalidad, con su enlace si
     * el usuario los puede descargar (PublishedExportPolicy) o el motivo si no.
     *
     * @return list<array{kind: string, label: string, icon: string, filename: ?string, url: ?string, reason: ?string}>
     */
    private function files(AcademicLoad $load, PublishedScheduleQuery $published): array
    {
        $request = Request::create('/', 'GET', ['departamento' => $load->department_id]);
        $parameters = ['periodo' => $load->term->code, 'modalidad' => PublishedContextQuery::modalityKey($load->modality)];
        $file = fn (string $kind, string $type, string $label, string $icon, ?object $export, array $query): array => [
            'kind' => $kind,
            'label' => $label,
            'icon' => $icon,
            'filename' => $export?->filename(),
            'url' => $export !== null && Gate::allows('download', $export) ? route('published.export', [...$parameters, 'tipo' => $type, ...$query]) : null,
            'reason' => $export === null ? 'No hay nada publicado que exportar.' : (Gate::allows('download', $export) ? null : 'No tienes permiso para descargarlo.'),
        ];

        return [
            $file('pdf', 'departamento', DepartmentPdf::label(), 'o-document-arrow-down', DepartmentPdf::fromRequest($published, $load->term, $load->modality, $request), ['departamento' => $load->department_id]),
            $file('excel', 'excel', CampusWorkbook::label(), 'o-table-cells', CampusWorkbook::fromRequest($published, $load->term, $load->modality, $request), []),
        ];
    }

    /**
     * Elige la carga: la del departamento de `?departamento=` (403 si el usuario no ve la carga de ese departamento en la
     * modalidad) o la primera que ve con versiones, en el periodo activo y la modalidad de la pantalla.
     */
    private function resolve(Actor $context, LoadVersionsQuery $query): void
    {
        $this->loadId = null;
        $this->loaded = null;
        $this->departments = [];
        $this->message = null;

        $modality = $this->requiredModality();
        $this->modalidad = $modality->code;
        $term = $context->activeTerm();

        if ($term === null) {
            $this->message = 'No hay un periodo activo: elige uno en tu credencial.';

            return;
        }

        $loads = $query->loadsWithVersions($context, $term, $modality);
        $load = $this->department === '' ? $loads->first() : $loads->first(fn (AcademicLoad $load): bool => $load->department_id === $this->department);

        abort_if($load === null && $this->department !== '' && ! (Str::isUuid($this->department) && $context->allows(AcademicLoadPermission::LoadView, Target::of(modality: $modality->getKey(), unit: $this->department))), 403);

        $this->departments = $loads->map(fn (AcademicLoad $load): array => [
            'id' => $load->department_id,
            'name' => "{$load->department->code} · {$load->department->name} — {$load->status->label()} · v{$load->current_version}",
        ])->values()->all();

        if ($load === null) {
            $this->message = $this->department === ''
                ? "Ninguna carga que ves en {$modality->name} tiene versiones publicadas en {$term->name}."
                : "La carga de ese departamento en {$modality->name} todavía no tiene versiones publicadas en {$term->name}.";

            return;
        }

        $this->department = $load->department_id;
        $this->loadId = $load->getKey();
        $this->loaded = $load;
    }

    /**
     * La carga de la página, recargada por su id.
     */
    private function load(): AcademicLoad
    {
        abort_if($this->loadId === null, 403);

        return $this->loaded ??= KeyLookup::findOrFail(AcademicLoad::query()->with(['modality', 'term', 'department' => fn ($query) => $query->withTrashed()]), $this->loadId);
    }
};
