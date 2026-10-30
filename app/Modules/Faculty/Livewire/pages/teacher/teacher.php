<?php

use App\Modules\Core\Actions\ResetUserPassword;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Queries\ScopedContext;
use App\Modules\Faculty\Actions\AddPosition;
use App\Modules\Faculty\Actions\RemoveAdscription;
use App\Modules\Faculty\Actions\RemovePosition;
use App\Modules\Faculty\Actions\SetPrimaryAdscription;
use App\Modules\Faculty\Actions\SetTeacherActive;
use App\Modules\Faculty\Actions\UpdatePosition;
use App\Modules\Faculty\Actions\UpdateTeacher;
use App\Modules\Faculty\Enums\PositionKind;
use App\Modules\Faculty\Enums\TeacherDegree;
use App\Modules\Faculty\Models\Teacher;
use App\Modules\Faculty\Models\TeacherDepartment;
use App\Modules\Faculty\Queries\TeacherQuery;
use App\Modules\Faculty\Queries\TeacherRecord;
use App\Modules\Faculty\Queries\TeacherRecordQuery;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Mary\Traits\Toast;

/**
 * Ficha del docente (tarea 1.13, spec 4.3 y S8), con la forma de la ficha del alumno: encabezado con sus datos y
 * cifras, y cinco pestañas. Datos (sus datos, sus adscripciones: quitar y hacer principal) y Plazas (agregar,
 * editar y quitar) son de esta tarea; Disponibilidad, Perfil e Historial muestran su lugar hasta que las tareas
 * siguientes las llenen. «Acciones» trae darlo de baja o reactivarlo y «Restablecer contraseña», que es solo del
 * administrador.
 *
 * Lo ven y cambian el admin, la subdirección y los jefes, cada quien los docentes de su alcance (TeacherPolicy). El
 * componente lee con TeacherQuery y TeacherRecordQuery, autoriza en mount(), en with() y en cada método (una
 * petición puede llegar con una página que abrió otra sesión), valida la forma de los datos y llama a las Actions,
 * que hacen cumplir las reglas; un error de regla de una acción de confirmación sale en un toast.
 */
