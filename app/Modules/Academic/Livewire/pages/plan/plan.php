<?php

use App\Modules\Academic\Actions\AddPrerequisite;
use App\Modules\Academic\Actions\CreateSpecialty;
use App\Modules\Academic\Actions\PlaceSubject;
use App\Modules\Academic\Actions\RemovePlacement;
use App\Modules\Academic\Actions\RemovePrerequisite;
use App\Modules\Academic\Actions\RemoveSpecialty;
use App\Modules\Academic\Actions\UpdatePlacement;
use App\Modules\Academic\Actions\UpdatePrerequisite;
use App\Modules\Academic\Actions\UpdateSpecialty;
use App\Modules\Academic\Enums\PrerequisiteKind;
use App\Modules\Academic\Models\Specialty;
use App\Modules\Academic\Models\StudyPlan;
use App\Modules\Academic\Models\StudyPlanSubject;
use App\Modules\Academic\Models\SubjectPrerequisite;
use App\Modules\Academic\Queries\PlanGridEditorQuery;
use App\Modules\Academic\Queries\StudyPlanQuery;
use App\Modules\Academic\Rules\PlacementRules;
use App\Modules\Academic\Rules\SeriationRules;
use App\Modules\Core\Support\SemesterGrid;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;
use Mary\Traits\Toast;

/**
 * Retícula de un plan (tarea 1.8; DESIGN.md, "Retícula del plan (editor)", variante A aprobada el 29 de
 * octubre de 2026): el encabezado del plan, sus especialidades y sus materias en columnas por semestre, con su
 * seriación y sus cadenas, en la misma cuadrícula de Core que la retícula del alumno (SemesterGrid). Tocar una
 * materia la selecciona, resalta su cadena y la abre en el panel lateral (`x-drawer`): su lugar, que se edita ahí
 * (semestre, especialidad y si es obligatoria), lo que pide, que también se edita ahí (el tipo de cada requisito,
 * quitarlo y «Agregar requisito» con otra materia de la retícula), quién la pide, y al pie «Quitar de la
 * retícula…», que pide confirmar con la seriación que se va. En el encabezado de cada semestre, «Agregar materia»
 * busca en el catálogo y la pone. En el mismo panel se gestionan las especialidades. Es del admin.
 *
 * El componente lee con PlanGridEditorQuery, autoriza `view` del plan en mount() y en cada lectura, y
 * `editGrid` en cada método que escribe (una petición puede llegar con una página que abrió otra sesión);
 * valida la forma de los datos y llama a las Actions, que hacen cumplir las reglas. Los ids que llegan del
 * navegador se buscan dentro del plan: una fila o una especialidad de otro plan no existe.
 *
 * Una seriación que cierra un ciclo se rechaza en el panel con su cadena, del requisito a la materia; la cadena la
 * calcula SeriationRules::cyclePath() con la seriación de la retícula que ya se leyó, sin consultar.
 */
