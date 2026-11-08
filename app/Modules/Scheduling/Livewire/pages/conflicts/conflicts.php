<?php

use App\Modules\Core\Authorization\Actor;
use App\Modules\Scheduling\Enums\SchedulingPermission;
use App\Modules\Scheduling\Jobs\RecomputeScheduleConflicts;
use App\Modules\Scheduling\Queries\BoardCount;
use App\Modules\Scheduling\Queries\ConflictBoardQuery;
use App\Modules\Scheduling\Queries\GroupBoardStatus;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Route;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Mary\Traits\Toast;

/**
 * Tablero de choques y avance (tarea 3.4): qué le falta a la carga del campus activo en el periodo activo para cerrar
 * (HC-15). La vista principal es por carrera, retícula y semestre, y la otra, la matriz por departamento con su
 * drawer (decidido por el usuario el 7 de noviembre de 2026). Lo ven quienes ven la carga y el coordinador de su
 * carrera en su campus (`scheduling.schedule.view`); ConflictBoardQuery filtra por ese alcance. Cada método público
 * vuelve a autorizar, porque la petición puede llegar con una pantalla que abrió otra sesión.
 *
 * Cada pendiente enlaza a la pantalla que lo corrige: el editor de horarios (`scheduling.editor`, tarea 3.5) y la
 * asignación docente (`staffing.assign`, tarea 3.9), con `?grupo=<id>`. Mientras una no existe, su enlace queda
 * deshabilitado con el motivo.
 */
