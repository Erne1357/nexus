<?php

use App\Modules\Academic\Models\Department;
use App\Modules\Academic\Queries\DepartmentQuery;
use App\Modules\Core\Queries\ScopedContext;
use App\Modules\Core\Support\DefaultPassword;
use App\Modules\Faculty\Actions\AddAdscription;
use App\Modules\Faculty\Actions\CreateTeacher;
use App\Modules\Faculty\Actions\RemoveAdscription;
use App\Modules\Faculty\Actions\SetTeacherActive;
use App\Modules\Faculty\Actions\UpdateTeacher;
use App\Modules\Faculty\Enums\PositionKind;
use App\Modules\Faculty\Enums\TeacherDegree;
use App\Modules\Faculty\Jobs\ProvisionTeacherUsersJob;
use App\Modules\Faculty\Models\Teacher;
use App\Modules\Faculty\Models\TeacherDepartment;
use App\Modules\Faculty\Policies\TeacherPolicy;
use App\Modules\Faculty\Queries\TeacherQuery;
use App\Modules\Faculty\Rules\TeacherRules;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Session;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;
use Mary\Traits\Toast;

/**
 * Catálogo de Docentes (tarea 1.12, spec 4.3 con la adenda del 30 de octubre de 2026), con la forma de Campus y los
 * filtros de Materias: tabla con búsqueda por número o nombre y filtros de departamento, tipo de plaza y estado;
 * alta y edición en un modal; en «Acciones», «Quitar de <depto>» y «Desactivar/Activar». Un docente no se borra: se
 * da de baja, y su usuario con él. Los docentes son de todo el Tec: la adscripción es solo de un departamento.
 *
 * Lo ven el admin y la Subdirección, a todos, y los jefes de departamento y de proyecto docente, a los de su
 * departamento (TeacherPolicy; TeacherQuery lista con la misma regla). El alta lleva el departamento de quien da de
 * alta: el jefe, el suyo, sin elegirlo; el admin y la Subdirección lo eligen. Si el número ya existe, no se da de
 * alta otra vez: se ofrece adscribirlo a ese departamento sin cambiar sus datos (S9, supuesto por confirmar). La baja
 * es solo del admin y la Subdirección; los jefes quitan al docente de su departamento (RemoveAdscription). El admin
 * además encola «Dar de alta usuarios» (ProvisionTeacherUsersJob) desde «Más acciones».
 *
 * El componente lee con TeacherQuery, autoriza en mount() y en cada acción (una petición puede llegar con una página
 * que abrió otra sesión), valida la forma de los datos y llama a las Actions, que hacen cumplir las reglas. Los
 * campos se llaman como las columnas, así los errores de las Actions caen en su campo.
 */