new #[Title('Retícula del plan')] class extends Component
{
    use Toast;

    /** Cuántas materias de la retícula muestra «Agregar requisito», como en la maqueta. */
    private const int REQUISITE_LIMIT = 8;

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

    /** El semestre de la materia del panel (lo que manda el select); se llena al elegirla. */
    public string $semester = '';

    /** La especialidad de la materia del panel; '' es el tronco común. */
    public string $specialty_id = '';

    /** Si la materia del panel es obligatoria. */
    public bool $is_required = true;

    /** La confirmación de quitar de la retícula la materia del panel. */
    public bool $showRemove = false;

    /** «Agregar materia»: el modal con la búsqueda en el catálogo. */
    public bool $showAdd = false;

    /** Lo que se busca en el catálogo: clave o nombre. */
    public string $addSearch = '';

    /** La materia del catálogo elegida para agregar; null si ninguna. Solo la elige pick(). */
    #[Locked]
    public ?string $addSubjectId = null;

    /** Su clave, para el botón que la agrega. */
    #[Locked]
    public string $addSubjectCode = '';

    /** El semestre donde se agrega. */
    public string $addSemester = '1';

    /** Su especialidad; '' es el tronco común. */
    public string $addSpecialtyId = '';

    /** Si se agrega como obligatoria. */
    public bool $addIsRequired = true;

    /** «Agregar requisito»: lo que se busca entre las materias de esta retícula, clave o nombre. */
    public string $requisiteSearch = '';

    /** La materia de esta retícula elegida como requisito nuevo; null si ninguna. Solo la elige pickRequisite(). */
    #[Locked]
    public ?string $prerequisite_subject_id = null;

    /** El tipo del requisito nuevo: `seriacion` o `correquisito`. */
    public string $kind = 'seriacion';

    /**
     * El tipo de cada requisito de la materia del panel, por id de la seriación: lo que manda su select. Al pintar
     * vuelve a tener lo que hay en la base, así un cambio rechazado regresa el select; cambiarlo es changeKind().
     *
     * @var array<string, string>
     */
    public array $kinds = [];

    /**
     * La seriación que se rechazó por cerrar un ciclo, para pintar su cadena; null sin ninguna.
     *
     * @var array{subject_id: string, prerequisite_subject_id: string}|null
     */
    #[Locked]
    public ?array $cycle = null;

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

        if ($selected === null) {
            $this->showRemove = false;
        }

        if ($this->chainFocus !== null && ! isset($grid['chains'][$this->chainFocus])) {
            $this->chainFocus = null;
        }

        $this->kinds = $selected === null ? [] : array_column($selected['requires'], 'kind', 'prerequisite_id');

        $offeredSemesters = PlacementRules::offeredSemesters($plan->semesters_count, $grid['semesters_count']);

        return [
            'plan' => $plan,
            'grid' => $grid,
            'subjectsCount' => count($rows),
            'specialtiesById' => array_column($grid['specialties'], null, 'id'),
            'layout' => SemesterGrid::place(
                array_map(fn (array $semester): array => array_column($semester, 'specialty_id', 'placement_id'), $grid['semesters']),
                array_column($grid['specialties'], 'id'),
            ),
            'bandSubjects' => array_count_values(array_filter(array_column($rows, 'specialty_id'))),
            'commonCredits' => array_map(fn (array $semester): int => $this->commonCredits($semester), $grid['semesters']),
            'selected' => $selected,
            'offeredSemesters' => $offeredSemesters,
            'placementOptions' => $selected === null ? null : [
                'semesters' => $this->semesterOptions(max($offeredSemesters, $selected['semester'])),
                'specialties' => $this->specialtyOptions($grid['specialties'], $selected['specialty_id']),
            ],
            'removal' => $this->showRemove && $selected !== null ? $this->removal($rows, $selected) : null,
            'addOptions' => $this->showAdd ? [
                'semesters' => $this->semesterOptions($offeredSemesters),
                'specialties' => $this->specialtyOptions($grid['specialties']),
            ] : null,
            'catalog' => $this->showAdd ? $grids->catalog($plan, $this->addSearch) : null,
            'kindOptions' => array_map(fn (PrerequisiteKind $kind): array => ['id' => $kind->value, 'name' => $kind->label()], PrerequisiteKind::cases()),
            'requisiteOptions' => $selected === null ? null : $this->requisiteOptions($rows, $selected),
            'cyclePath' => $selected === null ? null : $this->cyclePath($rows),
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
        $this->showRemove = false;
        $this->fillPlacement($placement);
        $this->resetRequisiteForm();
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
        $this->resetRequisiteForm();
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
            $this->resetValidation(['semester', 'specialty_id', 'is_required']);
            $this->reset('selectedId', 'renamingId', 'renamedName', 'showRemove', 'semester', 'specialty_id', 'is_required');
            $this->resetRequisiteForm();

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
        $this->resetRequisiteForm();
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
     * Guarda el lugar de la materia de la fila `$id` (la del panel): su semestre, su especialidad y si es
     * obligatoria. Los errores de UpdatePlacement caen en su campo, que se llama como su clave. Si otra sesión
     * la quitó, lo dice y cierra el panel (selectedPlacementGone()).
     */
    public function savePlacement(string $id, StudyPlanQuery $plans, PlanGridEditorQuery $grids, UpdatePlacement $updatePlacement): void
    {
        $placement = $this->selectedPlacement($id, $plans, $grids);

        if ($placement === null) {
            return;
        }

        $data = $this->validate([
            'semester' => ['required', 'integer'],
            'specialty_id' => ['nullable', 'string'],
            'is_required' => ['boolean'],
        ]);

        try {
            $placement = $updatePlacement->handle($placement, [
                'semester' => (int) $data['semester'],
                'specialty_id' => $data['specialty_id'] ?? null,
                'is_required' => (bool) $data['is_required'],
            ]);
        } catch (ModelNotFoundException) {
            $this->selectedPlacementGone();

            return;
        }

        $this->fillPlacement($placement);
        $subject = $placement->subject;

        if (! $placement->wasChanged()) {
            $this->info('Sin cambios', e("{$subject->code} {$subject->name} ya estaba así."));

            return;
        }

        $this->success('Lugar guardado', e("{$subject->code} {$subject->name} queda en el semestre {$placement->semester}."));
    }

    /**
     * Pide confirmar que se quita de la retícula la materia del panel; la confirmación lista la seriación que se
     * va con ella.
     */
    public function confirmRemove(StudyPlanQuery $plans, PlanGridEditorQuery $grids): void
    {
        if ($this->selectedId === null) {
            $this->authorize('editGrid', $this->plan($plans));

            return;
        }

        $this->showRemove = $this->selectedPlacement($this->selectedId, $plans, $grids) !== null;
    }

    /**
     * Quita de la retícula la materia de la fila `$id`, con su seriación, y cierra el panel. Si otra sesión ya la
     * quitó, lo dice (selectedPlacementGone()).
     */
    public function removePlacement(string $id, StudyPlanQuery $plans, PlanGridEditorQuery $grids, RemovePlacement $removePlacement): void
    {
        $placement = $this->selectedPlacement($id, $plans, $grids);

        if ($placement === null) {
            return;
        }

        try {
            $removed = $removePlacement->handle($placement);
        } catch (ModelNotFoundException) {
            $this->selectedPlacementGone();

            return;
        }

        $subject = $placement->subject;

        $this->resetValidation(['semester', 'specialty_id', 'is_required']);
        $this->reset('selectedId', 'showDrawer', 'showRemove', 'semester', 'specialty_id', 'is_required');
        $this->resetRequisiteForm();
        $this->success('Materia quitada', e("{$subject->code} {$subject->name} ya no está en la retícula".match ($removed) {
            0 => '.',
            1 => ' ni su seriación.',
            default => " ni sus {$removed} seriaciones.",
        }));
    }

    /**
     * Abre «Agregar materia» en el semestre `$semester`, sin búsqueda ni materia elegida.
     */
    public function openAdd(int $semester, StudyPlanQuery $plans): void
    {
        $this->authorize('editGrid', $this->plan($plans));

        $this->resetValidation(['addSubjectId', 'addSemester', 'addSpecialtyId', 'addIsRequired']);
        $this->reset('addSearch', 'addSubjectId', 'addSubjectCode', 'addSpecialtyId', 'addIsRequired');
        $this->addSemester = (string) max(1, $semester);
        $this->showAdd = true;
    }

    /**
     * Elige la materia del catálogo que se va a agregar. Si no se puede poner, lo dice PlaceSubject al agregarla.
     */
    public function pick(string $id, StudyPlanQuery $plans, PlanGridEditorQuery $grids): void
    {
        $this->authorize('editGrid', $this->plan($plans));

        $subject = $grids->subjectOrFail($id);

        $this->resetValidation('addSubjectId');
        $this->addSubjectId = $subject->id;
        $this->addSubjectCode = $subject->code;
    }

    /**
     * Otra búsqueda suelta la materia elegida: puede ya no estar en la lista.
     */
    public function updatedAddSearch(): void
    {
        $this->reset('addSubjectId', 'addSubjectCode');
    }

    /**
     * Agrega la materia elegida en el semestre, con su especialidad y si es obligatoria. Los errores de
     * PlaceSubject caen en el campo del modal que les toca.
     */
    public function place(StudyPlanQuery $plans, PlanGridEditorQuery $grids, PlaceSubject $placeSubject): void
    {
        $plan = $this->plan($plans);
        $this->authorize('editGrid', $plan);

        $data = $this->validate([
            'addSubjectId' => ['required', 'string'],
            'addSemester' => ['required', 'integer'],
            'addSpecialtyId' => ['nullable', 'string'],
            'addIsRequired' => ['boolean'],
        ], ['addSubjectId.required' => 'Elige una materia del catálogo.']);

        $subject = $grids->subjectOrFail($data['addSubjectId']);

        try {
            $specialty = ($data['addSpecialtyId'] ?? '') === '' ? null : $grids->specialtyOrFail($plan, $data['addSpecialtyId']);
        } catch (ModelNotFoundException) {
            throw ValidationException::withMessages(['addSpecialtyId' => PlacementRules::SPECIALTY_GONE]);
        }

        try {
            $placement = $placeSubject->handle($plan, $subject, (int) $data['addSemester'], $specialty, (bool) $data['addIsRequired']);
        } catch (ValidationException $exception) {
            $fields = ['subject_id' => 'addSubjectId', 'semester' => 'addSemester', 'specialty_id' => 'addSpecialtyId'];

            throw ValidationException::withMessages(collect($exception->errors())->mapWithKeys(fn (array $messages, string $key): array => [$fields[$key] ?? $key => $messages])->all());
        }

        $this->reset('showAdd', 'addSearch', 'addSubjectId', 'addSubjectCode', 'addSpecialtyId', 'addIsRequired');
        $this->success('Materia agregada', e("{$subject->code} {$subject->name} quedó en el semestre {$placement->semester}."));
    }

    /**
     * Elige la materia de esta retícula que se agrega como requisito de la materia del panel; su clave y nombre
     * quedan en la búsqueda. Si no se puede, lo dice AddPrerequisite al agregarla.
     */
    public function pickRequisite(string $id, StudyPlanQuery $plans, PlanGridEditorQuery $grids): void
    {
        $this->authorize('editGrid', $this->plan($plans));

        $subject = $grids->subjectOrFail($id);

        $this->resetValidation(['prerequisite_subject_id', 'kind']);
        $this->cycle = null;
        $this->prerequisite_subject_id = $subject->id;
        $this->requisiteSearch = "{$subject->code} · {$subject->name}";
    }

    /**
     * Otra búsqueda suelta la materia elegida y su error: puede ya no estar en la lista.
     */
    public function updatedRequisiteSearch(): void
    {
        $this->resetValidation(['prerequisite_subject_id', 'kind']);
        $this->reset('prerequisite_subject_id', 'cycle');
    }

    /**
     * Agrega a la materia del panel el requisito elegido, con su tipo. Los errores de AddPrerequisite caen en
     * `prerequisite_subject_id` (la materia) o en `kind` (el ciclo, que el panel muestra con su cadena). Si otra
     * sesión quitó la materia del panel, lo dice y cierra el panel (selectedPlacementGone()).
     */
    public function addPrerequisite(StudyPlanQuery $plans, PlanGridEditorQuery $grids, AddPrerequisite $addPrerequisite): void
    {
        if ($this->selectedId === null) {
            $this->authorize('editGrid', $this->plan($plans));

            return;
        }

        $placement = $this->selectedPlacement($this->selectedId, $plans, $grids);

        if ($placement === null) {
            return;
        }

        $this->cycle = null;
        $data = $this->validate([
            'prerequisite_subject_id' => ['required', 'string'],
            'kind' => ['required', Rule::enum(PrerequisiteKind::class)],
        ], [
            'prerequisite_subject_id.required' => 'Elige una materia de esta retícula.',
            'kind.enum' => 'El tipo debe ser seriación o correquisito.',
        ]);

        $subject = $placement->subject;
        $prerequisite = $grids->subjectOrFail($data['prerequisite_subject_id']);

        try {
            $row = $addPrerequisite->handle($this->plan($plans), $subject, $prerequisite, PrerequisiteKind::from($data['kind']));
        } catch (ValidationException $exception) {
            if (isset($exception->errors()['kind'])) {
                $this->cycle = ['subject_id' => $subject->id, 'prerequisite_subject_id' => $prerequisite->id];
            }

            throw ValidationException::withMessages(collect($exception->errors())->mapWithKeys(fn (array $messages, string $key): array => [$key === 'subject_id' ? 'prerequisite_subject_id' : $key => $messages])->all());
        }

        $this->resetRequisiteForm();
        $this->success('Requisito agregado', e("{$subject->code} pide {$prerequisite->code} (".mb_strtolower($row->kind->label()).').'));
    }

    /**
     * El select de tipo de un requisito del panel (`kinds.{id}`): cambiarlo es changeKind(). Un `kinds` entero que
     * llega del navegador no cambia nada: al pintar vuelve a tener lo de la base.
     */
    public function updatedKinds(mixed $kind, ?string $id = null): void
    {
        if ($id === null) {
            return;
        }

        $this->changeKind($id, is_string($kind) ? $kind : '', app(StudyPlanQuery::class), app(PlanGridEditorQuery::class), app(UpdatePrerequisite::class));
    }

    /**
     * Cambia el tipo del requisito `$id` del plan a `$kind`. Si como seriación cierra un ciclo, el error cae en
     * `kind` y el panel lo muestra con su cadena; el select regresa al tipo que tiene. Si otra sesión lo quitó,
     * antes o mientras tanto, lo dice (prerequisite()).
     */
    public function changeKind(string $id, string $kind, StudyPlanQuery $plans, PlanGridEditorQuery $grids, UpdatePrerequisite $updatePrerequisite): void
    {
        $prerequisite = $this->prerequisite($id, $plans, $grids);

        if ($prerequisite === null) {
            return;
        }

        $this->resetValidation('kind');
        $this->cycle = null;
        $newKind = PrerequisiteKind::tryFrom($kind) ?? throw ValidationException::withMessages(['kind' => 'El tipo debe ser seriación o correquisito.']);

        try {
            $prerequisite = $updatePrerequisite->handle($prerequisite, $newKind);
        } catch (ModelNotFoundException) {
            $this->prerequisiteGone();

            return;
        } catch (ValidationException $exception) {
            $this->cycle = ['subject_id' => $prerequisite->subject_id, 'prerequisite_subject_id' => $prerequisite->prerequisite_subject_id];

            throw $exception;
        }

        $description = e("{$prerequisite->subject->code} pide {$prerequisite->prerequisite->code} como ".mb_strtolower($newKind->label()).'.');

        if (! $prerequisite->wasChanged()) {
            $this->info('Sin cambios', $description);

            return;
        }

        $this->success('Tipo cambiado', $description);
    }

    /**
     * Quita el requisito `$id` del plan: la materia deja de pedirlo. Si otra sesión lo quitó, antes o mientras
     * tanto, lo dice (prerequisite()).
     */
    public function removePrerequisite(string $id, StudyPlanQuery $plans, PlanGridEditorQuery $grids, RemovePrerequisite $removePrerequisite): void
    {
        $prerequisite = $this->prerequisite($id, $plans, $grids);

        if ($prerequisite === null) {
            return;
        }

        try {
            $removePrerequisite->handle($prerequisite);
        } catch (ModelNotFoundException) {
            $this->prerequisiteGone();

            return;
        }

        $this->resetValidation('kind');
        $this->cycle = null;
        $this->success('Requisito quitado', e("{$prerequisite->subject->code} ya no pide {$prerequisite->prerequisite->code}."));
    }

    /**
     * El navegador solo cierra «Agregar materia» (Esc, el velo o la X); abrirlo es de openAdd(), y una petición
     * que lo abre pide `editGrid` como ella.
     */
    public function updatedShowAdd(bool $open): void
    {
        if ($open) {
            $this->authorize('editGrid', $this->plan(app(StudyPlanQuery::class)));
        }
    }

    /**
     * El navegador solo cierra la confirmación de quitar; abrirla es de confirmRemove(): sin una materia elegida
     * se queda cerrada, y con ella pide `editGrid`.
     */
    public function updatedShowRemove(bool $open): void
    {
        if (! $open) {
            return;
        }

        if ($this->selectedId === null) {
            $this->showRemove = false;

            return;
        }

        $this->authorize('editGrid', $this->plan(app(StudyPlanQuery::class)));
    }

    /**
     * @return array<string, string>
     */
    protected function validationAttributes(): array
    {
        return [
            'code' => 'clave',
            'name' => 'nombre',
            'renamedName' => 'nombre',
            'semester' => 'semestre',
            'specialty_id' => 'especialidad',
            'addSubjectId' => 'materia',
            'addSemester' => 'semestre',
            'addSpecialtyId' => 'especialidad',
            'prerequisite_subject_id' => 'requisito',
            'kind' => 'tipo',
        ];
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
     * Una fila de la retícula del plan con permiso de editarla; una de otro plan, quitada o con un id que no es
     * uuid no existe (404). La excepción es la fila del panel (`selectedId`, que solo pone select() con una fila
     * de este plan): si ya no está, otra sesión la quitó (o borró su materia del catálogo), y en lugar del 404 lo
     * dice y cierra el panel; devuelve null.
     *
     * @throws ModelNotFoundException<StudyPlanSubject>
     */
    private function selectedPlacement(string $id, StudyPlanQuery $plans, PlanGridEditorQuery $grids): ?StudyPlanSubject
    {
        $plan = $this->plan($plans);
        $this->authorize('editGrid', $plan);

        try {
            return $grids->placementOrFail($plan, $id);
        } catch (ModelNotFoundException $exception) {
            if ($this->selectedId === null || $id !== $this->selectedId) {
                throw $exception;
            }

            $this->selectedPlacementGone();

            return null;
        }
    }

    /**
     * La materia del panel ya no está en la retícula: cierra el panel y la confirmación, y lo dice. Al pintar, la
     * retícula ya sale sin ella.
     */
    private function selectedPlacementGone(): void
    {
        $this->resetValidation(['semester', 'specialty_id', 'is_required']);
        $this->reset('selectedId', 'showDrawer', 'showRemove', 'semester', 'specialty_id', 'is_required');
        $this->resetRequisiteForm();
        $this->info('Esa materia ya no está en la retícula.', 'La retícula ya está al día.');
    }

    /**
     * Llena el formulario del panel con el lugar de la fila y le quita sus errores.
     */
    private function fillPlacement(StudyPlanSubject $placement): void
    {
        $this->resetValidation(['semester', 'specialty_id', 'is_required']);
        $this->semester = (string) $placement->semester;
        $this->specialty_id = (string) $placement->specialty_id;
        $this->is_required = $placement->is_required;
    }

    /**
     * Deja «Agregar requisito» como al abrir el panel: sin búsqueda, sin materia elegida, seriación y sin error.
     */
    private function resetRequisiteForm(): void
    {
        $this->resetValidation(['prerequisite_subject_id', 'kind']);
        $this->reset('requisiteSearch', 'prerequisite_subject_id', 'kind', 'cycle');
    }

    /**
     * Un requisito sin quitar del plan con permiso de editar la retícula; uno de otro plan, que no existe o con un
     * id que no es uuid no existe (404). La excepción es uno de este plan que otra sesión quitó mientras el panel lo
     * seguía mostrando: en lugar del 404 lo dice (prerequisiteGone()) y devuelve null. Saberlo cuesta una consulta,
     * solo cuando no lo encuentra.
     *
     * @throws ModelNotFoundException<SubjectPrerequisite>
     */
    private function prerequisite(string $id, StudyPlanQuery $plans, PlanGridEditorQuery $grids): ?SubjectPrerequisite
    {
        $plan = $this->plan($plans);
        $this->authorize('editGrid', $plan);

        try {
            return $grids->prerequisiteOrFail($plan, $id);
        } catch (ModelNotFoundException $exception) {
            if (! $grids->prerequisiteWasRemoved($plan, $id)) {
                throw $exception;
            }

            $this->prerequisiteGone();

            return null;
        }
    }

    /**
     * El requisito ya no está: otra sesión lo quitó, solo o con su materia. Al pintar, el panel ya sale sin él.
     */
    private function prerequisiteGone(): void
    {
        $this->resetValidation('kind');
        $this->cycle = null;
        $this->info('Ese requisito ya no está.', 'La retícula ya está al día.');
    }

    /**
     * Las materias de esta retícula que «Agregar requisito» ofrece para la búsqueda: todas menos la del panel y las
     * que ya pide, con cada palabra en la clave o el nombre (sin importar mayúsculas ni acentos), en el orden de la
     * retícula y a lo más REQUISITE_LIMIT. Null sin búsqueda o con una materia ya elegida. No consulta: filtra
     * la retícula que ya se leyó.
     *
     * @param  list<array<string, mixed>>  $rows
     * @param  array<string, mixed>  $selected
     * @return list<array{subject_id: string, code: string, name: string, semester: int}>|null
     */
    private function requisiteOptions(array $rows, array $selected): ?array
    {
        $fold = fn (string $text): string => Str::lower(Str::ascii($text));
        $words = preg_split('/\s+/u', $fold($this->requisiteSearch), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        if ($words === [] || $this->prerequisite_subject_id !== null) {
            return null;
        }

        $taken = array_fill_keys([$selected['subject_id'], ...array_column($selected['requires'], 'subject_id')], true);
        $options = [];

        foreach ($rows as $row) {
            $text = $fold("{$row['code']} {$row['name']}");

            if (isset($taken[$row['subject_id']]) || array_filter($words, fn (string $word): bool => ! str_contains($text, $word)) !== []) {
                continue;
            }

            $options[] = ['subject_id' => $row['subject_id'], 'code' => $row['code'], 'name' => $row['name'], 'semester' => $row['semester']];

            if (count($options) === self::REQUISITE_LIMIT) {
                break;
            }
        }

        return $options;
    }

    /**
     * La cadena del ciclo que se rechazó (`cycle`), del requisito a la materia, con sus claves; null sin ciclo o
     * si la retícula ya no lo tiene.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<string>|null
     */
    private function cyclePath(array $rows): ?array
    {
        if ($this->cycle === null) {
            return null;
        }

        $edges = [];

        foreach ($rows as $row) {
            foreach ($row['requires'] as $requisite) {
                if ($requisite['kind'] === PrerequisiteKind::Seriacion->value) {
                    $edges[$row['subject_id']][$requisite['subject_id']] = true;
                }
            }
        }

        $path = SeriationRules::cyclePath($edges, $this->cycle['subject_id'], $this->cycle['prerequisite_subject_id']);
        $codes = array_column($rows, 'code', 'subject_id');

        return $path === null ? null : array_map(fn (string $id): string => $codes[$id], $path);
    }

    /**
     * Los semestres del 1 al `$last` para un select.
     *
     * @return list<array{id: string, name: string}>
     */
    private function semesterOptions(int $last): array
    {
        return array_map(fn (int $semester): array => ['id' => (string) $semester, 'name' => (string) $semester], range(1, max(1, $last)));
    }

    /**
     * Las especialidades del plan para un select; una inactiva no se puede elegir, salvo la que ya tiene la
     * materia (`$current`): conservarla vale.
     *
     * @param  list<array{id: string, code: string, name: string, is_active: bool, subjects: int}>  $specialties
     * @return list<array{id: string, name: string, disabled: bool}>
     */
    private function specialtyOptions(array $specialties, ?string $current = null): array
    {
        return array_map(fn (array $specialty): array => [
            'id' => $specialty['id'],
            'name' => $specialty['name'].($specialty['is_active'] ? '' : ' (inactiva)'),
            'disabled' => ! $specialty['is_active'] && $specialty['id'] !== $current,
        ], $specialties);
    }

    /**
     * Lo que dice la confirmación de quitar la materia del panel: las materias que la piden y se quedan sin
     * ningún requisito al quitarla.
     *
     * @param  list<array<string, mixed>>  $rows
     * @param  array<string, mixed>  $selected
     * @return array{orphans: list<string>}
     */
    private function removal(array $rows, array $selected): array
    {
        $byId = array_column($rows, null, 'subject_id');
        $orphans = array_filter($selected['required_by'], fn (array $requisite): bool => count($byId[$requisite['subject_id']]['requires'] ?? []) === 1);

        return ['orphans' => array_values(array_unique(array_column($orphans, 'code')))];
    }

    /**
     * Lo que se resalta en la retícula: la materia elegida con su cadena completa (lo que pide y lo que abre,
     * hasta el final, solo por seriación), o todas las materias de la cadena de la leyenda; y lo que dice la barra
     * de selección, como en la retícula del alumno (vacío sin nada resaltado).
     *
     * @param  array<string, mixed>  $grid
     * @param  list<array<string, mixed>>  $rows
     * @param  array<string, mixed>|null  $selected
     * @return array{focused: bool, onChain: array<string, true>, info: string}
     */
    private function highlight(array $grid, array $rows, ?array $selected): array
    {
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
            return ['focused' => false, 'onChain' => [], 'info' => ''];
        }

        $byId = array_column($rows, null, 'subject_id');
        $seriation = fn (array $requisites): array => array_column(array_filter($requisites, fn (array $requisite): bool => $requisite['kind'] === 'seriacion'), 'subject_id');
        // Se salta un requisito que no es materia de esta retícula: PlanGridEditorQuery ya los filtra, pero el
        // recorrido no depende de eso.
        $walk = function (string $start, string $direction) use ($byId, $seriation): array {
            $seen = [];
            $pending = [$start];

            while ($pending !== []) {
                foreach ($seriation($byId[array_pop($pending)][$direction] ?? []) as $next) {
                    if (isset($byId[$next]) && ! isset($seen[$next])) {
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
     * Las cifras del encabezado de un semestre: los créditos de sus materias del tronco común. Los de las de
     * especialidad dependen de cuál elija el alumno, y cuántas materias tiene ya se ve.
     *
     * @param  list<array<string, mixed>>  $rows
     */
    private function commonCredits(array $rows): int
    {
        return array_sum(array_column(array_filter($rows, fn (array $row): bool => $row['specialty_id'] === null), 'credits'));
    }

    /**
     * Clave y nombre para el toast, escapados: MaryUI lo pinta con x-html.
     */
    private function describe(Specialty $specialty): string
    {
        return e("{$specialty->code} · {$specialty->name}");
    }
};
