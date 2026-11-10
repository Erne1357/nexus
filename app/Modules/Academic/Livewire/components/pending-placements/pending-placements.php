<?php

use App\Modules\Academic\Actions\ResolvePendingPlacement;
use App\Modules\Academic\Enums\PrerequisiteKind;
use App\Modules\Academic\Models\StudyPlan;
use App\Modules\Academic\Models\StudyPlanSubjectPending;
use App\Modules\Academic\Queries\PendingPlacementsQuery;
use App\Modules\Academic\Queries\PlanGridEditorQuery;
use App\Modules\Academic\Queries\StudyPlanQuery;
use App\Modules\Academic\Queries\WaitingPrerequisitesQuery;
use App\Modules\Academic\Rules\PlacementRules;
use App\Modules\Core\Support\SemesterGrid;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Mary\Traits\Toast;

/**
 * «Por validar» de un plan (D18 y D19; DESIGN.md, "Retícula del plan (editor)"; maqueta B elegida el 5 de noviembre
 * de 2026, `.impeccable/mocks/por-validar/b.html`): las materias de la migración del SII que esperan semestre. A la
 * izquierda, la bandeja con su búsqueda y una ficha por materia (nombre, clave, horas y créditos, retícula del SII);
 * a la derecha, la retícula del plan de solo lectura, con las piezas de Core. Elegir una ficha pone «Colocar aquí» en
 * cada semestre que se ofrece; tocarlo abre el panel lateral con el semestre elegido, los datos de la materia y la
 * carga del semestre («Semestre 3: 5 materias · 19 cr → 24 cr»), y «Agregar a la retícula» la resuelve con
 * ResolvePendingPlacement. Al terminar avisa, la ficha sale de la bandeja, el mosaico nuevo queda marcado y el plan
 * (academic::pages.plan) se pone al día con `pending-placed`.
 *
 * La carga del semestre cuenta lo que llevaría un alumno de la materia: el tronco común y, si es de especialidad, las
 * de esa especialidad (supuesto por confirmar).
 *
 * Nexus nunca decide un semestre (D18, confirmado el 10 de noviembre de 2026): en una retícula viva, la migración deja
 * un semestre sugerido (el de las materias con el mismo nombre de la misma carrera, o el del SII de una materia que ya
 * no se ofrece). La ficha lo dice («Sugerido: N», y si ese semestre todavía no se ofrece, también eso) y elegirla abre
 * el panel con ese semestre elegido, si se ofrece; Escolares lo confirma con «Agregar a la retícula» o lo cambia. En una
 * retícula histórica (ningún alumno inscrito) la sugerencia que haya quedado no se usa, y un aviso arriba dice que
 * solo hace falta asignarles semestre si vuelve un alumno.
 *
 * Requisitos que esperan (seriación del SII; decisión del usuario del 10 de noviembre de 2026, confirmada): el panel dice
 * qué requisitos entran con la materia a la seriación al asignarle semestre («Al asignarle semestre entran sus
 * requisitos: pide ACF-0901 (seriación), AEF-1024 la pide (correquisito).») y cuáles esperan todavía a que se valide la
 * otra materia (WaitingPrerequisitesQuery). Al agregarla, el aviso dice cuáles entraron y, si alguno no entró porque
 * cerraría un ciclo de seriación, lo dice como advertencia: sigue esperando con su nota.
 *
 * El componente autoriza `view` del plan y `validatePlacements` (el jefe de Servicios Escolares y el admin) en
 * mount(), al pintar y en cada método; los ids del navegador se buscan entre los pendientes sin resolver del plan. Los
 * errores de la Action caen en el panel: el semestre en su campo y los demás (el plan, la materia, la especialidad) en
 * `pending`. Si otra sesión ya la resolvió, lo dice y la quita de la bandeja.
 */
