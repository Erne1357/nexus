<?php

use App\Modules\Academic\Models\Department;
use App\Modules\AcademicLoad\Enums\AcademicLoadPermission;
use App\Modules\AcademicLoad\Queries\CareerProposalQuery;
use App\Modules\AcademicLoad\Queries\DepartmentInboxQuery;
use App\Modules\Core\Authorization\Actor;
use App\Modules\Core\Livewire\Concerns\WithModalityCampusFilter;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Queries\KeyLookup;
use App\Modules\Scheduling\Actions\Parallels\ArmParallelGroups;
use App\Modules\Scheduling\Actions\Parallels\ParallelArrangement;
use App\Modules\Scheduling\Queries\ParallelDraft;
use App\Modules\Scheduling\Queries\Parallels;
use App\Modules\Scheduling\Queries\ParallelsQuery;
use App\Modules\Scheduling\Queries\ParallelSubject;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Mary\Traits\Toast;

/**
 * La pestaña «Paralelos» de la bandeja del departamento (tarea 8.8, maqueta B «Pestaña Paralelos», elegida por el usuario
 * el 18 de noviembre de 2026; DESIGN.md, «Paralelos»). Es una pantalla de Scheduling, porque AcademicLoad no puede llamar
 * a Scheduling, que se presenta como la segunda pestaña de la bandeja: la misma modalidad (`?modalidad=`), el mismo
 * departamento (`?depto=`) y el mismo campus (`?campus=`, filtro) en la URL, y en el menú cuenta como la bandeja.
 *
 * - Arriba, cuatro cifras de las materias que se pueden juntar en el campus del filtro (Parallels::figures()): materias,
 *   grupos pedidos, los que bastan y los de menos. La búsqueda (`?buscar=`) y «Todas · Por armar · Armadas» (`?filtro=`).
 * - Un renglón por materia y campus con las carreras que cuentan, esperados, pedidos, bastan, de menos y su estado (por
 *   armar, armada o pendiente con su motivo); las que no tienen nada que juntar van plegadas con su motivo.
 * - El drawer de una materia: la matriz carrera × grupo (completos con su paso y un campo por mixto), la suma de esperados y
 *   cupos de cada grupo con su barra, los grupos que resultan y lo que pasa al armar, con la vista previa de ParallelDraft
 *   (el servidor la recalcula sin escribir en cada cambio). «Armar grupos» confirma con el diálogo de Core y llama a
 *   ArmParallelGroups; desde 1024 px se edita y debajo se consulta.
 *
 * Quién (ParallelsPolicy, en cada petición): la ven quienes ven la bandeja del departamento; arma quien tiene
 * `scheduling.parallels.arrange` (el jefe del departamento que imparte y el admin). El jefe de proyecto docente y
 * Subdirección la consultan; un departamento pedido por la URL que el usuario no ve es 403.
 */
