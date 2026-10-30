<?php

use App\Modules\Academic\Actions\CreateSpecialty;
use App\Modules\Academic\Actions\RemoveSpecialty;
use App\Modules\Academic\Actions\UpdateSpecialty;
use App\Modules\Academic\Models\Specialty;
use App\Modules\Academic\Models\StudyPlan;
use App\Modules\Academic\Queries\PlanGridEditorQuery;
use App\Modules\Academic\Queries\StudyPlanQuery;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;
use Mary\Traits\Toast;

/**
 * Retícula de un plan (tarea 1.8; DESIGN.md, "Retícula del plan (editor)", variante A aprobada el 29 de
 * octubre de 2026): el encabezado del plan, sus especialidades y sus materias en columnas por semestre, con su
 * seriación y sus cadenas. Tocar una materia la selecciona, resalta su cadena y la abre en el panel lateral
 * (`x-drawer`): su lugar, lo que pide y quién la pide. En el mismo panel se gestionan las especialidades. Es del
 * admin.
 *
 * El componente lee con PlanGridEditorQuery, autoriza `view` del plan en mount() y en cada lectura, y
 * `editGrid` en cada método que escribe (una petición puede llegar con una página que abrió otra sesión);
 * valida la forma de los datos y llama a las Actions, que hacen cumplir las reglas. Los ids que llegan del
 * navegador se buscan dentro del plan: una fila o una especialidad de otro plan no existe.
 *
 * Las tareas 1.8.9 (poner, mover y quitar materias) y 1.8.10 (seriación) llenan las secciones del panel de una
 * materia: ver academic::plan.subject-panel.
 */
