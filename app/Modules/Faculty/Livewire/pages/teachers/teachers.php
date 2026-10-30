<?php

use App\Modules\Academic\Models\Department;
use App\Modules\Academic\Queries\DepartmentQuery;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Queries\CampusQuery;
use App\Modules\Core\Queries\ScopedContext;
use App\Modules\Core\Support\DefaultPassword;
use App\Modules\Faculty\Actions\AddAdscription;
use App\Modules\Faculty\Actions\CreateTeacher;
use App\Modules\Faculty\Actions\SetTeacherActive;
use App\Modules\Faculty\Actions\UpdateTeacher;
use App\Modules\Faculty\Enums\PositionKind;
use App\Modules\Faculty\Enums\TeacherDegree;
use App\Modules\Faculty\Jobs\ProvisionTeacherUsersJob;
use App\Modules\Faculty\Models\Teacher;
use App\Modules\Faculty\Queries\TeacherQuery;
use App\Modules\Faculty\Rules\TeacherRules;
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
 * Catálogo de Docentes (tarea 1.12, spec 4.3), con la forma de Campus y los filtros de Materias: tabla con búsqueda
 * por número o nombre y filtros de departamento, campus, tipo de plaza y estado; alta y edición en un modal;
 * activar o desactivar desde «Acciones». Un docente no se borra: se da de baja, y su usuario con él.
 *
 * Lo ven el admin, la subdirección, los jefes de departamento y los jefes de proyecto docente, cada quien los
 * docentes de su alcance (TeacherPolicy; TeacherQuery lista con la misma regla). El alta lleva la adscripción de
 * quien da de alta: el jefe, la suya, sin elegirla; la subdirección elige el departamento en su campus; el admin,
 * departamento y campus. Si el número ya existe, no se da de alta otra vez: se ofrece adscribirlo a ese departamento
 * y campus sin cambiar sus datos (S9, supuesto por confirmar). El admin además encola «Dar de alta usuarios»
 * (ProvisionTeacherUsersJob).
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

    /** Id del campus de una adscripción; '' son todos. */
    #[Session]
    public string $campusFilter = '';

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

    /** La adscripción del alta. */
    public string $department_id = '';

    public string $campus_id = '';

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
            $this->campusFilter === '' ? null : $this->campusFilter,
            PositionKind::tryFrom($this->kindFilter),
            match ($this->status) {
                'active' => true,
                'inactive' => false,
                default => null,
            },
        );
        $targets = $this->showModal && $this->editingId === null ? $teachers->adscriptionTargets($context) : ['departments' => [], 'campuses' => []];

        return [
            'teachers' => $page,
            'hours' => $teachers->baseHoursFor($term, $page),
            'term' => $term,
            'options' => $options,
            'targets' => $targets,
            'targetLabel' => $this->targetLabel($targets),
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
        if (in_array($property, ['search', 'departmentFilter', 'campusFilter', 'kindFilter', 'status'], true)) {
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
        $this->reset('editingId', 'existingId', 'existingLabel', 'employee_number', 'name', 'email', 'degree', 'hired_on', 'department_id', 'campus_id');

        // Lo que no tiene más que una opción ya va elegido: el jefe no elige su departamento ni la subdirección su campus.
        $targets = $teachers->adscriptionTargets($context);
        $campusIds = array_column($targets['campuses'], 'id');
        $this->department_id = count($targets['departments']) === 1 ? $targets['departments'][0]['id'] : '';
        $this->campus_id = match (true) {
            count($campusIds) === 1 => $campusIds[0],
            in_array($context->activeCampus()?->id, $campusIds, true) => $context->activeCampus()->id,
            default => '',
        };
        $this->showModal = true;
    }

    public function edit(string $id, TeacherQuery $teachers): void
    {
        $teacher = $teachers->findOrFail($id);
        $this->authorize('update', $teacher);

        $this->resetValidation();
        $this->reset('existingId', 'existingLabel', 'department_id', 'campus_id');
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
        [$department, $campus] = $this->allowedTarget($teachers, $context);

        // El docente nuevo recibe usuario con la contraseña por defecto.
        if ($this->defaultPasswordIsMissing($defaultPassword, 'No se pudo dar de alta al docente')) {
            return;
        }

        $teacher = $createTeacher->handle($data, $department, $campus);

        if ($teacher->user_id === null) {
            $this->warning('Docente creado sin usuario', $this->describe($teacher).e(": ya hay un usuario {$teacher->employee_number} que no se puede ligar; lo resuelve el administrador."));
        } else {
            $this->success('Docente creado', $this->describe($teacher));
        }

        $this->showModal = false;
    }

    /**
     * S9: adscribe al docente que ya existe con el número capturado al departamento y campus del alta, sin cambiar
     * sus datos.
     */
    public function adscribeExisting(AddAdscription $addAdscription, TeacherQuery $teachers, ScopedContext $context, DefaultPassword $defaultPassword): void
    {
        $this->authorize('create', Teacher::class);

        if ($this->existingId === null) {
            return;
        }

        $teacher = $teachers->findOrFail($this->existingId);
        $this->validate(['department_id' => ['required', 'string'], 'campus_id' => ['required', 'string']]);
        [$department, $campus] = $this->allowedTarget($teachers, $context);

        // Adscribirlo sincroniza su acceso: un docente activo sin usuario lo recibe con la contraseña por defecto.
        if ($teacher->is_active && $teacher->user_id === null && $this->defaultPasswordIsMissing($defaultPassword, 'No se pudo adscribir al docente')) {
            return;
        }

        $addAdscription->handle($teacher, $department, $campus);

        $this->success(e("Docente adscrito a {$department->code} en {$campus->code}"), $this->describe($teacher));
        $this->showModal = false;
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
            'campus_id' => 'campus',
        ];
    }

    /**
     * Los datos validados en forma. El número y la adscripción solo en un alta: el número no se edita.
     *
     * @return array{employee_number?: string, name: string, email: string, degree: string, hired_on: string}
     */
    private function validatedData(bool $creating = false): array
    {
        $data = $this->validate([
            ...($creating ? [
                'employee_number' => ['required', 'string', 'max:255'],
                'department_id' => ['required', 'string'],
                'campus_id' => ['required', 'string'],
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
     * El departamento y el campus del alta, si están entre los que puede elegir y TeacherPolicy le deja dar de alta
     * en esa pareja (un jefe con dos alcances no puede cruzarlos); si no, un error en el departamento.
     *
     * @return array{0: Department, 1: Campus}
     *
     * @throws ValidationException
     */
    private function allowedTarget(TeacherQuery $teachers, ScopedContext $context): array
    {
        $targets = $teachers->adscriptionTargets($context);
        $allowed = in_array($this->department_id, array_column($targets['departments'], 'id'), true)
            && in_array($this->campus_id, array_column($targets['campuses'], 'id'), true)
            && Gate::allows('create', [Teacher::class, $this->campus_id, $this->department_id]);

        if (! $allowed) {
            throw ValidationException::withMessages(['department_id' => 'No das de alta docentes en ese departamento y campus.']);
        }

        return [app(DepartmentQuery::class)->findOrFail($this->department_id), app(CampusQuery::class)->findOrFail($this->campus_id)];
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
     * «SC en C2»: el destino del alta, con las claves del departamento y del campus elegidos; null si falta uno.
     *
     * @param  array{departments: list<array{id: string, code: string, name: string}>, campuses: list<array{id: string, code: string, name: string}>}  $targets
     */
    private function targetLabel(array $targets): ?string
    {
        $department = collect($targets['departments'])->firstWhere('id', $this->department_id);
        $campus = collect($targets['campuses'])->firstWhere('id', $this->campus_id);

        return $department === null || $campus === null ? null : "{$department['code']} en {$campus['code']}";
    }

    /**
     * Olvida el departamento, el campus o el tipo del filtro que no están entre las opciones: los filtros se
     * recuerdan en la sesión, y uno que ya no existe dejaría la lista vacía sin verse en su select.
     *
     * @param  array{departments: list<array{id: string, name: string}>, campuses: list<array{id: string, name: string}>}  $options
     */
    private function forgetFiltersOutside(array $options): void
    {
        if ($this->departmentFilter !== '' && ! in_array($this->departmentFilter, array_column($options['departments'], 'id'), true)) {
            $this->departmentFilter = '';
        }

        if ($this->campusFilter !== '' && ! in_array($this->campusFilter, array_column($options['campuses'], 'id'), true)) {
            $this->campusFilter = '';
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