new #[Title('Paralelos')] class extends Component
{
    use Toast;
    use WithModalityCampusFilter;

    private const array FILTERS = ['por-armar', 'armadas'];

    /** Código del departamento; '' es el primero que puede ver. */
    #[Url(as: 'depto', except: '')]
    public string $department = '';

    #[Url(as: 'buscar', except: '')]
    public string $search = '';

    /** '' (todas), 'por-armar' o 'armadas'. */
    #[Url(as: 'filtro', except: '')]
    public string $filter = '';

    /** El periodo, la modalidad y el departamento con los que entró. El navegador no los puede cambiar. */
    #[Locked]
    public ?string $termId = null;

    #[Locked]
    public ?string $modalityId = null;

    #[Locked]
    public ?string $departmentId = null;

    /**
     * Los departamentos del selector y, si no hay pestaña que mostrar, por qué.
     *
     * @var list<array{code: string, name: string}>
     */
    #[Locked]
    public array $departments = [];

    #[Locked]
    public ?string $message = null;

    public bool $showDrawer = false;

    /** La materia y el campus del drawer. */
    #[Locked]
    public ?string $subjectId = null;

    #[Locked]
    public ?string $subjectCampusId = null;

    /**
     * El armado que se edita (ParallelDraft lo limpia en cada petición): completos por carrera y, por columna de mixto,
     * esperados por carrera.
     *
     * @var array<string, mixed>
     */
    public array $full = [];

    /** @var list<array<string, mixed>> */
    public array $mixed = [];

    public function mount(Actor $context, DepartmentInboxQuery $inbox, CareerProposalQuery $proposals): void
    {
        abort_unless($context->allowsAny(AcademicLoadPermission::ProposalReviewView), 403);

        $this->updatedFilter();
        $this->resolve($context, $inbox, $proposals);
    }

    /**
     * Otra modalidad: la pestaña se abre de nuevo en ella, con el mismo departamento y el mismo campus si la otra lo tiene.
     */
    public function updatedModalidad(): void
    {
        $this->redirectRoute('scheduling.parallels', array_filter(['modalidad' => $this->requiredModality()->code, 'depto' => $this->department, 'campus' => $this->campusFilter()?->code]), navigate: true);
    }

    public function updatedDepartment(Actor $context, DepartmentInboxQuery $inbox, CareerProposalQuery $proposals): void
    {
        $this->closeDrawer();
        $this->resolve($context, $inbox, $proposals);
    }

    public function updatedFilter(): void
    {
        $this->filter = in_array($this->filter, self::FILTERS, true) ? $this->filter : '';
    }

    /**
     * Cualquier número que se mueve en el drawer suelta el error de la última vez que se intentó armar.
     */
    public function updated(string $property): void
    {
        if (str_starts_with($property, 'full') || str_starts_with($property, 'mixed')) {
            $this->resetErrorBag();
        }
    }

    /**
     * Abre el drawer de una materia en un campus con el armado que sugiere Nexus. Una que no es de la pestaña es 404.
     */
    public function open(string $subjectId, string $campusId, ParallelsQuery $query): void
    {
        $row = $this->parallels($query)->subject($subjectId, $campusId) ?? abort(404);

        $this->subjectId = $row->subject->getKey();
        $this->subjectCampusId = $row->campus->getKey();
        $this->useDraft(ParallelDraft::suggested($row));
        $this->showDrawer = true;
    }

    public function closeDrawer(): void
    {
        $this->showDrawer = false;
        $this->subjectId = $this->subjectCampusId = null;
        $this->full = $this->mixed = [];
        $this->resetErrorBag();
    }

    /**
     * Esc o el velo cierran el drawer por wire:model: entonces también se suelta el armado.
     */
    public function updatedShowDrawer(bool $open): void
    {
        if (! $open) {
            $this->closeDrawer();
        }
    }

    /**
     * Un completo más o uno menos de una carrera de la materia.
     */
    public function step(string $careerId, int $delta): void
    {
        if (! array_key_exists($careerId, $this->full)) {
            return;
        }

        $this->full[$careerId] = max(0, min(ArmParallelGroups::MAX_GROUPS, (is_numeric($this->full[$careerId]) ? (int) $this->full[$careerId] : 0) + ($delta <=> 0)));
        $this->resetErrorBag();
    }

    public function addMixed(): void
    {
        if ($this->subjectId !== null && count($this->mixed) < ArmParallelGroups::MAX_GROUPS) {
            $this->mixed[] = [];
        }
    }

    /**
     * Quita una columna de mixto: sus esperados se quedan sin lugar hasta ponerlos en otro grupo.
     */
    public function removeMixed(int $column): void
    {
        unset($this->mixed[$column]);
        $this->mixed = array_values($this->mixed);
        $this->resetErrorBag();
    }

    /**
     * «Usar lo de Nexus»: vuelve a la sugerencia.
     */
    public function useSuggestion(ParallelsQuery $query): void
    {
        $this->useDraft(ParallelDraft::suggested($this->openRow($this->parallels($query))));
    }

    /**
     * Arma los paralelos de la materia del drawer con el armado que se ve (ArmParallelGroups), con la policy de armar. Los
     * errores de la Action van a su lugar (`groups`, o `groups.{i}` en el grupo); al armar se cierra el drawer y el aviso
     * dice qué cambió y, si un grupo cancelado tenía horario, cuál se perdió.
     */
    public function arm(ParallelsQuery $query, ArmParallelGroups $arm): void
    {
        $parallels = $this->parallels($query);
        $this->authorize('arrange', [Parallels::class, $parallels->modality, $parallels->department]);
        $row = $this->openRow($parallels);
        $draft = ParallelDraft::of($row, $this->full, $this->mixed);
        $this->resetErrorBag();

        try {
            $result = $arm->handle($parallels->term, $parallels->modality, $parallels->department, $row->subject, $row->campus, $draft->arrangement(), auth()->user());
        } catch (ValidationException $e) {
            foreach ($e->errors() as $field => $messages) {
                $this->addError($field, $messages[0]);
            }

            $this->error('No se armaron los grupos', e(collect($e->errors())->flatten()->first()));

            return;
        }

        $this->closeDrawer();
        $title = "Armaste los paralelos de {$row->subject->code} en {$row->campus->code}";

        match (true) {
            $result->lostSchedules() !== [] => $this->warning($title, e($this->lostLine($result))),
            ! $result->changed() => $this->info($title, 'Ya estaban así: no cambió nada.'),
            default => $this->success($title, e($this->resultLine($result))),
        };
    }

    /**
     * @return array<string, mixed>
     */
    public function with(ParallelsQuery $query): array
    {
        $scope = $this->scopeViewData();
        $params = array_filter(['modalidad' => $scope['shownModality']->code, 'depto' => $this->department, 'campus' => $scope['shownCampus']?->code]);
        $tabs = ['inbox' => route('academic-load.inbox', $params), 'parallels' => route('scheduling.parallels', $params)];

        if ($this->termId === null) {
            return [...$scope, 'parallels' => null, 'tabs' => $tabs, 'pendingCount' => null];
        }

        $parallels = $this->parallels($query);
        $campusId = $scope['shownCampus']?->getKey();
        $joinable = $parallels->joinable($campusId);
        $row = $this->showDrawer && $this->subjectId !== null ? $parallels->subject($this->subjectId, (string) $this->subjectCampusId) : null;
        $draft = $row === null ? null : ParallelDraft::of($row, $this->full, $this->mixed);
        $canArrange = Gate::allows('arrange', [Parallels::class, $parallels->modality, $parallels->department]);
        $arranged = count(array_filter($joinable, fn (ParallelSubject $subject): bool => $subject->arranged()));

        return [
            ...$scope,
            'tabs' => $tabs,
            'parallels' => $parallels,
            'figures' => $parallels->figures($campusId),
            'hoursSaved' => array_sum(array_map(fn (ParallelSubject $subject): int => $subject->saved() * $this->weeklyHours($subject), $joinable)),
            'careerCount' => collect($joinable)->flatMap(fn (ParallelSubject $subject): array => $subject->careers)->map(fn ($career): string => $career->career->getKey())->unique()->count(),
            'campusCounts' => collect($parallels->joinable())->countBy(fn (ParallelSubject $subject): string => $subject->campus->code)->all(),
            'campusTotal' => count($parallels->joinable()),
            'filterCounts' => ['' => count($joinable), 'por-armar' => count($joinable) - $arranged, 'armadas' => $arranged],
            'pendingCount' => count($joinable) - $arranged,
            'visible' => $this->visible($joinable),
            'nothing' => $parallels->nothingToJoin($campusId),
            'canArrange' => $canArrange,
            'row' => $row,
            'draft' => $draft,
            'armBlocked' => $draft === null ? null : match (true) {
                ! $canArrange => "Solo consulta: arma los grupos la jefatura de {$parallels->department->name}.",
                $parallels->blockedReason !== null => $parallels->blockedReason,
                default => $draft->blockedReason(),
            },
            'editable' => $draft !== null && $canArrange && $parallels->blockedReason === null,
            'confirm' => $draft === null ? null : $this->confirmLine($draft),
            'hours' => $row === null ? 0 : $this->weeklyHours($row),
            'duration' => $this->duration(...),
        ];
    }

    /**
     * Deja en el drawer un armado ya limpio (la sugerencia).
     */
    private function useDraft(ParallelDraft $draft): void
    {
        $this->full = $draft->full;
        $this->mixed = $draft->mixed;
        $this->resetErrorBag();
    }

    /**
     * Resuelve con la credencial el periodo y con la página la modalidad y el departamento, como la bandeja (o por qué no hay
     * pestaña). Un departamento pedido por la URL que el usuario no ve es 403.
     */
    private function resolve(Actor $context, DepartmentInboxQuery $inbox, CareerProposalQuery $proposals): void
    {
        $this->termId = $this->modalityId = $this->departmentId = null;
        $this->departments = [];

        $modality = $this->requiredModality();
        $this->modalidad = $modality->code;
        $term = $context->activeTerm();

        if ($term === null) {
            $this->message = 'No hay un periodo activo: elige uno en tu credencial.';

            return;
        }

        if (! $proposals->termIsOpen($term, $modality)) {
            $this->message = "El periodo {$term->code} no está abierto en {$modality->name}.";

            return;
        }

        $departments = $inbox->departmentsFor($context, $modality, $term);
        $department = $this->department === '' ? $departments->first() : $departments->first(fn (Department $department): bool => $department->code === $this->department);

        abort_if($this->department !== '' && $department === null, 403);

        $this->departments = $departments->map(fn (Department $department): array => ['code' => $department->code, 'name' => "{$department->code} · {$department->name}"])->all();

        if ($department === null) {
            $this->message = "No tienes un departamento con bandeja en {$modality->name}.";

            return;
        }

        $this->department = $department->code;
        $this->termId = $term->getKey();
        $this->modalityId = $modality->getKey();
        $this->departmentId = $department->getKey();
        $this->message = null;
    }

    /**
     * La pestaña con la que entró la página, con la policy de verla: una petición puede llegar de una página que abrió otro
     * usuario.
     */
    private function parallels(ParallelsQuery $query): Parallels
    {
        abort_if($this->termId === null || $this->modalityId === null || $this->departmentId === null, 403);

        [$modality, $department] = app(DepartmentInboxQuery::class)->scopeOf($this->modalityId, $this->departmentId);
        $this->authorize('view', [Parallels::class, $modality, $department]);

        return $query->for(KeyLookup::findOrFail(Term::query(), $this->termId), $modality, $department);
    }

    /**
     * La materia del drawer; si salió de la pestaña entre una petición y otra, 404.
     */
    private function openRow(Parallels $parallels): ParallelSubject
    {
        abort_if($this->subjectId === null, 404);

        return $parallels->subject($this->subjectId, (string) $this->subjectCampusId) ?? abort(404);
    }

    /**
     * Las materias juntables de la lista, con la búsqueda y el filtro: las que faltan primero, de la que ahorra más a la que
     * menos, y luego por clave y campus.
     *
     * @param  list<ParallelSubject>  $joinable
     * @return list<ParallelSubject>
     */
    private function visible(array $joinable): array
    {
        $needle = Str::of($this->search)->ascii()->lower()->trim()->toString();

        $rows = array_filter($joinable, fn (ParallelSubject $subject): bool => match ($this->filter) {
            'por-armar' => ! $subject->arranged(),
            'armadas' => $subject->arranged(),
            default => true,
        } && ($needle === '' || Str::contains(Str::of("{$subject->subject->code} {$subject->subject->name}")->ascii()->lower()->toString(), $needle)));

        usort($rows, fn (ParallelSubject $a, ParallelSubject $b): int => [$a->arranged(), $b->saved(), $a->subject->code, $a->campus->code] <=> [$b->arranged(), $a->saved(), $b->subject->code, $b->campus->code]);

        return $rows;
    }

    private function weeklyHours(ParallelSubject $subject): int
    {
        return (int) $subject->subject->theory_hours + (int) $subject->subject->practice_hours;
    }

    /**
     * Lo que dice la confirmación de «Armar grupos»: cuántos grupos quedan, cuáles se quedan, nacen y se cancelan, y el
     * horario que se pierde.
     */
    private function confirmLine(ParallelDraft $draft): string
    {
        $kept = count(array_filter($draft->slots, fn ($slot): bool => $slot->keeps !== null));
        $created = $draft->size() - $kept;
        $cancelled = count($draft->cancelled);
        $lost = array_values(array_filter($draft->cancelled, fn ($group): bool => $group->minutes > 0));

        $parts = array_filter([
            $kept > 0 ? ($kept === 1 ? 'se queda 1 de los que ya existen' : "se quedan {$kept} de los que ya existen") : null,
            $created > 0 ? ($created === 1 ? 'nace 1 nuevo' : "nacen {$created} nuevos") : null,
            $cancelled > 0 ? ($cancelled === 1 ? 'se cancela 1' : "se cancelan {$cancelled}") : null,
        ]);
        $line = ($draft->size() === 1 ? 'Queda 1 grupo' : "Quedan {$draft->size()} grupos").': '.self::listOf(array_values($parts)).'.';

        if ($lost !== []) {
            $line .= ' Se pierde el horario de '.self::listOf(array_map(fn ($group): string => "{$group->code} ({$this->duration($group->minutes)})", $lost)).'.';
        }

        return $line.' Los cupos de cada carrera se escriben directo y se avisa a las carreras cuyos lugares cambian.';
    }

    private function resultLine(ParallelArrangement $result): string
    {
        $kept = count($result->kept);
        $created = count($result->created);
        $cancelled = count($result->cancelled);

        return self::listOf(array_values(array_filter([
            $kept > 0 ? ($kept === 1 ? '1 grupo se quedó' : "{$kept} grupos se quedaron") : null,
            $created > 0 ? ($created === 1 ? '1 nació' : "{$created} nacieron") : null,
            $cancelled > 0 ? ($cancelled === 1 ? '1 se canceló' : "{$cancelled} se cancelaron") : null,
        ]))).'. Se avisó a las carreras cuyos lugares cambiaron.';
    }

    private function lostLine(ParallelArrangement $result): string
    {
        $lost = array_map(fn (array $row): string => "{$row['group']->code} ({$this->duration($row['minutes'])})", $result->lostSchedules());

        return 'Se perdió el horario de '.self::listOf($lost).': sus sesiones quedan en la bitácora.';
    }

    /**
     * Minutos de horario como se dicen: «5 h», «1 h 30 min», «45 min».
     */
    private function duration(int $minutes): string
    {
        $hours = intdiv($minutes, 60);
        $rest = $minutes % 60;

        return match (true) {
            $hours === 0 => "{$rest} min",
            $rest === 0 => "{$hours} h",
            default => "{$hours} h {$rest} min",
        };
    }

    /**
     * @param  list<string>  $items
     */
    private static function listOf(array $items): string
    {
        $last = array_pop($items);

        return $items === [] ? (string) $last : implode(', ', $items)." y {$last}";
    }
};