new #[Title('Ficha del docente')] class extends Component
{
    use Toast;

    /** Las pestañas de la ficha, en su orden. */
    private const array TABS = ['datos', 'plazas', 'disponibilidad', 'perfil', 'historial'];

    /** El docente de la ficha. El navegador no lo puede cambiar. */
    #[Locked]
    public string $teacherId;

    /** Va en la URL para volver a la misma pestaña; otro valor muestra Datos. */
    #[Url]
    public string $tab = 'datos';

    public bool $showDataModal = false;

    public string $name = '';

    public string $email = '';

    /** Valor de TeacherDegree; '' es sin grado. */
    public string $degree = '';

    /** `Y-m-d`; '' es sin fecha. */
    public string $hired_on = '';

    public bool $showRemoveAdscriptionModal = false;

    /** La adscripción que se confirma quitar. */
    #[Locked]
    public ?string $removingAdscriptionId = null;

    public bool $showPositionModal = false;

    /** La plaza que se edita; null es una plaza nueva. */
    #[Locked]
    public ?string $editingPositionId = null;

    /** Valor de PositionKind. */
    public string $kind = '';

    public string $base_hours = '';

    public string $valid_from = '';

    public string $valid_to = '';

    public bool $showRemovePositionModal = false;

    /** La plaza que se confirma quitar. */
    #[Locked]
    public ?string $removingPositionId = null;

    public function mount(string $teacher, TeacherQuery $teachers): void
    {
        $model = $teachers->findOrFail($teacher);
        $this->authorize('view', $model);
        $this->teacherId = $model->id;
        $this->updatedTab();
    }

    /**
     * Una pestaña que no existe (un enlace viejo o mal escrito) muestra Datos.
     */
    public function updatedTab(): void
    {
        $this->tab = in_array($this->tab, self::TABS, true) ? $this->tab : 'datos';
    }

    /**
     * Al elegir un tipo de plaza con horas fijas, las horas se ponen solas.
     */
    public function updatedKind(): void
    {
        $fixed = PositionKind::tryFrom($this->kind)?->fixedHours();

        if ($fixed !== null) {
            $this->base_hours = (string) $fixed;
        } elseif ($this->base_hours === '0') {
            $this->base_hours = '';
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function with(TeacherQuery $teachers, TeacherRecordQuery $records, ScopedContext $context): array
    {
        $teacher = $teachers->findOrFail($this->teacherId);
        $this->authorize('view', $teacher);

        $record = $records->for($teacher, $context->activeTerm());
        $adscriptionCount = $record->adscriptions->count();

        // Quitar es de quien cubre esa adscripción; el motivo de lo deshabilitado se escribe junto al botón.
        $removal = [];

        foreach ($record->adscriptions as $adscription) {
            $removal[$adscription->id] = match (true) {
                $adscriptionCount <= 1 => 'Es la única: para quitarla, desactiva al docente.',
                $adscription->is_primary => 'Es la principal: primero haz principal otra.',
                ! Gate::allows('removeAdscription', [Teacher::class, $adscription]) => 'Solo quien administra ese departamento y campus la quita.',
                default => null,
            };
        }

        $removing = $this->removingAdscriptionId === null ? null : $record->adscriptions->firstWhere('id', $this->removingAdscriptionId);

        return [
            'record' => $record,
            'removal' => $removal,
            'removing' => $removing,
            'removingConsequences' => $removing === null ? [] : $this->consequencesOfRemoving($record, $removing),
            'removingPosition' => $this->removingPositionId === null ? null : $record->positions->firstWhere('id', $this->removingPositionId),
            'positionCounts' => $this->showPositionModal ? $this->positionCountsInTerm($record->term) : false,
            'canReset' => Gate::allows('resetPassword', $teacher),
            'degrees' => array_map(fn (TeacherDegree $degree): array => ['id' => $degree->value, 'name' => $degree->label()], TeacherDegree::cases()),
            'kinds' => array_map(fn (PositionKind $kind): array => [
                'id' => $kind->value,
                'name' => $kind->label().($kind->fixedHours() === null ? '' : " · {$kind->fixedHours()} h"),
            ], PositionKind::cases()),
            'tabs' => [
                'datos' => ['Datos', null],
                'plazas' => ['Plazas', $record->positions->count()],
                'disponibilidad' => ['Disponibilidad', null],
                'perfil' => ['Perfil', null],
                'historial' => ['Historial', null],
            ],
        ];
    }

    public function editData(TeacherQuery $teachers): void
    {
        $teacher = $teachers->findOrFail($this->teacherId);
        $this->authorize('update', $teacher);

        $this->resetValidation();
        $this->name = $teacher->name;
        $this->email = (string) $teacher->email;
        $this->degree = $teacher->degree?->value ?? '';
        $this->hired_on = $teacher->hired_on?->toDateString() ?? '';
        $this->showDataModal = true;
    }

    public function saveData(TeacherQuery $teachers, UpdateTeacher $updateTeacher): void
    {
        $teacher = $teachers->findOrFail($this->teacherId);
        $this->authorize('update', $teacher);

        $data = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'degree' => ['nullable', Rule::enum(TeacherDegree::class)],
            'hired_on' => ['nullable', 'date_format:Y-m-d'],
        ]);

        $teacher = $updateTeacher->handle($teacher, [
            'name' => $data['name'],
            'email' => (string) $data['email'],
            'degree' => (string) $data['degree'],
            'hired_on' => (string) $data['hired_on'],
        ]);

        $this->success('Datos actualizados', e("{$teacher->employee_number} · {$teacher->name}"));
        $this->showDataModal = false;
    }

    /**
     * Dar de baja o reactivar. Reactivar a uno sin usuario se lo crea con la contraseña por defecto: sin ella, un toast.
     */
    public function toggleActive(TeacherQuery $teachers, SetTeacherActive $setTeacherActive): void
    {
        $teacher = $teachers->findOrFail($this->teacherId);
        $this->authorize('deactivate', $teacher);

        try {
            $teacher = $setTeacherActive->handle($teacher, ! $teacher->is_active);
        } catch (RuntimeException $exception) {
            $this->error('No se pudo cambiar el estado', e($exception->getMessage()));

            return;
        }

        $this->success($teacher->is_active ? 'Docente activado' : 'Docente desactivado', e("{$teacher->employee_number} · {$teacher->name}"));
    }

    /**
     * Devuelve el usuario del docente a la contraseña por defecto y lo obliga a cambiarla. Solo el administrador.
     */
    public function resetPassword(TeacherQuery $teachers, ResetUserPassword $resetUserPassword, ScopedContext $context): void
    {
        $teacher = $teachers->findOrFail($this->teacherId);
        $this->authorize('resetPassword', $teacher);

        if ($teacher->user === null) {
            $this->error('No se pudo restablecer la contraseña', 'El docente no tiene usuario.');

            return;
        }

        try {
            $resetUserPassword->handle($teacher->user, $context->user());
        } catch (RuntimeException $exception) {
            $this->error('No se pudo restablecer la contraseña', e($exception->getMessage()));

            return;
        }

        $this->success('Contraseña restablecida', e("{$teacher->user->username} debe cambiarla al entrar."));
    }

    public function confirmRemoveAdscription(string $id, TeacherQuery $teachers, TeacherRecordQuery $records): void
    {
        $adscription = $records->findAdscription($teachers->findOrFail($this->teacherId), $id);
        $this->authorize('removeAdscription', [Teacher::class, $adscription]);

        $this->removingAdscriptionId = $adscription->id;
        $this->showRemoveAdscriptionModal = true;
    }

    public function removeAdscription(TeacherQuery $teachers, TeacherRecordQuery $records, RemoveAdscription $removeAdscription): void
    {
        $this->showRemoveAdscriptionModal = false;

        if ($this->removingAdscriptionId === null) {
            return;
        }

        $adscription = $records->findAdscription($teachers->findOrFail($this->teacherId), $this->removingAdscriptionId);
        $this->authorize('removeAdscription', [Teacher::class, $adscription]);
        $this->removingAdscriptionId = null;

        if (! $this->attempt(fn () => $removeAdscription->handle($adscription), 'No se pudo quitar la adscripción')) {
            return;
        }

        // Quien quitó la última adscripción de su alcance ya no cubre al docente: vuelve a la lista en lugar de un 403.
        $stillVisible = Gate::allows('view', $teachers->findOrFail($this->teacherId));

        $this->success('Adscripción quitada', 'Queda en la bitácora.', redirectTo: $stillVisible ? null : route('faculty.teachers'));
    }

    public function setPrimary(string $id, TeacherQuery $teachers, TeacherRecordQuery $records, SetPrimaryAdscription $setPrimaryAdscription): void
    {
        $teacher = $teachers->findOrFail($this->teacherId);
        $this->authorize('update', $teacher);
        $adscription = $records->findAdscription($teacher, $id);

        if ($this->attempt(fn () => $setPrimaryAdscription->handle($adscription), 'No se pudo cambiar la principal')) {
            $this->success('Adscripción principal cambiada', 'Queda en la bitácora.');
        }
    }

    public function createPosition(TeacherQuery $teachers): void
    {
        $this->authorize('managePositions', $teachers->findOrFail($this->teacherId));

        $this->resetValidation();
        $this->reset('editingPositionId', 'valid_from', 'valid_to');
        $this->kind = PositionKind::TiempoCompleto->value;
        $this->updatedKind();
        $this->showPositionModal = true;
    }

    public function editPosition(string $id, TeacherQuery $teachers, TeacherRecordQuery $records): void
    {
        $teacher = $teachers->findOrFail($this->teacherId);
        $this->authorize('managePositions', $teacher);
        $position = $records->findPosition($teacher, $id);

        $this->resetValidation();
        $this->editingPositionId = $position->id;
        $this->kind = $position->kind->value;
        $this->base_hours = (string) $position->base_hours;
        $this->valid_from = $position->valid_from?->toDateString() ?? '';
        $this->valid_to = $position->valid_to?->toDateString() ?? '';
        $this->showPositionModal = true;
    }

    public function savePosition(TeacherQuery $teachers, TeacherRecordQuery $records, AddPosition $addPosition, UpdatePosition $updatePosition): void
    {
        $teacher = $teachers->findOrFail($this->teacherId);
        $this->authorize('managePositions', $teacher);

        $data = $this->validate([
            'kind' => ['required', Rule::enum(PositionKind::class)],
            'base_hours' => ['nullable', 'string', 'max:4'],
            'valid_from' => ['nullable', 'date_format:Y-m-d'],
            'valid_to' => ['nullable', 'date_format:Y-m-d'],
        ]);

        if ($this->editingPositionId === null) {
            $addPosition->handle($teacher, $data);
            $this->success('Plaza agregada', 'Sus horas base cuentan desde que la plaza esté vigente.');
        } else {
            $updatePosition->handle($records->findPosition($teacher, $this->editingPositionId), $data);
            $this->success('Plaza actualizada', 'Queda en la bitácora.');
        }

        $this->showPositionModal = false;
    }

    public function confirmRemovePosition(string $id, TeacherQuery $teachers, TeacherRecordQuery $records): void
    {
        $teacher = $teachers->findOrFail($this->teacherId);
        $this->authorize('managePositions', $teacher);

        $this->removingPositionId = $records->findPosition($teacher, $id)->id;
        $this->showRemovePositionModal = true;
    }

    public function removePosition(TeacherQuery $teachers, TeacherRecordQuery $records, RemovePosition $removePosition): void
    {
        $this->showRemovePositionModal = false;
        $teacher = $teachers->findOrFail($this->teacherId);
        $this->authorize('managePositions', $teacher);

        if ($this->removingPositionId === null) {
            return;
        }

        $position = $records->findPosition($teacher, $this->removingPositionId);
        $this->removingPositionId = null;

        if ($this->attempt(fn () => $removePosition->handle($position), 'No se pudo quitar la plaza')) {
            $this->success('Plaza quitada', 'Dejan de contar sus horas base. Queda en la bitácora.');
        }
    }

    /**
     * @return array<string, string>
     */
    protected function validationAttributes(): array
    {
        return [
            'name' => 'nombre',
            'email' => 'correo',
            'degree' => 'grado',
            'hired_on' => 'fecha de ingreso',
            'kind' => 'tipo de plaza',
            'base_hours' => 'horas base',
            'valid_from' => 'inicio de la vigencia',
            'valid_to' => 'fin de la vigencia',
        ];
    }

    /**
     * Corre una acción de confirmación; si una regla la rechaza o falta la contraseña por defecto, lo dice en un toast
     * de error con el motivo y da false.
     */
    private function attempt(Closure $action, string $title): bool
    {
        try {
            $action();
        } catch (ValidationException $exception) {
            $this->error($title, e(collect($exception->errors())->flatten()->implode(' ')));

            return false;
        } catch (RuntimeException $exception) {
            $this->error($title, e($exception->getMessage()));

            return false;
        }

        return true;
    }

    /**
     * Lo que pasa al quitar una adscripción, escrito en el modal de confirmación.
     *
     * @return list<string>
     */
    private function consequencesOfRemoving(TeacherRecord $record, TeacherDepartment $adscription): array
    {
        $where = "{$adscription->department?->code} en {$adscription->campus?->code}";
        $consequences = ["Deja de aparecer en Docentes de {$where}."];

        $othersInCampus = $record->adscriptions->contains(fn (TeacherDepartment $other): bool => $other->id !== $adscription->id && $other->campus_id === $adscription->campus_id);

        if (! $othersInCampus && $record->user !== null) {
            $consequences[] = "Era su única adscripción en {$adscription->campus?->name}: su usuario pierde el acceso a ese campus.";
        }

        $consequences[] = 'Queda en la bitácora.';

        return $consequences;
    }

    /**
     * Si la plaza del formulario cuenta en las horas base del periodo (S1, supuesto por confirmar: vigente el primer
     * día): null si el periodo no existe o las fechas todavía no son válidas.
     */
    private function positionCountsInTerm(?Term $term): ?bool
    {
        $day = $term?->starts_on->toDateString();
        $from = trim($this->valid_from);
        $to = trim($this->valid_to);

        if ($day === null || ($from !== '' && ! strtotime($from)) || ($to !== '' && ! strtotime($to))) {
            return null;
        }

        return ($from === '' || $from <= $day) && ($to === '' || $to >= $day);
    }
};