new #[Title('Retícula del plan')] class extends Component
{
    use Toast;

    /** El plan de la retícula. El navegador no lo puede cambiar. */
    #[Locked]
    public string $planId;

    /** La fila de la retícula (`study_plan_subjects.id`) que está en el panel; null sin materia elegida. */
    #[Locked]
    public ?string $selectedId = null;

    /** La cadena resaltada desde la leyenda (su índice); null sin ninguna. */
    #[Locked]
    public ?int $chainFocus = null;

    /** Lo que muestra el panel: 'subject' (la materia elegida) o 'specialties'. */
    #[Locked]
    public string $panel = 'subject';

    /** El panel abierto; el navegador lo cierra (Esc, el velo o la X) y eso quita la selección. */
    public bool $showDrawer = false;

    /** Clave de la especialidad nueva. */
    public string $code = '';

    /** Nombre de la especialidad nueva. */
    public string $name = '';

    /** La especialidad que se renombra; null si ninguna. */
    #[Locked]
    public ?string $renamingId = null;

    public string $renamedName = '';

    public function mount(string $plan, StudyPlanQuery $plans): void
    {
        $model = $plans->findOrFail($plan);
        $this->authorize('view', $model);
        $this->planId = $model->id;
    }

    /**
     * @return array<string, mixed>
     */
    public function with(StudyPlanQuery $plans, PlanGridEditorQuery $grids): array
    {
        $plan = $plans->findWithCareerOrFail($this->planId);
        $this->authorize('view', $plan);
        $grid = $grids->forPlan($plan);
        $rows = array_merge(...array_values($grid['semesters']));
        $selected = $this->selectedId === null ? null : collect($rows)->firstWhere('placement_id', $this->selectedId);

        // Otra sesión quitó la materia que estaba en el panel, o una cadena dejó de existir.
        if ($this->selectedId !== null && $selected === null) {
            $this->selectedId = null;
            $this->showDrawer = $this->showDrawer && $this->panel === 'specialties';
        }

        if ($this->chainFocus !== null && ! isset($grid['chains'][$this->chainFocus])) {
            $this->chainFocus = null;
        }

        return [
            'plan' => $plan,
            'grid' => $grid,
            'subjectsCount' => count($rows),
            'specialtiesById' => array_column($grid['specialties'], null, 'id'),
            'summaries' => array_map(fn (array $semester): array => $this->summary($semester), $grid['semesters']),
            'selected' => $selected,
            ...$this->highlight($grid, $rows, $selected),
        ];
    }

    /**
     * Abre en el panel la materia de la fila `$id` y resalta su cadena.
     */
    public function select(string $id, StudyPlanQuery $plans, PlanGridEditorQuery $grids): void
    {
        $placement = $grids->placementOrFail($this->plan($plans), $id);

        $this->selectedId = $placement->id;
        $this->chainFocus = null;
        $this->panel = 'subject';
        $this->showDrawer = true;
    }

    /**
     * Resalta la cadena `$chain` de la leyenda; tocarla otra vez la quita.
     */
    public function focusChain(int $chain, StudyPlanQuery $plans): void
    {
        $this->plan($plans);

        $this->chainFocus = $this->chainFocus === $chain ? null : $chain;
        $this->selectedId = null;
        $this->showDrawer = $this->showDrawer && $this->panel === 'specialties';
    }

    public function clearFocus(StudyPlanQuery $plans): void
    {
        $this->plan($plans);

        $this->chainFocus = null;
    }

    /**
     * El navegador solo cierra el panel (Esc, el velo o la X), y al cerrarlo se quitan la materia elegida y lo
     * que se estaba renombrando. Abrirlo es de select() y openSpecialties(): una petición que lo abre sin una
     * materia elegida lo deja cerrado, y una que vuelve a abrir las especialidades pide `editGrid`, como
     * openSpecialties(). `showDrawer` no puede ser #[Locked] porque el x-drawer lo cierra desde el navegador.
     */
    public function updatedShowDrawer(bool $open): void
    {
        if (! $open) {
            $this->reset('selectedId', 'renamingId', 'renamedName');

            return;
        }

        if ($this->panel === 'specialties') {
            $this->authorize('editGrid', $this->plan(app(StudyPlanQuery::class)));

            return;
        }

        if ($this->selectedId === null) {
            $this->showDrawer = false;
        }
    }

    public function openSpecialties(StudyPlanQuery $plans): void
    {
        $this->authorize('editGrid', $this->plan($plans));

        $this->resetValidation();
        $this->reset('selectedId', 'renamingId', 'renamedName', 'code', 'name');
        $this->panel = 'specialties';
        $this->showDrawer = true;
    }

    public function createSpecialty(StudyPlanQuery $plans, CreateSpecialty $createSpecialty): void
    {
        $plan = $this->plan($plans);
        $this->authorize('editGrid', $plan);

        $data = $this->validate([
            'code' => ['required', 'string', 'max:30'],
            'name' => ['required', 'string', 'max:255'],
        ]);
        $specialty = $createSpecialty->handle($plan, $data);

        $this->reset('code', 'name');
        $this->success('Especialidad creada', $this->describe($specialty));
    }

    public function startRename(string $id, StudyPlanQuery $plans, PlanGridEditorQuery $grids): void
    {
        $specialty = $this->specialty($id, $plans, $grids);

        $this->resetValidation('renamedName');
        $this->renamingId = $specialty->id;
        $this->renamedName = $specialty->name;
    }

    public function cancelRename(StudyPlanQuery $plans): void
    {
        $this->authorize('editGrid', $this->plan($plans));

        $this->resetValidation('renamedName');
        $this->reset('renamingId', 'renamedName');
    }

    public function saveRename(StudyPlanQuery $plans, PlanGridEditorQuery $grids, UpdateSpecialty $updateSpecialty): void
    {
        $plan = $this->plan($plans);
        $this->authorize('editGrid', $plan);

        if ($this->renamingId === null) {
            return;
        }

        $specialty = $this->specialty($this->renamingId, $plans, $grids);
        $data = $this->validate(['renamedName' => ['required', 'string', 'max:255']]);

        try {
            $specialty = $updateSpecialty->handle($specialty, ['name' => $data['renamedName']]);
        } catch (ValidationException $exception) {
            throw ValidationException::withMessages(['renamedName' => $exception->errors()['name'] ?? array_merge(...array_values($exception->errors()))]);
        }

        $this->reset('renamingId', 'renamedName');
        $this->success('Especialidad renombrada', $this->describe($specialty));
    }

    public function toggleSpecialty(string $id, StudyPlanQuery $plans, PlanGridEditorQuery $grids, UpdateSpecialty $updateSpecialty): void
    {
        $specialty = $this->specialty($id, $plans, $grids);
        $specialty = $updateSpecialty->handle($specialty, ['is_active' => ! $specialty->is_active]);

        $this->success($specialty->is_active ? 'Especialidad activada' : 'Especialidad desactivada', $this->describe($specialty));
    }

    public function removeSpecialty(string $id, StudyPlanQuery $plans, PlanGridEditorQuery $grids, RemoveSpecialty $removeSpecialty): void
    {
        $specialty = $this->specialty($id, $plans, $grids);

        try {
            $removeSpecialty->handle($specialty);
        } catch (ValidationException $exception) {
            $this->error('No se pudo quitar '.e($specialty->code), e(implode(' ', array_merge(...array_values($exception->errors())))));

            return;
        }

        if ($this->renamingId === $specialty->id) {
            $this->reset('renamingId', 'renamedName');
        }

        $this->success('Especialidad quitada', $this->describe($specialty));
    }

    /**
     * @return array<string, string>
     */
    protected function validationAttributes(): array
    {
        return ['code' => 'clave', 'name' => 'nombre', 'renamedName' => 'nombre'];
    }

    /**
     * El plan de la página, sin los borrados, con permiso de verlo.
     */
    private function plan(StudyPlanQuery $plans): StudyPlan
    {
        $plan = $plans->findOrFail($this->planId);
        $this->authorize('view', $plan);

        return $plan;
    }

    /**
     * Una especialidad del plan con permiso de editar la retícula; una de otro plan, quitada o con un id que
     * no es uuid no existe.
     */
    private function specialty(string $id, StudyPlanQuery $plans, PlanGridEditorQuery $grids): Specialty
    {
        $plan = $this->plan($plans);
        $this->authorize('editGrid', $plan);

        return $grids->specialtyOrFail($plan, $id);
    }

    /**
     * Lo que se resalta en la retícula: la materia elegida con su cadena completa (lo que pide y lo que abre,
     * hasta el final, solo por seriación), o todas las materias de la cadena de la leyenda; y la línea que lo
     * dice, como en la retícula del alumno.
     *
     * @param  array<string, mixed>  $grid
     * @param  list<array<string, mixed>>  $rows
     * @param  array<string, mixed>|null  $selected
     * @return array{focused: bool, onChain: array<string, true>, info: string}
     */
    private function highlight(array $grid, array $rows, ?array $selected): array
    {
        $hint = 'Toca una materia para verla en el panel; su cadena se resalta. Toca una cadena para verla completa; Esc la quita.';

        if ($this->chainFocus !== null) {
            $members = array_filter($rows, fn (array $row): bool => $row['chain'] === $this->chainFocus);
            $chain = $grid['chains'][$this->chainFocus];

            return [
                'focused' => true,
                'onChain' => array_fill_keys(array_column($members, 'subject_id'), true),
                'info' => "Cadena de {$chain['name']}: {$chain['size']} materias.",
            ];
        }

        if ($selected === null) {
            return ['focused' => false, 'onChain' => [], 'info' => $hint];
        }

        $byId = array_column($rows, null, 'subject_id');
        $seriation = fn (array $requisites): array => array_column(array_filter($requisites, fn (array $requisite): bool => $requisite['kind'] === 'seriacion'), 'subject_id');
        $walk = function (string $start, string $direction) use ($byId, $seriation): array {
            $seen = [];
            $pending = [$start];

            while ($pending !== []) {
                foreach ($seriation($byId[array_pop($pending)][$direction]) as $next) {
                    if (! isset($seen[$next])) {
                        $seen[$next] = true;
                        $pending[] = $next;
                    }
                }
            }

            return $seen;
        };
        $label = fn (array $requisite): string => "{$requisite['code']} {$requisite['name']}";
        $requires = array_filter($selected['requires'], fn (array $requisite): bool => $requisite['kind'] === 'seriacion');
        $corequisites = array_filter($selected['requires'], fn (array $requisite): bool => $requisite['kind'] !== 'seriacion');
        $opens = array_filter($selected['required_by'], fn (array $requisite): bool => $requisite['kind'] === 'seriacion');

        return [
            'focused' => true,
            'onChain' => [$selected['subject_id'] => true] + $walk($selected['subject_id'], 'requires') + $walk($selected['subject_id'], 'required_by'),
            'info' => "{$label($selected)}: "
                .($requires === [] ? 'no pide seriación' : 'pide '.implode(', ', array_map($label, $requires)))
                .($corequisites === [] ? '' : '; correquisito '.implode(', ', array_map($label, $corequisites)))
                .' · '
                .($opens === [] ? 'no abre ninguna' : 'abre '.implode(', ', array_map($label, $opens))),
        ];
    }

    /**
     * El encabezado de un semestre: cuántas materias tiene, los créditos de las del tronco común y si tiene
     * materias de especialidad (sus créditos dependen de cuál elija el alumno).
     *
     * @param  list<array<string, mixed>>  $rows
     * @return array{subjects: int, credits: int, withSpecialties: bool}
     */
    private function summary(array $rows): array
    {
        $common = array_filter($rows, fn (array $row): bool => $row['specialty_id'] === null);

        return [
            'subjects' => count($rows),
            'credits' => array_sum(array_column($common, 'credits')),
            'withSpecialties' => count($common) < count($rows),
        ];
    }

    /**
     * Clave y nombre para el toast, escapados: MaryUI lo pinta con x-html.
     */
    private function describe(Specialty $specialty): string
    {
        return e("{$specialty->code} · {$specialty->name}");
    }
};
