<?php

use App\Modules\Academic\Actions\SetSubjectRequiredSpace;
use App\Modules\Academic\Enums\RequiredSpace;
use App\Modules\Academic\Models\Department;
use App\Modules\Academic\Models\Subject;
use App\Modules\Core\Authorization\Actor;
use App\Modules\Core\Models\Modality;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\User;
use App\Modules\Core\Queries\KeyLookup;
use App\Modules\Facilities\Actions\UpdateRoom;
use App\Modules\Facilities\Enums\RoomType;
use App\Modules\Facilities\Models\Room;
use App\Modules\Staffing\Actions\Selection\CancelSelection;
use App\Modules\Staffing\Actions\Selection\CreateSelection;
use App\Modules\Staffing\Actions\Selection\ImportSelectionCsv;
use App\Modules\Staffing\Actions\Selection\PublishSelection;
use App\Modules\Staffing\Actions\Selection\RecomputeSelectionOrder;
use App\Modules\Staffing\Actions\Selection\ResolveSelectionEntry;
use App\Modules\Staffing\Actions\Selection\ScheduleSelection;
use App\Modules\Staffing\Actions\Selection\SetSelectionLoads;
use App\Modules\Staffing\Enums\SelectionEntrySource;
use App\Modules\Staffing\Enums\SelectionStatus;
use App\Modules\Staffing\Enums\StaffingPermission;
use App\Modules\Staffing\Models\Selection;
use App\Modules\Staffing\Models\SelectionEntry;
use App\Modules\Staffing\Queries\SelectionGroupsQuery;
use App\Modules\Staffing\Queries\SelectionMonitorQuery;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithFileUploads;
use Mary\Traits\Toast;

/**
 * Orden de selección (tarea 1.33, diseño 6.8; maqueta admin elegida por el usuario): el admin prepara la selección de
 * un departamento en siete pasos (alcance, CSV, tipo de espacio de las materias, tipo de los salones, horario de
 * turnos, vista previa del orden y publicar) y, ya publicada, la sigue en un monitor que se refresca cada 10 s.
 *
 * Solo con `staffing.selection.manage` (admin). Todo lo que escribe pasa por una Action; el CSV se lee de la carga
 * temporal y se borra enseguida: nunca se guarda ni se registra (lleva datos personales).
 */