new #[Title('Docentes')] class extends Component
{
    use Toast, WithPagination;

    /** Número de empleado o nombre; se recuerda en la sesión del usuario (DESIGN.md). */
    #[Session]
    public string $search = '';

    /** Id del departamento de una adscripción; '' son todos. */
    #[Session]
    public string $departmentFilter = '';

    /** Valor de PositionKind; '' son todos. */
    #[Session]
    public string $kindFilter = '';

    /** '' (todos), 'active' o 'inactive'. */
    #[Session]
    public string $status = '';

    public bool $showModal = false;

    /** El docente que se edita; null es un alta. El navegador no lo puede cambiar. */
    #[Locked]
    public ?string $editingId = null;

    /** En un alta, el docente que ya existe con el número capturado (S9); null si no hay. */
    #[Locked]
    public ?string $existingId = null;

    /** Su número y su nombre, para el aviso. */
    #[Locked]
    public string $existingLabel = '';

    public string $employee_number = '';

    public string $name = '';

    public string $email = '';

    /** Valor de TeacherDegree; '' es sin grado. */
    public string $degree = '';

    /** `Y-m-d`; '' es sin fecha. */
    public string $hired_on = '';

    /** El departamento del alta. */
    public string $department_id = '';

    public function mount(): void
    {
        $this->authorize('viewAny', Teacher::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function with(TeacherQuery $teachers, ScopedContext $context): array
    {
        $options = $teachers->filterOptions($context);
        $this->forgetFiltersOutside($options);
        $term = $context->activeTerm();
        $page = $teachers->forTable(
            $context,
            $this->search,
            $this->departmentFilter === '' ? null : $this->departmentFilter,
            PositionKind::tryFrom($this->kindFilter),
            match ($this->status) {
                'active' => true,
                'inactive' => false,
                default => null,
            },
        );
        $targets = $this->showModal && $this->editingId === null ? $teachers->adscriptionTargets($context) : [];

        return [
            'teachers' => $page,
            'hours' => $teachers->baseHoursFor($term, $page),
            'rowActions' => $this->rowActions($page, $context),
            'term' => $term,
            'options' => $options,
            'targets' => $targets,
            'targetCode' => collect($targets)->firstWhere('id', $this->department_id)['code'] ?? null,
            'kinds' => array_map(fn (PositionKind $kind): array => ['id' => $kind->value, 'name' => $kind->label()], PositionKind::cases()),
            'degrees' => array_map(fn (TeacherDegree $degree): array => ['id' => $degree->value, 'name' => $degree->label()], TeacherDegree::cases()),
            'statuses' => [['id' => 'active', 'name' => 'Activos'], ['id' => 'inactive', 'name' => 'Inactivos']],
            'canProvision' => Gate::allows('provisionUsers', Teacher::class),
            // La adscripción y el usuario son las columnas descriptivas: se ocultan debajo de 1024 y de 1200 px.
            'headers' => [
                ['key' => 'employee_number', 'label' => 'Número'],
                ['key' => 'name', 'label' => 'Nombre', 'class' => 'min-w-36 min-[1200px]:min-w-52'],
                ['key' => 'adscriptions', 'label' => 'Adscripción', 'class' => 'max-[1023px]:hidden'],
                ['key' => 'base_hours', 'label' => 'Horas base', 'class' => 'text-right'],
                ['key' => 'has_user', 'label' => 'Usuario', 'class' => 'max-[1200px]:hidden'],
                ['key' => 'is_active', 'label' => 'Estado'],
            ],
            // DESIGN.md, "Insignias de estado": la fila de un inactivo pasa a text-neutral.
            'rowDecoration' => ['text-neutral' => fn (Teacher $teacher): bool => ! $teacher->is_active],
        ];
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['search', 'departmentFilter', 'kindFilter', 'status'], true)) {
            $this->resetPage();
        }
    }

    /**
     * Al capturar el número en un alta, si ya hay un docente con él, ofrece adscribirlo (S9).
     */
    public function updatedEmployeeNumber(): void
    {
        if ($this->editingId !== null) {
            return;
        }

        $this->authorize('create', Teacher::class);
        $this->resetErrorBag('adscription');
        $this->lookUpExisting();
    }

    public function create(TeacherQuery $teachers, ScopedContext $context): void
    {
        $this->authorize('create', Teacher::class);

        $this->resetValidation();
        $this->reset('editingId', 'existingId', 'existingLabel', 'employee_number', 'name', 'email', 'degree', 'hired_on', 'department_id');

        // Si solo puede dar de alta en un departamento (el jefe, el suyo), ya va elegido.
        $targets = $teachers->adscriptionTargets($context);
        $this->department_id = count($targets) === 1 ? $targets[0]['id'] : '';
        $this->showModal = true;
    }

    public function edit(string $id, TeacherQuery $teachers): void
    {
        $teacher = $teachers->findOrFail($id);
        $this->authorize('update', $teacher);

        $this->resetValidation();
        $this->reset('existingId', 'existingLabel', 'department_id');
        $this->editingId = $teacher->id;
        $this->employee_number = $teacher->employee_number;
        $this->name = $teacher->name;
        $this->email = (string) $teacher->email;
        $this->degree = $teacher->degree?->value ?? '';
        $this->hired_on = $teacher->hired_on?->toDateString() ?? '';
        $this->showModal = true;
    }

    public function save(CreateTeacher $createTeacher, UpdateTeacher $updateTeacher, TeacherQuery $teachers, ScopedContext $context, DefaultPassword $defaultPassword): void
    {
        if ($this->editingId !== null) {
            $teacher = $teachers->findOrFail($this->editingId);
            $this->authorize('update', $teacher);
            $teacher = $updateTeacher->handle($teacher, $this->validatedData());
            $this->success('Docente actualizado', $this->describe($teacher));
            $this->showModal = false;

            return;
        }

        $this->authorize('create', Teacher::class);

        // Un número que ya existe no se da de alta otra vez: el modal cambia a «Adscribir» (S9).
        if ($this->lookUpExisting() !== null) {
            return;
        }

        $data = $this->validatedData(creating: true);
        $department = $this->allowedDepartment($teachers, $context);

        // El docente nuevo recibe usuario con la contraseña por defecto.
        if ($this->defaultPasswordIsMissing($defaultPassword, 'No se pudo dar de alta al docente')) {
            return;
        }

        $teacher = $createTeacher->handle($data, $department);

        if ($teacher->user_id === null) {
            $this->warning('Docente creado sin usuario', $this->describe($teacher).e(": ya hay un usuario {$teacher->employee_number} que no se puede ligar; lo resuelve el administrador."));
        } else {
            $this->success('Docente creado', $this->describe($teacher));
        }

        $this->showModal = false;
    }

    /**
     * S9: adscribe al docente que ya existe con el número capturado al departamento del alta, sin cambiar sus datos.
     */
    public function adscribeExisting(AddAdscription $addAdscription, TeacherQuery $teachers, ScopedContext $context): void
    {
        $this->authorize('create', Teacher::class);

        if ($this->existingId === null) {
            return;
        }

        $teacher = $teachers->findOrFail($this->existingId);
        $this->validate(['department_id' => ['required', 'string']]);
        $department = $this->allowedDepartment($teachers, $context);

        $addAdscription->handle($teacher, $department);

        $this->success(e("Docente adscrito a {$department->code}"), $this->describe($teacher));
        $this->showModal = false;
    }

    /**
     * «Quitar de mi departamento»: quita una adscripción del docente; si era la principal, la principal pasa a la más
     * antigua que le quede, y sin ninguna el docente sigue activo (RemoveAdscription).
     */
    public function removeAdscription(string $id, TeacherQuery $teachers, RemoveAdscription $removeAdscription): void
    {
        $adscription = $teachers->findAdscriptionOrFail($id);
        $this->authorize('removeAdscription', [Teacher::class, $adscription]);

        $teacher = $adscription->teacher ?? abort(404);
        $rest = $teacher->adscriptions->reject(fn (TeacherDepartment $other): bool => $other->is($adscription));
        $promoted = $removeAdscription->handle($adscription);
        $outcome = match (true) {
            $rest->isEmpty() => '; queda sin departamento',
            $promoted !== null => '; su adscripción principal pasa a '.$rest->firstWhere('id', $promoted->id)?->department?->code,
            default => '',
        };

        $this->success(e("Docente quitado de {$adscription->department?->code}"), $this->describe($teacher).e($outcome));
    }

    public function toggleActive(string $id, TeacherQuery $teachers, SetTeacherActive $setTeacherActive, DefaultPassword $defaultPassword): void
    {
        $teacher = $teachers->findOrFail($id);
        $this->authorize('deactivate', $teacher);

        // Reactivar a uno sin usuario se lo crea con la contraseña por defecto.
        if (! $teacher->is_active && $teacher->user_id === null && $this->defaultPasswordIsMissing($defaultPassword, 'No se pudo activar a '.e($teacher->employee_number))) {
            return;
        }

        $teacher = $setTeacherActive->handle($teacher, ! $teacher->is_active);

        $this->success($teacher->is_active ? 'Docente activado' : 'Docente desactivado', $this->describe($teacher));
    }

    /**
     * «Dar de alta usuarios»: encola la sincronización del acceso de todos los docentes, a nombre de quien la pide.
     * Sin NEXUS_DEFAULT_PASSWORD no encola nada y dice por qué.
     */
    public function provisionUsers(DefaultPassword $defaultPassword, ScopedContext $context): void
    {
        $this->authorize('provisionUsers', Teacher::class);

        if ($this->defaultPasswordIsMissing($defaultPassword, 'No se puede dar de alta usuarios')) {
            return;
        }

        ProvisionTeacherUsersJob::dispatch($context->user());

        $this->success('Alta de usuarios en cola', 'Se revisa el acceso de todos los docentes en segundo plano; el resultado queda en la bitácora.');
    }

    /**
     * @return array<string, string>
     */
    protected function validationAttributes(): array
    {
        return [
            'employee_number' => 'número de empleado',
            'name' => 'nombre',
            'email' => 'correo',
            'degree' => 'grado',
            'hired_on' => 'fecha de ingreso',
            'department_id' => 'departamento',
        ];
    }

    /**
     * Los datos validados en forma. El número y el departamento solo en un alta: el número no se edita.
     *
     * @return array{employee_number?: string, name: string, email: string, degree: string, hired_on: string}
     */
    private function validatedData(bool $creating = false): array
    {
        $data = $this->validate([
            ...($creating ? [
                'employee_number' => ['required', 'string', 'max:255'],
                'department_id' => ['required', 'string'],
            ] : []),
            'name' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'degree' => ['nullable', Rule::enum(TeacherDegree::class)],
            'hired_on' => ['nullable', 'date_format:Y-m-d'],
        ]);

        return [
            ...($creating ? ['employee_number' => $data['employee_number']] : []),
            'name' => $data['name'],
            'email' => (string) $data['email'],
            'degree' => (string) $data['degree'],
            'hired_on' => (string) $data['hired_on'],
        ];
    }

    /**
     * El departamento del alta, si está entre los que puede elegir y TeacherPolicy le deja dar de alta en él; si no,
     * un error en el departamento.
     *
     * @throws ValidationException
     */
    private function allowedDepartment(TeacherQuery $teachers, ScopedContext $context): Department
    {
        $allowed = in_array($this->department_id, array_column($teachers->adscriptionTargets($context), 'id'), true)
            && Gate::allows('create', [Teacher::class, $this->department_id]);

        if (! $allowed) {
            throw ValidationException::withMessages(['department_id' => 'No das de alta docentes en ese departamento.']);
        }

        return app(DepartmentQuery::class)->findOrFail($this->department_id);
    }

    /**
     * Las acciones de cada fila del menú «Acciones», ya decididas con TeacherPolicy: «Quitar de <depto>» por cada
     * adscripción a un departamento que cubre (las de otros departamentos no se muestran), con el aviso de qué
     * principal queda; y «Desactivar/Activar», deshabilitado con su motivo si no le toca. Sin consultas: las
     * adscripciones ya vienen con la página.
     *
     * @param  LengthAwarePaginator<int, Teacher>  $teachers
     * @return array<string, array{removals: list<array{id: string, label: string, confirm: string, reason: ?string}>, deactivateReason: ?string}>
     */
    private function rowActions(LengthAwarePaginator $teachers, ScopedContext $context): array
    {
        $actions = [];

        foreach ($teachers as $teacher) {
            $removals = [];

            foreach ($teacher->adscriptions as $adscription) {
                if (! $context->coversDepartment(TeacherPolicy::EDITORS, $adscription->department_id)) {
                    continue;
                }

                $adscription->setRelation('teacher', $teacher);
                $removals[] = [
                    'id' => $adscription->id,
                    'label' => 'Quitar de '.$adscription->department?->code,
                    'confirm' => $this->removalWarning($teacher, $adscription),
                    'reason' => Gate::allows('removeAdscription', [Teacher::class, $adscription]) ? null : 'No te puedes quitar a ti mismo de un departamento.',
                ];
            }

            $actions[$teacher->id] = [
                'removals' => $removals,
                'deactivateReason' => match (true) {
                    Gate::allows('deactivate', $teacher) => null,
                    $teacher->user_id !== null && $teacher->user_id === $context->user()?->getKey() => 'No te puedes dar de baja a ti mismo.',
                    default => 'Solo el administrador y la Subdirección dan de baja a un docente.',
                },
            ];
        }

        return $actions;
    }

    /**
     * El aviso antes de quitar una adscripción: qué principal le queda, con la regla de RemoveAdscription (la más
     * antigua de las que quedan; las adscripciones llegan en ese orden detrás de la principal).
     */
    private function removalWarning(Teacher $teacher, TeacherDepartment $adscription): string
    {
        $rest = $teacher->adscriptions->reject(fn (TeacherDepartment $other): bool => $other->is($adscription));
        $outcome = match (true) {
            $rest->isEmpty() => 'Se queda sin departamento, pero sigue activo.',
            $adscription->is_primary => 'Sigue activo y su adscripción principal pasa a '.$rest->first()->department?->code.'.',
            default => 'Sigue activo y su adscripción principal no cambia.',
        };

        return "¿Quitar al docente {$teacher->employee_number} de {$adscription->department?->code}? {$outcome}";
    }

    /**
     * Si falta NEXUS_DEFAULT_PASSWORD, lo dice en un toast de error con `$title` y da true: quien llama no escribe,
     * porque crear un usuario sin ella fallaría a media operación. Se pregunta solo cuando la operación puede crear
     * un usuario.
     */
    private function defaultPasswordIsMissing(DefaultPassword $defaultPassword, string $title): bool
    {
        try {
            $defaultPassword->value();
        } catch (RuntimeException $exception) {
            $this->error($title, e($exception->getMessage()));

            return true;
        }

        return false;
    }

    /**
     * Busca al docente sin borrar con el número capturado y, si existe, lo deja para el aviso de S9.
     */
    private function lookUpExisting(): ?Teacher
    {
        $this->reset('existingId', 'existingLabel');

        try {
            $number = app(TeacherRules::class)->normalizeEmployeeNumber($this->employee_number);
        } catch (ValidationException) {
            return null;
        }

        $teacher = app(TeacherQuery::class)->findByEmployeeNumber($number);

        if ($teacher !== null) {
            $this->existingId = $teacher->id;
            $this->existingLabel = "{$teacher->employee_number} · {$teacher->name}";
        }

        return $teacher;
    }

    /**
     * Olvida el departamento o el tipo del filtro que no están entre las opciones: los filtros se recuerdan en la
     * sesión, y uno que ya no existe dejaría la lista vacía sin verse en su select.
     *
     * @param  array{departments: list<array{id: string, name: string}>}  $options
     */
    private function forgetFiltersOutside(array $options): void
    {
        if ($this->departmentFilter !== '' && ! in_array($this->departmentFilter, array_column($options['departments'], 'id'), true)) {
            $this->departmentFilter = '';
        }

        if ($this->kindFilter !== '' && PositionKind::tryFrom($this->kindFilter) === null) {
            $this->kindFilter = '';
        }
    }

    /**
     * Número y nombre para el toast, escapados: MaryUI lo pinta con x-html.
     */
    private function describe(Teacher $teacher): string
    {
        return e("{$teacher->employee_number} · {$teacher->name}");
    }
};
