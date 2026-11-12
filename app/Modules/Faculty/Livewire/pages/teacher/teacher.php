<?php

use App\Modules\Academic\Models\Department;
use App\Modules\Academic\Models\Subject;
use App\Modules\Core\Actions\AppointRole;
use App\Modules\Core\Actions\EndRoleAssignment;
use App\Modules\Core\Actions\ResetUserPassword;
use App\Modules\Core\Authorization\Actor;
use App\Modules\Core\Authorization\Target;
use App\Modules\Core\Enums\AssignmentEnding;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Enums\UserAccess;
use App\Modules\Core\Models\RoleAssignment;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Queries\AssignmentLabels;
use App\Modules\Core\Queries\KeyLookup;
use App\Modules\Core\Queries\PeopleQuery;
use App\Modules\Faculty\Actions\AddExternalLoad;
use App\Modules\Faculty\Actions\AddPosition;
use App\Modules\Faculty\Actions\InviteTeacherAccess;
use App\Modules\Faculty\Actions\RemoveAdscription;
use App\Modules\Faculty\Actions\RemoveExternalLoad;
use App\Modules\Faculty\Actions\RemovePosition;
use App\Modules\Faculty\Actions\RemoveQualification;
use App\Modules\Faculty\Actions\SetPrimaryAdscription;
use App\Modules\Faculty\Actions\SetQualification;
use App\Modules\Faculty\Actions\SetTeacherActive;
use App\Modules\Faculty\Actions\SyncTeacherAccess;
use App\Modules\Faculty\Actions\UpdateExternalLoad;
use App\Modules\Faculty\Actions\UpdatePosition;
use App\Modules\Faculty\Actions\UpdateTeacher;
use App\Modules\Faculty\Enums\FacultyPermission;
use App\Modules\Faculty\Enums\PositionKind;
use App\Modules\Faculty\Enums\QualificationLevel;
use App\Modules\Faculty\Enums\TeacherDegree;
use App\Modules\Faculty\Models\Teacher;
use App\Modules\Faculty\Models\TeacherDepartment;
use App\Modules\Faculty\Models\TeacherExternalLoad;
use App\Modules\Faculty\Models\TeacherSubjectQualification;
use App\Modules\Faculty\Queries\AvailabilityGridQuery;
use App\Modules\Faculty\Queries\TeacherQuery;
use App\Modules\Faculty\Queries\TeacherRecord;
use App\Modules\Faculty\Queries\TeacherRecordQuery;
use Carbon\CarbonImmutable;
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
 * cifras, y cinco pestañas: Datos (sus datos, sus adscripciones: quitar y hacer principal), Plazas (agregar,
 * editar y quitar), Perfil (las materias que puede dar, apto o preferente, y sus «Horas fuera del ITCJ» del periodo
 * activo; S16 y S17, supuestos por confirmar) e Historial (grupos por periodo y evaluaciones, de solo lectura).
 * Disponibilidad muestra la del periodo activo, de solo lectura (AvailabilityGridQuery), y lleva al editor
 * (faculty::pages.teacher-availability). «Acciones» trae darlo de baja o reactivarlo y «Restablecer contraseña», que es
 * solo del administrador y se llama «Dar acceso» cuando su usuario no tiene contraseña (AC-03), y «Reenviar
 * invitación» (AC-07), del jefe de su departamento y del admin; Datos muestra el correo institucional (AC-06) y el
 * estado del acceso (AC-04).
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

    public string $institutional_email = '';

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

    public bool $showQualificationModal = false;

    /** La materia del perfil que se edita; null es una materia nueva. */
    #[Locked]
    public ?string $editingQualificationId = null;

    /** La materia elegida al declarar una nueva. */
    public string $qualSubjectId = '';

    public string $qualSearch = '';

    /** Valor de QualificationLevel. */
    public string $qualLevel = 'apto';

    public string $qualNotes = '';

    public bool $showLoadModal = false;

    /** Las horas fuera del ITCJ que se editan; null es un origen nuevo. */
    #[Locked]
    public ?string $editingLoadId = null;

    public string $loadSource = '';

    public string $loadHours = '';

    public string $loadNotes = '';

    public bool $showHeadshipModal = false;

    /** Valor de un puesto de Role::HEADSHIPS. */
    public string $headKind = '';

    public string $headDepartmentId = '';

    /**
     * Solo para el jefe de proyecto docente (AZ-S17): su modalidad, que empieza en la única activa, y su campus, opcional
     * (vacío es todos los campus de la modalidad). El jefe es de todo el Tec.
     */
    public string $headModalityId = '';

    public string $headCampusId = '';

    public string $headFrom = '';

    public string $headTo = '';

    public bool $showEndHeadshipModal = false;

    /** La jefatura que se confirma terminar. */
    #[Locked]
    public ?string $endingHeadshipId = null;

    public string $endLastDay = '';

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
    public function with(TeacherQuery $teachers, TeacherRecordQuery $records, Actor $context, PeopleQuery $people, AvailabilityGridQuery $grids): array
    {
        $teacher = $teachers->findOrFail($this->teacherId);
        $this->authorize('view', $teacher);

        $record = $records->for($teacher, $context->activeTerm());
        $adscriptionCount = $record->adscriptions->count();

        // «Quitar de <depto>» es de quien cubre ese departamento (y nadie a sí mismo); el motivo de lo deshabilitado
        // se escribe junto al botón.
        $removal = [];
        $primaryReasons = [];

        foreach ($record->adscriptions as $adscription) {
            $removal[$adscription->id] = Gate::allows('removeAdscription', [Teacher::class, $adscription])
                ? null
                : ($context->allows(FacultyPermission::TeacherDepartmentRemove, Target::of(unit: $adscription->department_id))
                    ? 'No te puedes quitar a ti mismo de un departamento.'
                    : 'Solo quien administra ese departamento lo quita.');
            $primaryReasons[$adscription->id] = Gate::allows('setPrimaryAdscription', [Teacher::class, $adscription])
                ? null
                : 'Solo quien administra ese departamento la hace principal.';
        }

        $tabData = match ($this->tab) {
            'perfil' => $this->profileData($teacher, $records, $teachers, $context, $record->term),
            'historial' => ['history' => $records->history($teacher)],
            'disponibilidad' => [
                'availability' => $record->term === null ? null : $grids->for($teacher, $record->term, $grids->profile($context->activeCampus())),
                'availabilityReason' => Gate::allows('manageAvailability', $teacher) ? null : 'Solo quien administra al docente edita su disponibilidad.',
            ],
            default => [],
        };

        $canAppoint = Gate::allows('viewAny', RoleAssignment::class);
        $headOptions = $canAppoint && $this->showHeadshipModal ? $people->scopeOptions(Role::JefeProyectoDocente, null) : ['modalities' => [], 'campuses' => [], 'units' => []];

        $removing = $this->removingAdscriptionId === null ? null : $record->adscriptions->firstWhere('id', $this->removingAdscriptionId);

        return [
            'subjectOptions' => collect(),
            ...$tabData,
            'canAppoint' => $canAppoint,
            'headships' => $canAppoint && $this->tab === 'datos' ? $records->headships($teacher) : collect(),
            'endingHeadship' => $canAppoint && $this->endingHeadshipId !== null ? $records->findHeadship($teacher, $this->endingHeadshipId) : null,
            'headKinds' => array_map(fn (Role $role): array => ['id' => $role->value, 'name' => $role->label()], Role::HEADSHIPS),
            'departmentOptions' => $headOptions['units'],
            'campusOptions' => $headOptions['campuses'],
            'modalityOptions' => $headOptions['modalities'],
            'record' => $record,
            'removal' => $removal,
            'primaryReasons' => $primaryReasons,
            'removing' => $removing,
            'removingWarning' => $removing === null ? '' : $this->removalWarning($record, $removing),
            'removingPosition' => $this->removingPositionId === null ? null : $record->positions->firstWhere('id', $this->removingPositionId),
            'positionCounts' => $this->showPositionModal ? $this->positionCountsInTerm($record->term) : false,
            'canReset' => Gate::allows('resetPassword', $teacher),
            'grantsAccess' => $record->access?->hasPassword() === false,
            'inviteReason' => $record->access?->hasPassword() === false ? $this->inviteReason($record, Gate::allows('inviteAccess', $teacher)) : null,
            'institutionalDomain' => config('nexus.institutional_email_domain'),
            'institutionalEmailReason' => $this->showDataModal && Gate::denies('updateInstitutionalEmail', $teacher) ? $this->institutionalEmailReason($teacher) : null,
            'canDeactivate' => Gate::allows('deactivate', $teacher),
            'deactivateReason' => $context->allows(FacultyPermission::TeacherDeactivate)
                ? 'No te puedes dar de baja a ti mismo.'
                : 'Solo el administrador y la Subdirección dan de baja.',
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
        $this->institutional_email = (string) $teacher->institutional_email;
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
            'institutional_email' => ['nullable', 'string', 'max:255'],
            'degree' => ['nullable', Rule::enum(TeacherDegree::class)],
            'hired_on' => ['nullable', 'date_format:Y-m-d'],
        ]);

        $changes = [
            'name' => $data['name'],
            'email' => (string) $data['email'],
            'degree' => (string) $data['degree'],
            'hired_on' => (string) $data['hired_on'],
        ];

        if (mb_strtolower(trim((string) $data['institutional_email'])) !== (string) $teacher->institutional_email) {
            if (Gate::denies('updateInstitutionalEmail', $teacher)) {
                $this->addError('institutional_email', $this->institutionalEmailReason($teacher));

                return;
            }

            $changes['institutional_email'] = (string) $data['institutional_email'];
        }

        $teacher = $updateTeacher->handle($teacher, $changes);

        $this->success('Datos actualizados', e("{$teacher->rfc} · {$teacher->name}"));
        $this->showDataModal = false;
    }

    /**
     * Dar de baja o reactivar. Reactivar a uno sin usuario se lo crea sin acceso (AC-02); un error, en un toast.
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

        $this->success($teacher->is_active ? 'Docente activado' : 'Docente desactivado', e("{$teacher->rfc} · {$teacher->name}"));
    }

    /**
     * Devuelve el usuario del docente a la contraseña por defecto y lo obliga a cambiarla. Si no tenía contraseña es
     * «Dar acceso», la habilitación manual (AC-03), que a un usuario inactivo no se le da: no podría entrar. Solo el
     * administrador.
     */
    public function resetPassword(TeacherQuery $teachers, ResetUserPassword $resetUserPassword, Actor $context): void
    {
        $teacher = $teachers->findOrFail($this->teacherId);
        $this->authorize('resetPassword', $teacher);
        $user = $teacher->user;
        $failure = $user !== null && $user->password === null ? 'No se pudo dar acceso' : 'No se pudo restablecer la contraseña';

        if ($user === null) {
            $this->error($failure, 'El docente no tiene usuario.');

            return;
        }

        if ($user->password === null && ! $user->is_active) {
            $this->error($failure, 'Su usuario está inactivo: reactiva al docente antes.');

            return;
        }

        try {
            $granted = $resetUserPassword->handle($user, $context->user());
        } catch (RuntimeException $exception) {
            $this->error($failure, e($exception->getMessage()));

            return;
        }

        $this->success($granted ? 'Acceso dado' : 'Contraseña restablecida', e("{$user->username} entra con la contraseña por defecto y debe cambiarla al entrar."));
    }

    /**
     * «Reenviar invitación» (AC-07): un enlace nuevo a su correo institucional; el anterior deja de servir. Solo a quien
     * ya se invitó: la primera sale cuando su departamento valida el catálogo de docentes. Un rechazo, en un toast.
     */
    public function resendInvitation(TeacherQuery $teachers, InviteTeacherAccess $inviteTeacherAccess, Actor $context): void
    {
        $teacher = $teachers->findOrFail($this->teacherId);
        $this->authorize('inviteAccess', $teacher);

        $sent = $this->attempt(fn () => $inviteTeacherAccess->resend($teacher, $context->user()), 'No se pudo reenviar la invitación');

        if ($sent) {
            $this->success('Invitación reenviada', e("{$teacher->rfc}: a {$teacher->institutional_email}. El enlace anterior ya no sirve."));
        }
    }

    /**
     * Abre el formulario de «Nombrar jefatura». Solo quien nombra puestos (el administrador).
     */
    public function createHeadship(TeacherQuery $teachers, PeopleQuery $people): void
    {
        $this->authorize('create', RoleAssignment::class);
        $teachers->findOrFail($this->teacherId);

        $this->resetValidation();
        $this->reset('headKind', 'headDepartmentId', 'headCampusId', 'headTo');
        $this->headModalityId = $people->defaultModalityId() ?? '';
        $this->headFrom = today()->toDateString();
        $this->showHeadshipModal = true;
    }

    /**
     * Nombra al docente jefe de departamento (de todo el Tec) o jefe de proyecto docente (de una modalidad y,
     * opcionalmente, de un campus; AZ-S17) con AppointRole. Si no tiene usuario, se le crea antes con SyncTeacherAccess; un inactivo no se nombra. Los rechazos
     * de la Action salen en el campo que corresponde.
     */
    public function saveHeadship(TeacherQuery $teachers, PeopleQuery $people, AssignmentLabels $labels, SyncTeacherAccess $syncTeacherAccess, AppointRole $appointRole): void
    {
        $this->authorize('create', RoleAssignment::class);
        $teacher = $teachers->findOrFail($this->teacherId);

        $data = $this->validate([
            'headKind' => ['required', Rule::in(array_map(fn (Role $role): string => $role->value, Role::HEADSHIPS))],
            'headDepartmentId' => ['required', 'string'],
            'headModalityId' => ['nullable', 'string'],
            'headCampusId' => ['nullable', 'string'],
            'headFrom' => ['required', 'date_format:Y-m-d'],
            'headTo' => ['nullable', 'date_format:Y-m-d'],
        ], ['headKind.in' => 'Elige un cargo de la lista.']);
        $role = Role::from($data['headKind']);

        if (! $teacher->is_active) {
            $this->addError('headKind', 'El docente está inactivo: reactívalo antes de nombrarlo.');

            return;
        }

        try {
            if ($teacher->user_id === null) {
                $syncTeacherAccess->handle($teacher);
                $teacher = $teachers->findOrFail($this->teacherId);
            }

            $user = $teacher->user;

            if ($user === null) {
                $this->addError('headKind', 'No se pudo darle usuario al docente: revisa su acceso.');

                return;
            }

            $assignment = $appointRole->handle(
                $user,
                $role,
                $role === Role::JefeProyectoDocente ? $people->campus($data['headCampusId']) : null,
                $people->unit($data['headDepartmentId']),
                null,
                CarbonImmutable::parse($data['headFrom']),
                $data['headTo'] === '' ? null : CarbonImmutable::parse($data['headTo']),
                $role === Role::JefeProyectoDocente ? $people->modality($data['headModalityId']) : null,
            );
        } catch (ValidationException $exception) {
            $fields = ['user' => 'headKind', 'role' => 'headDepartmentId', 'unit' => 'headDepartmentId', 'modality' => 'headModalityId', 'campus' => 'headCampusId', 'to' => 'headTo'];

            foreach ($exception->errors() as $field => $messages) {
                $this->addError($fields[$field] ?? 'headKind', (string) $messages[0]);
            }

            return;
        } catch (RuntimeException $exception) {
            $this->addError('headKind', $exception->getMessage());

            return;
        }

        $this->showHeadshipModal = false;
        $this->success('Jefatura nombrada', e($labels->one($assignment)->summary.' · '.$teacher->rfc));
    }

    public function confirmEndHeadship(string $id, TeacherQuery $teachers, TeacherRecordQuery $records): void
    {
        $this->authorize('viewAny', RoleAssignment::class);

        $head = $records->findHeadship($teachers->findOrFail($this->teacherId), $id);
        $this->authorize('end', $head);

        $this->endingHeadshipId = $head->id;
        $this->endLastDay = today()->toDateString();
        $this->resetValidation();
        $this->showEndHeadshipModal = true;
    }

    /**
     * Termina la jefatura en su último día con EndRoleAssignment: la que ya empezó termina ese día; la que empieza
     * después se quita; la que ya termina antes se queda como está.
     */
    public function endHeadship(TeacherQuery $teachers, TeacherRecordQuery $records, EndRoleAssignment $endRoleAssignment): void
    {
        $this->authorize('viewAny', RoleAssignment::class);

        if ($this->endingHeadshipId === null) {
            return;
        }

        $head = $records->findHeadship($teachers->findOrFail($this->teacherId), $this->endingHeadshipId);
        $this->authorize('end', $head);

        $data = $this->validate(['endLastDay' => ['required', 'date_format:Y-m-d']]);
        $day = CarbonImmutable::parse($data['endLastDay']);

        try {
            $ending = $endRoleAssignment->handle($head, $day);
        } catch (ValidationException $exception) {
            $this->addError('endLastDay', (string) collect($exception->errors())->flatten()->first());

            return;
        } catch (RuntimeException $exception) {
            $this->addError('endLastDay', $exception->getMessage());

            return;
        }

        $this->showEndHeadshipModal = false;
        $this->endingHeadshipId = null;

        match ($ending) {
            AssignmentEnding::Ended => $this->success('Jefatura terminada', e('Último día: '.$day->format('d/m/Y'))),
            AssignmentEnding::Removed => $this->success('Jefatura quitada', $head->valid_from->isToday() ? 'Empezaba hoy.' : 'Todavía no empezaba.'),
            AssignmentEnding::Unchanged => $this->info('Sin cambios', e('Esa jefatura ya termina el '.$head->valid_to->format('d/m/Y').'.')),
        };
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

        $teacher = $teachers->findOrFail($this->teacherId);
        $removal = null;

        if (! $this->attempt(function () use ($removeAdscription, $adscription, &$removal): void {
            $removal = $removeAdscription->handle($adscription);
        }, 'No se pudo quitar de ese departamento')) {
            return;
        }

        $outcome = $removal['outcome'] === null ? '' : "; {$removal['outcome']}";
        $code = Department::withTrashed()->whereKey($adscription->department_id)->value('code');

        // Quien quitó la última adscripción de su alcance ya no cubre al docente: vuelve a la lista en lugar de un 403.
        $stillVisible = Gate::allows('view', $teachers->findOrFail($this->teacherId));

        $this->success(e("Quitado de {$code}"), e($teacher->rfc.$outcome), redirectTo: $stillVisible ? null : route('faculty.teachers'));
    }

    public function setPrimary(string $id, TeacherQuery $teachers, TeacherRecordQuery $records, SetPrimaryAdscription $setPrimaryAdscription): void
    {
        $teacher = $teachers->findOrFail($this->teacherId);
        $this->authorize('update', $teacher);
        $adscription = $records->findAdscription($teacher, $id);
        $this->authorize('setPrimaryAdscription', [Teacher::class, $adscription]);

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

    public function createQualification(TeacherQuery $teachers): void
    {
        $this->authorize('view', $teachers->findOrFail($this->teacherId));

        $this->resetValidation();
        $this->reset('editingQualificationId', 'qualSubjectId', 'qualSearch', 'qualNotes');
        $this->qualLevel = QualificationLevel::Apto->value;
        $this->showQualificationModal = true;
    }

    public function editQualification(string $id, TeacherQuery $teachers, TeacherRecordQuery $records): void
    {
        $teacher = $teachers->findOrFail($this->teacherId);
        $qualification = $records->findQualification($teacher, $id);
        $this->authorize('manageQualification', [$teacher, Subject::withTrashed()->findOrFail($qualification->subject_id)]);

        $this->resetValidation();
        $this->editingQualificationId = $qualification->id;
        $this->qualSubjectId = $qualification->subject_id;
        $this->qualLevel = $qualification->level->value;
        $this->qualNotes = (string) $qualification->notes;
        $this->showQualificationModal = true;
    }

    public function saveQualification(TeacherQuery $teachers, TeacherRecordQuery $records, SetQualification $setQualification, Actor $context): void
    {
        $teacher = $teachers->findOrFail($this->teacherId);
        $this->authorize('view', $teacher);

        $data = $this->validate([
            'qualSubjectId' => ['required', 'string'],
            'qualLevel' => ['required', Rule::enum(QualificationLevel::class)],
            'qualNotes' => ['nullable', 'string', 'max:500'],
        ]);

        // Al editar, la materia es la del renglón, no lo que mande el navegador.
        $subject = $this->editingQualificationId === null
            ? KeyLookup::findOrFail(Subject::withTrashed(), $data['qualSubjectId'])
            : Subject::withTrashed()->findOrFail($records->findQualification($teacher, $this->editingQualificationId)->subject_id);
        $this->authorize('manageQualification', [$teacher, $subject]);

        try {
            $setQualification->handle($teacher, $subject, QualificationLevel::from($data['qualLevel']), $context->user(), $data['qualNotes']);
        } catch (ValidationException $exception) {
            $this->addError('qualSubjectId', (string) collect($exception->errors())->flatten()->first());

            return;
        }

        $this->success('Perfil actualizado', e("{$subject->code} · {$subject->name}"));
        $this->showQualificationModal = false;
    }

    public function removeQualification(string $id, TeacherQuery $teachers, TeacherRecordQuery $records, RemoveQualification $removeQualification): void
    {
        $teacher = $teachers->findOrFail($this->teacherId);
        $qualification = $records->findQualification($teacher, $id);
        $this->authorize('manageQualification', [$teacher, Subject::withTrashed()->findOrFail($qualification->subject_id)]);

        if ($this->attempt(fn () => $removeQualification->handle($qualification), 'No se pudo quitar la materia')) {
            $this->success('Materia quitada del perfil', 'Queda en la bitácora.');
        }
    }

    public function createLoad(TeacherQuery $teachers, Actor $context): void
    {
        $this->authorize('manageExternalLoads', $teachers->findOrFail($this->teacherId));
        $this->activeTermOrFail($context);

        $this->resetValidation();
        $this->reset('editingLoadId', 'loadSource', 'loadHours', 'loadNotes');
        $this->showLoadModal = true;
    }

    public function editLoad(string $id, TeacherQuery $teachers, TeacherRecordQuery $records, Actor $context): void
    {
        $teacher = $teachers->findOrFail($this->teacherId);
        $this->authorize('manageExternalLoads', $teacher);
        $load = $this->findLoadInActiveTerm($id, $teacher, $records, $context);

        $this->resetValidation();
        $this->editingLoadId = $load->id;
        $this->loadSource = $load->source;
        $this->loadHours = (string) $load->hours;
        $this->loadNotes = (string) $load->notes;
        $this->showLoadModal = true;
    }

    public function saveLoad(TeacherQuery $teachers, TeacherRecordQuery $records, AddExternalLoad $addExternalLoad, UpdateExternalLoad $updateExternalLoad, Actor $context): void
    {
        $teacher = $teachers->findOrFail($this->teacherId);
        $this->authorize('manageExternalLoads', $teacher);
        $term = $this->activeTermOrFail($context);

        $data = $this->validate([
            'loadSource' => ['required', 'string', 'max:255'],
            'loadHours' => ['required', 'integer', 'between:1,40'],
            'loadNotes' => ['nullable', 'string', 'max:500'],
        ]);
        $data = ['source' => $data['loadSource'], 'hours' => $data['loadHours'], 'notes' => $data['loadNotes']];

        try {
            if ($this->editingLoadId === null) {
                $addExternalLoad->handle($teacher, $term, $data);
                $this->success('Horas agregadas', e("Se restan de lo que se le puede asignar en {$term->name}."));
            } else {
                $updateExternalLoad->handle($this->findLoadInActiveTerm($this->editingLoadId, $teacher, $records, $context), $data);
                $this->success('Horas actualizadas', 'Queda en la bitácora.');
            }
        } catch (ValidationException $exception) {
            $this->addError('loadSource', (string) ($exception->errors()['source'][0] ?? $exception->errors()['hours'][0] ?? collect($exception->errors())->flatten()->first()));

            return;
        }

        $this->showLoadModal = false;
    }

    public function removeLoad(string $id, TeacherQuery $teachers, TeacherRecordQuery $records, RemoveExternalLoad $removeExternalLoad, Actor $context): void
    {
        $teacher = $teachers->findOrFail($this->teacherId);
        $this->authorize('manageExternalLoads', $teacher);
        $load = $this->findLoadInActiveTerm($id, $teacher, $records, $context);

        if ($this->attempt(fn () => $removeExternalLoad->handle($load), 'No se pudo quitar las horas')) {
            $this->success('Horas quitadas', 'Dejan de restarse de lo que se le puede asignar. Queda en la bitácora.');
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
            'institutional_email' => 'correo institucional',
            'degree' => 'grado',
            'hired_on' => 'fecha de ingreso',
            'kind' => 'tipo de plaza',
            'base_hours' => 'horas base',
            'valid_from' => 'inicio de la vigencia',
            'valid_to' => 'fin de la vigencia',
            'qualSubjectId' => 'materia',
            'qualLevel' => 'nivel',
            'qualNotes' => 'notas',
            'loadSource' => 'origen',
            'loadHours' => 'horas',
            'loadNotes' => 'notas',
            'headKind' => 'cargo',
            'headDepartmentId' => 'departamento',
            'headModalityId' => 'modalidad',
            'headCampusId' => 'campus',
            'headFrom' => 'inicio',
            'headTo' => 'fin',
            'endLastDay' => 'último día',
        ];
    }

    /**
     * Lo que pinta la pestaña Perfil: sus materias con quién puede cambiarlas, las horas fuera del ITCJ del periodo
     * activo y, con el modal abierto, las materias que el usuario puede declarar (S17: las de los departamentos que
     * cubre).
     *
     * @return array<string, mixed>
     */
    private function profileData(Teacher $teacher, TeacherRecordQuery $records, TeacherQuery $teachers, Actor $context, ?Term $term): array
    {
        $profile = $records->profile($teacher);
        $loads = $term === null ? collect() : $records->externalLoads($teacher, $term);

        return [
            'profile' => $profile,
            'canQualify' => $this->qualifiable($profile, $teacher, $context),
            'loads' => $loads,
            'canManageLoads' => Gate::allows('manageExternalLoads', $teacher),
            'subjectOptions' => $this->showQualificationModal && $this->editingQualificationId === null
                ? $records->qualifiableSubjects($teachers->coveredDepartments($context, FacultyPermission::TeacherQualificationManage), $this->qualSearch)
                : collect(),
        ];
    }

    /**
     * Por materia del perfil, si el usuario puede cambiarla: cubre al docente (una vez) y el departamento de la
     * materia, la misma regla de TeacherPolicy::manageQualification sin preguntarle a la Gate por cada renglón.
     *
     * @param  iterable<int, TeacherSubjectQualification>  $profile
     * @return array<string, bool>
     */
    private function qualifiable(iterable $profile, Teacher $teacher, Actor $context): array
    {
        $coversTeacher = $context->allows(FacultyPermission::TeacherViewAll)
            || $context->allows(FacultyPermission::TeacherQualificationManage, Target::of(unit: $teacher->adscriptions->pluck('department_id')->unique()->values()->all()));
        $allowed = [];

        foreach ($profile as $qualification) {
            $allowed[$qualification->id] = $coversTeacher
                && $context->allows(FacultyPermission::TeacherQualificationManage, Target::of(unit: $qualification->subject?->department_id));
        }

        return $allowed;
    }

    private function activeTermOrFail(Actor $context): Term
    {
        return $context->activeTerm() ?? abort(403, 'Elige un periodo en la credencial.');
    }

    /**
     * Unas horas fuera del ITCJ del docente en el periodo activo; las de otro periodo no se tocan desde aquí.
     */
    private function findLoadInActiveTerm(string $id, Teacher $teacher, TeacherRecordQuery $records, Actor $context): TeacherExternalLoad
    {
        $load = $records->findExternalLoad($teacher, $id);
        abort_unless($load->term_id === $this->activeTermOrFail($context)->id, 404);

        return $load;
    }

    /**
     * Por qué no puede cambiar el correo institucional (AC-06): de un docente con otro puesto, solo el admin; si no, solo
     * el jefe de su departamento y el admin.
     */
    private function institutionalEmailReason(Teacher $teacher): string
    {
        return Gate::allows('inviteAccess', $teacher)
            ? 'Tiene otro puesto: su correo institucional solo lo cambia el administrador.'
            : 'Solo su jefe de departamento o el administrador lo capturan.';
    }

    /**
     * Por qué «Reenviar invitación» está deshabilitado para un usuario sin contraseña, o '' si se puede (AC-07).
     */
    private function inviteReason(TeacherRecord $record, bool $allowed): string
    {
        return match (true) {
            ! $allowed => 'Solo su jefe de departamento o el administrador la reenvían.',
            ! $record->user->is_active => 'Su usuario está inactivo.',
            $record->access !== UserAccess::Invited => InviteTeacherAccess::NOT_INVITED,
            $record->teacher->institutional_email === null => 'Falta su correo institucional.',
            default => '',
        };
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
     * El aviso del modal de «Quitar de <depto>»: qué principal le queda, con la regla de RemoveAdscription (la más
     * antigua de las que quedan; las adscripciones llegan en ese orden detrás de la principal).
     */
    private function removalWarning(TeacherRecord $record, TeacherDepartment $adscription): string
    {
        $rest = $record->adscriptions->reject(fn (TeacherDepartment $other): bool => $other->is($adscription))->values();
        $outcome = match (true) {
            $rest->isEmpty() => 'Se queda sin departamento, pero sigue activo.',
            $adscription->is_primary => 'Sigue activo y su adscripción principal pasa a '.$rest->first()->department?->code.'.',
            default => 'Sigue activo y su adscripción principal no cambia.',
        };

        return "¿Quitar al docente {$record->teacher->rfc} de {$adscription->department?->code}? {$outcome} Queda en la bitácora.";
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