new #[Title('Orden de selección')] class extends Component
{
    use Toast, WithFileUploads;

    public const int STEPS = 7;

    public string $termId = '';

    public string $modalityId = '';

    public string $departmentId = '';

    #[Url(as: 'seleccion', except: '')]
    public string $selectionId = '';

    public int $step = 1;

    /** 'preparar' o 'monitor'. */
    public string $tab = 'preparar';

    public $csvFile = null;

    /** 'todas', 'pendientes' o 'resueltas'. */
    public string $csvFilter = 'todas';

    /** @var array<string, string> renglón => docente elegido en su select */
    public array $picks = [];

    /** @var array<string, string> renglón => docente parecido que propuso la importación */
    public array $candidates = [];

    /** @var array<string, bool> renglones que el admin confirmó a mano */
    public array $confirmed = [];

    /** @var list<array{line: int, message: string}> */
    public array $csvErrors = [];

    public string $startsOn = '';

    public string $startsAt = '13:00';

    public string $windowStarts = '09:00';

    public string $windowEnds = '14:00';

    /** @var list<int> */
    public array $weekdays = [1, 2, 3, 4, 5];

    public bool $showPublish = false;

    public bool $showCancel = false;

    public function mount(string $seleccion = ''): void
    {
        abort_unless(app(Actor::class)->allowsAny(StaffingPermission::SelectionManage), 403);

        $this->termId = (string) Term::query()->where('is_current', true)->value('id');
        $this->modalityId = (string) Modality::query()->where('is_active', true)->orderBy('name')->value('id');

        if ($seleccion !== '') {
            $this->selectionId = $seleccion;
        }

        $selection = $this->selection();

        if ($selection !== null) {
            $this->fillFrom($selection);
        }
    }

    /**
     * Abre la selección del periodo, modalidad y departamento elegidos (la viva, si ya hay) o crea el borrador.
     */
    public function start(CreateSelection $create): void
    {
        $this->validate([
            'termId' => ['required', 'exists:terms,id'],
            'modalityId' => ['required', 'exists:modalities,id'],
            'departmentId' => ['required', 'exists:departments,id'],
        ], attributes: ['termId' => 'periodo', 'modalityId' => 'modalidad', 'departmentId' => 'departamento']);

        $existing = Selection::query()
            ->where('term_id', $this->termId)
            ->where('modality_id', $this->modalityId)
            ->where('department_id', $this->departmentId)
            ->where('status', '!=', SelectionStatus::Cancelled->value)
            ->first();

        $selection = $existing ?? $create->handle(KeyLookup::findOrFail(Term::query(), $this->termId), KeyLookup::findOrFail(Modality::query(), $this->modalityId), KeyLookup::findOrFail(Department::query(), $this->departmentId), $this->actor());

        $this->authorizeManage($selection);
        $this->selectionId = (string) $selection->getKey();
        $this->fillFrom($selection);
    }

    public function goTo(int $step): void
    {
        if ($this->selection() === null) {
            return;
        }

        $this->step = max(1, min(self::STEPS, $step));
    }

    public function next(): void
    {
        $selection = $this->managed();

        if ($this->blockers($selection, $this->step) !== []) {
            return;
        }

        $this->goTo($this->step + 1);
    }

    public function removeLoad(string $loadId): void
    {
        $selection = $this->managed();
        $keep = $selection->loads()->pluck('academic_loads.id')->reject(fn (string $id): bool => $id === $loadId)->values()->all();

        app(SetSelectionLoads::class)->handle($selection, $keep, $this->actor());
    }

    public function importCsv(): void
    {
        $selection = $this->managed();

        $this->validate(['csvFile' => ['required', 'file', 'max:2048', 'extensions:csv,txt']], attributes: ['csvFile' => 'archivo']);

        $contents = (string) file_get_contents($this->csvFile->getRealPath());
        $this->csvFile->delete();
        $this->csvFile = null;

        $result = app(ImportSelectionCsv::class)->handle($selection, $contents, $this->actor());

        $this->csvErrors = $result['errors'];
        $this->confirmed = [];
        $this->picks = [];
        $this->candidates = [];

        foreach ($result['unresolved'] as $row) {
            $this->candidates[$row['entry_id']] = $row['candidates'][0] ?? '';
            $this->picks[$row['entry_id']] = $row['candidates'][0] ?? '';
        }
    }

    public function updatedPicks(mixed $value, string $entryId): void
    {
        if ($value !== '' && $value !== null) {
            $this->resolve($entryId, (string) $value);
        }
    }

    /**
     * Liga un renglón del CSV con un docente; con null lo descarta de la cola (SEL-06).
     */
    public function resolve(string $entryId, ?string $teacherId): void
    {
        $selection = $this->managed();
        $entry = KeyLookup::findOrFail(SelectionEntry::query()->where('selection_id', $selection->getKey()), $entryId);
        $teacher = $teacherId === null || $teacherId === '' ? null : KeyLookup::findOrFail(RecomputeSelectionOrder::pool($selection), $teacherId);

        app(ResolveSelectionEntry::class)->handle($entry, $teacher, $this->actor());

        unset($this->picks[$entryId], $this->candidates[$entryId]);

        if ($teacher !== null) {
            $this->confirmed[$entryId] = true;
        }
    }

    public function setSpace(string $subjectId, string $space): void
    {
        $selection = $this->managed();
        $subject = KeyLookup::findOrFail(Subject::query()->whereIn('id', app(SelectionGroupsQuery::class)->of($selection)->select('subject_id')), $subjectId);
        Gate::authorize('update', $subject);

        app(SetSubjectRequiredSpace::class)->handle($subject, RequiredSpace::from($space), $this->actor());
    }

    public function setRoomType(string $roomId, string $type): void
    {
        $selection = $this->managed();
        $room = KeyLookup::findOrFail($this->rooms($selection), $roomId);
        Gate::authorize('update', $room);

        app(UpdateRoom::class)->handle($room, ['type' => RoomType::from($type)->value]);
    }

    public function toggleDay(int $day): void
    {
        $days = in_array($day, $this->weekdays, true) ? array_diff($this->weekdays, [$day]) : [...$this->weekdays, $day];
        sort($days);
        $this->weekdays = array_values($days);

        $this->schedule();
    }

    public function updated(string $name): void
    {
        if (in_array($name, ['startsOn', 'startsAt', 'windowStarts', 'windowEnds'], true) && $this->selectionId !== '') {
            $this->schedule();
        }
    }

    /**
     * Guarda la ventana de turnos y recalcula las horas (SEL-08); la vista previa sale de lo guardado.
     */
    public function schedule(): void
    {
        $selection = $this->managed();

        if ($this->startsOn === '') {
            return;
        }

        try {
            $startsAt = CarbonImmutable::createFromFormat('Y-m-d H:i', "{$this->startsOn} {$this->startsAt}", config('app.timezone'));
        } catch (Throwable) {
            $startsAt = false;
        }

        if ($startsAt === false) {
            $this->addError('startsOn', 'Escribe la fecha y la hora de inicio.');

            return;
        }

        app(ScheduleSelection::class)->handle($selection, $startsAt, $this->windowStarts, $this->windowEnds, $this->weekdays, $this->actor());
    }

    public function publish(): void
    {
        $selection = $this->managed();

        try {
            app(PublishSelection::class)->handle($selection, $this->actor());
        } catch (ValidationException $e) {
            $this->addError('publish', implode(' ', array_merge(...array_values($e->errors()))));
            $this->showPublish = false;

            return;
        }

        $this->showPublish = false;
        $this->tab = 'monitor';
        $this->success('Orden publicado.', 'Los docentes ya ven su turno.');
    }

    public function cancel(): void
    {
        $selection = $this->managed();

        try {
            app(CancelSelection::class)->handle($selection, $this->actor());
        } catch (ValidationException $e) {
            $this->addError('publish', implode(' ', array_merge(...array_values($e->errors()))));
            $this->showCancel = false;

            return;
        }

        $this->showCancel = false;
        $this->selectionId = '';
        $this->step = 1;
        $this->tab = 'preparar';
        $this->info('Selección cancelada.');
    }

    public function with(): array
    {
        $selection = $this->selection();
        $data = [
            'selection' => $selection,
            'terms' => Term::query()->orderByDesc('starts_on')->get(['id', 'name', 'code']),
            'modalities' => Modality::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'departments' => Department::query()->where('is_active', true)->orderBy('code')->get(['id', 'code', 'name']),
        ];

        if ($selection === null) {
            return $data + ['blockers' => [], 'monitor' => null, 'draft' => false];
        }

        $selection->load('term:id,name', 'modality:id,name', 'department:id,code,name');
        $draft = $selection->status === SelectionStatus::Draft;
        $data['selection'] = $selection;
        $data['draft'] = $draft;
        $data['blockers'] = $draft ? $this->blockers($selection, $this->step) : [];

        if ($draft) {
            $data += $this->stepData($selection);
        }

        $data['monitor'] = $selection->status !== SelectionStatus::Draft && $selection->status !== SelectionStatus::Cancelled
            ? app(SelectionMonitorQuery::class)->for($selection, CarbonImmutable::now())
            : null;

        return $data;
    }

    public function setTab(string $tab): void
    {
        $this->tab = $tab === 'monitor' ? 'monitor' : 'preparar';
    }

    private function selection(): ?Selection
    {
        return $this->selectionId === '' ? null : KeyLookup::find(Selection::query(), $this->selectionId);
    }

    private function managed(): Selection
    {
        $selection = KeyLookup::findOrFail(Selection::query(), $this->selectionId);
        $this->authorizeManage($selection);

        return $selection;
    }

    private function authorizeManage(Selection $selection): void
    {
        Gate::authorize('manage', $selection);
    }

    private function actor(): User
    {
        return Auth::user();
    }

    private function fillFrom(Selection $selection): void
    {
        $this->termId = (string) $selection->term_id;
        $this->modalityId = (string) $selection->modality_id;
        $this->departmentId = (string) $selection->department_id;

        if ($selection->starts_at !== null) {
            $this->startsOn = $selection->starts_at->format('Y-m-d');
            $this->startsAt = $selection->starts_at->format('H:i');
        }

        if ($selection->window_starts !== null) {
            $this->windowStarts = substr((string) $selection->window_starts, 0, 5);
            $this->windowEnds = substr((string) $selection->window_ends, 0, 5);
            $this->weekdays = array_map('intval', $selection->weekdays ?? $this->weekdays);
        }

        if ($selection->status !== SelectionStatus::Draft) {
            $this->tab = 'monitor';
        }
    }

    /**
     * Lo que sale por paso: renglones del CSV, materias, salones, turnos y orden.
     *
     * @return array<string, mixed>
     */
    private function stepData(Selection $selection): array
    {
        $groups = app(SelectionGroupsQuery::class);
        $entries = $selection->entries()->with('teacher:id,name')->orderBy('position')->get();
        $csv = $entries->where('source', SelectionEntrySource::Csv)->sortBy('csv_line')->values();
        $unresolved = $csv->whereNull('teacher_id')->count();

        $subjects = Subject::query()
            ->whereIn('id', $groups->of($selection)->select('subject_id'))
            ->orderBy('code')
            ->get(['id', 'code', 'name', 'required_space']);

        $counts = $groups->of($selection)->selectRaw('subject_id, count(*) as n')->groupBy('subject_id')->pluck('n', 'subject_id');

        return [
            'loads' => $selection->loads()->with('department:id,code,name')->get(),
            'facts' => [
                'groups' => $groups->of($selection)->count(),
                'hours' => (int) $groups->of($selection)->join('subjects', 'subjects.id', '=', 'groups.subject_id')->sum(DB::raw('subjects.theory_hours + subjects.practice_hours')),
                'teachers' => RecomputeSelectionOrder::pool($selection)->count(),
            ],
            'csvRows' => $csv,
            'unresolved' => $unresolved,
            'appended' => $entries->where('source', SelectionEntrySource::Appended)->values(),
            'poolTeachers' => RecomputeSelectionOrder::pool($selection)->orderBy('name')->get(['id', 'name']),
            'subjects' => $subjects,
            'groupCounts' => $counts,
            'ownRooms' => $this->rooms($selection)->where('department_id', $selection->department_id)->get(),
            'freeRooms' => $this->rooms($selection)->whereNull('department_id')->get(),
            'entries' => $entries,
            'slots' => $this->slotPreview($entries, $selection),
        ];
    }

    /**
     * Salones que se ofrecen: los del departamento y los sin dueño de los campus de los grupos (SEL-01).
     *
     * @return Builder<Room>
     */
    private function rooms(Selection $selection): Builder
    {
        return Room::query()
            ->with('building:id,code')
            ->where('is_active', true)
            ->whereIn('type', [RoomType::Aula->value, RoomType::Laboratorio->value, RoomType::SalaComputo->value])
            ->whereIn('campus_id', app(SelectionGroupsQuery::class)->of($selection)->select('campus_id'))
            ->where(fn ($query) => $query->whereNull('department_id')->orWhere('department_id', $selection->department_id))
            ->orderBy('code');
    }

    /**
     * @param  Collection<int, SelectionEntry>  $entries
     * @return array{days: list<array{key: string, label: string}>, times: list<string>, cells: array<string, list<int>>, turns: int, people: int, ties: int, last: ?string, extra: ?string}|null
     */
    private function slotPreview($entries, Selection $selection): ?array
    {
        $timed = $entries->whereNotNull('slot_starts_at')->values();

        if ($timed->isEmpty()) {
            return null;
        }

        $cells = [];
        $days = [];
        $times = [];

        foreach ($timed as $entry) {
            $day = $entry->slot_starts_at->format('Y-m-d');
            $time = $entry->slot_starts_at->format('H:i');
            $days[$day] = ucfirst($entry->slot_starts_at->locale('es')->isoFormat('dddd D'));
            $times[$time] = true;
            $cells["{$day} {$time}"][] = (int) $entry->position;
        }

        ksort($days);
        ksort($times);

        $last = $timed->sortByDesc('slot_starts_at')->first();
        $extra = $selection->extra_round_starts_at ?? $selection->first_round_ends_at;

        return [
            'days' => array_map(fn (string $key, string $label): array => ['key' => $key, 'label' => $label], array_keys($days), $days),
            'times' => array_keys($times),
            'cells' => $cells,
            'turns' => $timed->pluck('slot_index')->unique()->count(),
            'people' => $timed->count(),
            'ties' => count(array_filter($cells, fn (array $positions): bool => count($positions) > 1)),
            'last' => $last->slot_starts_at->locale('es')->isoFormat('dddd D [de] MMMM [a las] H:mm'),
            'extra' => $extra?->locale('es')->isoFormat('dddd D [de] MMMM [a las] H:mm'),
        ];
    }

    /**
     * Lo que impide pasar al paso siguiente (vacío = se puede).
     *
     * @return list<string>
     */
    private function blockers(Selection $selection, int $step): array
    {
        return match ($step) {
            1 => $selection->loads()->exists() ? [] : ['Deja al menos una carga incluida.'],
            2 => $this->csvBlockers($selection),
            3 => ($n = $this->unreviewed($selection)) > 0 ? ["Faltan {$n} ".($n === 1 ? 'materia' : 'materias').' por revisar.'] : [],
            5 => $selection->starts_at === null ? ['Elige cuándo empieza.'] : ($selection->starts_at->isFuture() ? [] : ['La fecha de inicio debe estar en el futuro.']),
            default => [],
        };
    }

    /**
     * @return list<string>
     */
    private function csvBlockers(Selection $selection): array
    {
        if (! $selection->entries()->exists()) {
            return ['Sube el CSV de criterios.'];
        }

        $n = $selection->entries()->whereNull('teacher_id')->count();

        return $n > 0 ? [$n === 1 ? 'Falta 1 fila por resolver.' : "Faltan {$n} filas por resolver."] : [];
    }

    private function unreviewed(Selection $selection): int
    {
        return Subject::query()->whereNull('required_space')->whereIn('id', app(SelectionGroupsQuery::class)->of($selection)->select('subject_id'))->count();
    }
};