new class extends Component
{
    use Toast;

    /** El plan. El navegador no lo puede cambiar. */
    #[Locked]
    public string $planId;

    /** Lo que se busca en la bandeja: clave o nombre. */
    public string $search = '';

    /** El pendiente elegido en la bandeja; null sin ninguno. Solo lo elige pick(). */
    #[Locked]
    public ?string $pickedId = null;

    /** El panel de confirmación; el navegador solo lo cierra. */
    public bool $showDrawer = false;

    /** El semestre del panel (lo que manda su select). */
    public string $semester = '';

    /** La fila de la retícula que se acaba de colocar, para marcarla; null sin ninguna. */
    #[Locked]
    public ?string $placedId = null;

    public function mount(string $planId, StudyPlanQuery $plans): void
    {
        $this->planId = $this->plan($plans, $planId)->id;
    }

    /**
     * @return array<string, mixed>
     */
    public function with(StudyPlanQuery $plans, PlanGridEditorQuery $grids, PendingPlacementsQuery $pendings, WaitingPrerequisitesQuery $prerequisites): array
    {
        $plan = $this->plan($plans);
        $grid = $grids->forPlan($plan);
        $all = $pendings->forPlan($plan);
        $picked = $this->pickedId === null ? null : $all->firstWhere('id', $this->pickedId);

        if ($picked === null) {
            $this->pickedId = null;
            $this->showDrawer = false;
        }

        $offered = PlacementRules::offeredSemesters($plan->semesters_count, $grid['semesters_count']);

        return [
            'historical' => $plan->is_historical,
            'suggests' => ! $plan->is_historical,
            'grid' => $grid,
            'layout' => SemesterGrid::place(
                array_map(fn (array $semester): array => array_column($semester, 'specialty_id', 'placement_id'), $grid['semesters']),
                array_column($grid['specialties'], 'id'),
            ),
            'specialtiesById' => array_column($grid['specialties'], null, 'id'),
            'bandSubjects' => array_count_values(array_filter(array_column(array_merge([], ...array_values($grid['semesters'])), 'specialty_id'))),
            'commonCredits' => array_map(fn (array $rows): int => array_sum(array_column(array_filter($rows, fn (array $row): bool => $row['specialty_id'] === null), 'credits')), $grid['semesters']),
            'pendings' => trim($this->search) === '' ? $all : $pendings->forPlan($plan, $this->search),
            'total' => $all->count(),
            'picked' => $picked,
            'offeredSemesters' => $offered,
            'semesterOptions' => array_map(fn (int $semester): array => ['id' => (string) $semester, 'name' => "Semestre {$semester}"], range(1, max(1, $offered))),
            'load' => $picked !== null && $this->showDrawer ? $this->load($grid['semesters'], $picked) : null,
            'prerequisites' => $picked !== null && $this->showDrawer ? $prerequisites->forSubject($plan, $picked->subject_id) : ['enters' => [], 'waits' => []],
        ];
    }

    /**
     * Elige el pendiente `$id` de la bandeja; elegirlo otra vez lo suelta. Uno de otro plan, ya resuelto o con un id
     * que no es uuid no existe (404). Si trae semestre sugerido, el plan no es una retícula histórica y ese semestre se
     * ofrece, abre el panel con él elegido; nada se guarda sin «Agregar a la retícula».
     */
    public function pick(string $id, StudyPlanQuery $plans, PendingPlacementsQuery $pendings, PlanGridEditorQuery $grids): void
    {
        $plan = $this->plan($plans);
        $pending = $pendings->pendingOrFail($plan, $id);

        $this->resetValidation();
        $this->reset('showDrawer', 'semester', 'placedId');
        $this->pickedId = $this->pickedId === $pending->id ? null : $pending->id;
        $suggested = $plan->is_historical ? null : $pending->suggested_semester;

        if ($this->pickedId !== null && $suggested !== null && $suggested <= PlacementRules::offeredSemesters($plan->semesters_count, $grids->semestersCount($plan))) {
            $this->semester = (string) $suggested;
            $this->showDrawer = true;
        }
    }

    /**
     * «Colocar aquí» del semestre `$semester`: abre el panel con ese semestre. Sin materia elegida no hace nada; si
     * otra sesión ya la resolvió, lo dice.
     */
    public function openPlace(int $semester, StudyPlanQuery $plans, PendingPlacementsQuery $pendings): void
    {
        $plan = $this->plan($plans);

        if ($this->pickedId === null) {
            return;
        }

        if ($this->picked($plan, $pendings) === null) {
            return;
        }

        $this->resetValidation();
        $this->semester = (string) $semester;
        $this->showDrawer = true;
    }

    /**
     * El navegador solo cierra el panel (Esc, el velo, la X o «Cancelar»); abrirlo es de openPlace() y de pick() con un
     * semestre sugerido.
     */
    public function updatedShowDrawer(bool $open): void
    {
        $this->plan(app(StudyPlanQuery::class));

        if ($open && $this->pickedId === null) {
            $this->showDrawer = false;
        }

        if (! $open) {
            $this->resetValidation();
        }
    }

    /**
     * «Agregar a la retícula»: resuelve el pendiente elegido en el semestre del panel. El aviso dice qué requisitos que
     * esperaban entraron con ella y, como advertencia, los que siguen esperando porque cerrarían un ciclo.
     */
    public function place(StudyPlanQuery $plans, PendingPlacementsQuery $pendings, ResolvePendingPlacement $resolve, WaitingPrerequisitesQuery $prerequisites): void
    {
        $plan = $this->plan($plans);

        if ($this->pickedId === null || ($pending = $this->picked($plan, $pendings)) === null) {
            return;
        }

        $data = $this->validate(['semester' => ['required', 'integer']]);
        $expected = $prerequisites->forSubject($plan, $pending->subject_id)['enters'];

        try {
            $placement = $resolve->handle($pending, (int) $data['semester'], auth()->user());
        } catch (ValidationException $exception) {
            $errors = [];

            foreach ($exception->errors() as $key => $messages) {
                $field = $key === 'semester' ? 'semester' : 'pending';
                $errors[$field] = [...($errors[$field] ?? []), ...$messages];
            }

            throw ValidationException::withMessages($errors);
        }

        $name = $pending->subject->name;
        $still = array_column($prerequisites->forSubject($plan, $pending->subject_id)['enters'], 'id', 'id');
        $blocked = array_values(array_filter($expected, fn (array $item): bool => isset($still[$item['id']])));
        $entered = array_values(array_filter($expected, fn (array $item): bool => ! isset($still[$item['id']])));
        $detail = trim(($entered === [] ? '' : 'Entraron sus requisitos: '.$this->prerequisiteLabels($entered).'.')
            .match (count($blocked)) {
                0 => '',
                1 => ' No entró, porque cerraría un ciclo de seriación, y sigue esperando: '.$this->prerequisiteLabels($blocked).'.',
                default => ' No entraron, porque cerrarían un ciclo de seriación, y siguen esperando: '.$this->prerequisiteLabels($blocked).'.',
            });

        $this->reset('pickedId', 'showDrawer', 'semester');
        $this->placedId = $placement->id;
        $this->dispatch('pending-placed');

        if (! $placement->wasRecentlyCreated && ! $placement->wasChanged()) {
            $this->info(e("«{$name}» ya estaba en el semestre {$placement->semester}"), e(trim('Quedó validada sin moverla. '.$detail)));

            return;
        }

        if ($blocked !== []) {
            $this->warning(e("«{$name}» agregada al semestre {$placement->semester}"), e($detail));

            return;
        }

        $this->success(e("«{$name}» agregada al semestre {$placement->semester}"), $detail === '' ? null : e($detail));
    }

    /**
     * Los requisitos como en el panel: «pide ACF-0901 (seriación), AEF-1024 la pide (correquisito)».
     *
     * @param  list<array{code: string, requires: bool, kind: PrerequisiteKind}>  $items
     */
    private function prerequisiteLabels(array $items): string
    {
        return implode(', ', array_map(
            fn (array $item): string => ($item['requires'] ? "pide {$item['code']}" : "{$item['code']} la pide").' ('.mb_strtolower($item['kind']->label()).')',
            $items,
        ));
    }

    /**
     * @return array<string, string>
     */
    protected function validationAttributes(): array
    {
        return ['semester' => 'semestre'];
    }

    /**
     * El plan, sin los borrados, si puede verlo y validar sus materias.
     */
    private function plan(StudyPlanQuery $plans, ?string $id = null): StudyPlan
    {
        $plan = $plans->findOrFail($id ?? $this->planId);
        $this->authorize('view', $plan);
        $this->authorize('validatePlacements', StudyPlan::class);

        return $plan;
    }

    /**
     * El pendiente elegido si sigue sin resolver; si no, lo dice, lo suelta y cierra el panel.
     */
    private function picked(StudyPlan $plan, PendingPlacementsQuery $pendings): ?StudyPlanSubjectPending
    {
        try {
            return $pendings->pendingOrFail($plan, (string) $this->pickedId);
        } catch (ModelNotFoundException) {
            $this->resetValidation();
            $this->reset('pickedId', 'showDrawer', 'semester');
            $this->info('Esa materia ya se validó.', 'La bandeja ya está al día.');

            return null;
        }
    }

    /**
     * La carga del semestre del panel antes y después de colocar la materia: las materias del tronco común y, si es
     * de especialidad, las de esa especialidad, con sus créditos. Null con un semestre que no es número.
     *
     * @param  array<int, list<array<string, mixed>>>  $semesters
     * @return array{semester: int, subjects: int, credits: int, after: int}|null
     */
    private function load(array $semesters, StudyPlanSubjectPending $picked): ?array
    {
        if (! ctype_digit($this->semester)) {
            return null;
        }

        $semester = (int) $this->semester;
        $rows = array_filter($semesters[$semester] ?? [], fn (array $row): bool => $row['specialty_id'] === null || $row['specialty_id'] === $picked->specialty_id);
        $credits = array_sum(array_column($rows, 'credits'));

        return ['semester' => $semester, 'subjects' => count($rows), 'credits' => $credits, 'after' => $credits + $picked->subject->credits];
    }
};