new #[Title('Choques y avance')] class extends Component
{
    use Toast;

    /** 'reticula' (carrera, retícula y semestre) o 'departamentos' (matriz). */
    #[Url(as: 'vista', except: 'reticula')]
    public string $view = 'reticula';

    /** La carrera de la vista de retícula; vacío, la primera. */
    #[Url(as: 'carrera', except: '')]
    public string $career = '';

    /** La celda elegida, «<plan>:<semestre>». */
    #[Url(as: 'celda', except: '')]
    public string $cell = '';

    public bool $showDrawer = false;

    /** El departamento del drawer; el navegador no lo puede cambiar. */
    #[Locked]
    public ?string $departmentId = null;

    /** Hasta cuándo (marca Unix) la página se refresca sola tras «Recalcular». */
    public int $pollUntil = 0;

    public function mount(Actor $context): void
    {
        $this->authorizeView($context);
        $this->view = $this->normalizedView();
    }

    /**
     * @return array<string, mixed>
     */
    public function with(ConflictBoardQuery $board, Actor $context): array
    {
        $this->authorizeView($context);

        $term = $context->activeTerm();
        $campus = $context->activeCampus();
        $links = ['editor' => Route::has('scheduling.editor'), 'assign' => Route::has('staffing.assign')];

        if ($term === null || $campus === null) {
            return ['term' => $term, 'campus' => $campus, 'summary' => null, 'links' => $links];
        }

        $statuses = $board->statuses($context, $term, $campus);
        $careers = $board->byCareer($statuses);
        $careerId = $this->careerIn($careers);
        $grid = $careerId === null ? null : $board->grid($statuses, $careerId);
        [$cellPlan, $cellSemester] = $this->cellIn($grid);

        return [
            'term' => $term,
            'campus' => $campus,
            'links' => $links,
            'syncedAt' => $board->lastSyncedAt($term, $campus),
            'summary' => $board->summary($statuses),
            'careers' => $careers,
            'careerId' => $careerId,
            'careerCount' => $careerId === null ? null : BoardCount::of($statuses->filter(fn (GroupBoardStatus $status): bool => $status->countsFor($careerId))),
            'grid' => $grid,
            'cellPlan' => $cellPlan,
            'cellSemester' => $cellSemester,
            'cellDetail' => $cellPlan === null ? null : $board->detail($this->inCell($statuses, $careerId, $cellPlan['id'], $cellSemester)),
            'cellCount' => $cellPlan === null ? null : ($cellPlan['cells'][$cellSemester] ?? new BoardCount),
            'departments' => $this->normalizedView() === 'departamentos' ? $board->byDepartment($statuses) : [],
            'drawer' => $this->showDrawer ? $this->drawer($board, $statuses) : null,
        ];
    }

    public function updatedView(): void
    {
        $this->view = $this->normalizedView();
        $this->closeDrawer();
    }

    /**
     * Esc o el velo cierran el drawer por wire:model: entonces también se olvida el departamento.
     */
    public function updatedShowDrawer(bool $open): void
    {
        if (! $open) {
            $this->closeDrawer();
        }
    }

    /**
     * Lo que pide wire:poll mientras corre un recálculo: solo vuelve a pintar.
     */
    public function refreshBoard(): void
    {
        if (now()->getTimestamp() >= $this->pollUntil) {
            $this->pollUntil = 0;
        }
    }

    public function updatedCareer(): void
    {
        $this->cell = '';
    }

    public function selectCell(string $planId, int $semester, Actor $context): void
    {
        $this->authorizeView($context);
        $key = "{$planId}:{$semester}";
        $this->cell = $this->cell === $key ? '' : $key;
    }

    public function openDepartment(string $id, ConflictBoardQuery $board, Actor $context): void
    {
        $this->authorizeView($context);

        $term = $context->activeTerm() ?? abort(404);
        $campus = $context->activeCampus() ?? abort(404);

        abort_unless($board->hasDepartment($context, $term, $campus, $id), 404);

        $this->departmentId = $id;
        $this->showDrawer = true;
    }

    public function closeDrawer(): void
    {
        $this->showDrawer = false;
        $this->departmentId = null;
    }

    public function recompute(Actor $context): void
    {
        $this->authorizeView($context);

        $term = $context->activeTerm() ?? abort(404);
        $campus = $context->activeCampus() ?? abort(404);

        RecomputeScheduleConflicts::dispatch($term->id, $campus->id);
        $this->pollUntil = now()->addSeconds(30)->getTimestamp();

        $this->success('Recalculando los choques', 'Los conteos se actualizan en unos segundos.');
    }

    private function normalizedView(): string
    {
        return $this->view === 'departamentos' ? 'departamentos' : 'reticula';
    }

    private function authorizeView(Actor $context): void
    {
        abort_unless($context->allowsAny(SchedulingPermission::ScheduleView), 403);
    }

    /**
     * La carrera elegida si el usuario ve grupos suyos; si no, la primera.
     *
     * @param  list<array{id: string, code: string, name: string, count: BoardCount}>  $careers
     */
    private function careerIn(array $careers): ?string
    {
        $ids = array_column($careers, 'id');

        if (in_array($this->career, $ids, true)) {
            return $this->career;
        }

        return $ids[0] ?? null;
    }

    /**
     * El plan y semestre de la celda elegida, si existe en la cuadrícula.
     *
     * @param  array{plans: list<array<string, mixed>>, semesters: int}|null  $grid
     * @return array{0: array<string, mixed>|null, 1: int|null}
     */
    private function cellIn(?array $grid): array
    {
        if ($grid === null || ! str_contains($this->cell, ':')) {
            return [null, null];
        }

        [$planId, $semester] = explode(':', $this->cell, 2);
        $semester = (int) $semester;
        $plan = collect($grid['plans'])->firstWhere('id', $planId);

        return $plan === null || $semester < 1 || $semester > $grid['semesters'] ? [null, null] : [$plan, $semester];
    }

    /**
     * @param  Collection<int, GroupBoardStatus>  $statuses
     * @return Collection<int, GroupBoardStatus>
     */
    private function inCell(Collection $statuses, string $careerId, string $planId, int $semester): Collection
    {
        return $statuses->filter(fn (GroupBoardStatus $status): bool => $status->placedIn($careerId, $planId, $semester));
    }

    /**
     * @param  Collection<int, GroupBoardStatus>  $statuses
     * @return array<string, mixed>|null
     */
    private function drawer(ConflictBoardQuery $board, Collection $statuses): ?array
    {
        if ($this->departmentId === null) {
            return null;
        }

        $row = collect($board->byDepartment($statuses->where('departmentId', $this->departmentId)))->first();

        return $row === null ? null : [...$row, 'detail' => $board->detail($statuses->where('departmentId', $this->departmentId))];
    }
};
