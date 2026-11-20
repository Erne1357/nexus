<?php

use App\Modules\Academic\Models\Subject;
use App\Modules\Core\Actions\AppointRole;
use App\Modules\Core\Actions\EndRoleAssignment;
use App\Modules\Core\Actions\SendUserPasswordReset;
use App\Modules\Core\Authorization\Actor;
use App\Modules\Core\Authorization\Target;
use App\Modules\Core\Enums\AssignmentEnding;
use App\Modules\Core\Enums\CorePermission;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Enums\UserAccess;
use App\Modules\Core\Livewire\Concerns\ReturnsToValidation;
use App\Modules\Core\Models\RoleAssignment;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Queries\AssignmentLabels;
use App\Modules\Core\Queries\KeyLookup;
use App\Modules\Core\Queries\PeopleQuery;
use App\Modules\Core\Support\PasswordResetTokens;
use App\Modules\Faculty\Actions\AddExternalLoad;
use App\Modules\Faculty\Actions\AddPosition;
use App\Modules\Faculty\Actions\DischargeTeacherFromDepartment;
use App\Modules\Faculty\Actions\InviteTeacherAccess;
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
use App\Modules\Faculty\Livewire\Concerns\EditsInstitutionalEmail;
use App\Modules\Faculty\Models\Teacher;
use App\Modules\Faculty\Models\TeacherDepartment;
use App\Modules\Faculty\Models\TeacherExternalLoad;
use App\Modules\Faculty\Models\TeacherSubjectQualification;
use App\Modules\Faculty\Queries\AvailabilityGridQuery;
use App\Modules\Faculty\Queries\TeacherAccessQuery;
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
 * editar y quitar), Perfil (las materias que puede dar, apto o preferente, y su «Carga académica en otra modalidad» del periodo
 * activo; S16 y S17, supuestos por confirmar) e Historial (grupos por periodo y evaluaciones, de solo lectura).
 * Disponibilidad muestra la del periodo activo, de solo lectura (AvailabilityGridQuery), y lleva al editor
 * (faculty::pages.teacher-availability). «Acciones» trae darlo de baja o reactivarlo y, según su acceso, «Enviar
 * invitación» o «Reenviar invitación» si su usuario no tiene contraseña o «Restablecer contraseña» por enlace si la
 * tiene (CF-08, solo del admin). La invitación del admin (`core.user.invite`, CF-07) sale aunque tenga otro puesto o
 * su departamento no haya validado; la del jefe de su departamento sigue AC-07. Ninguna usa la contraseña por defecto.
 * Si el admin dio de baja su cuenta en el panel (CF-09), no se ofrece ninguna: «Se reactiva desde el panel», con el
 * enlace a su ficha de «Personas y puestos» para quien la ve. Datos muestra el correo institucional (AC-06) y el estado
 * del acceso (AC-04), o «Cuenta dada de baja por el admin».
 *
 * Lo ven y cambian el admin, la subdirección y los jefes, cada quien los docentes de su alcance (TeacherPolicy). El
 * componente lee con TeacherQuery y TeacherRecordQuery, autoriza en mount(), en with() y en cada método (una
 * petición puede llegar con una página que abrió otra sesión), valida la forma de los datos y llama a las Actions,
 * que hacen cumplir las reglas; un error de regla de una acción de confirmación sale en un toast.
 */
new #[Title('Ficha del docente')] class extends Component
{
    use EditsInstitutionalEmail, ReturnsToValidation, Toast;

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
     * Una modalidad sin salones suelta el campus de la jefatura: ahí el jefe de proyecto docente va sin campus (MS4).
     */
    public function updatedHeadModalityId(PeopleQuery $people): void
    {
        if (! $people->usesCampuses($this->headModalityId)) {
            $this->headCampusId = '';
        }
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
    public function with(TeacherQuery $teachers, TeacherRecordQuery $records, Actor $context, PeopleQuery $people, AvailabilityGridQuery $grids, TeacherAccessQuery $access, PasswordResetTokens $tokens): array
    {
        $teacher = $teachers->findOrFail($this->teacherId);
        $this->authorize('view', $teacher);

        $record = $records->for($teacher, $context->activeTerm());
        $adscriptionCount = $record->adscriptions->count();

        // «Quitar de <depto>» es de quien cubre ese departamento (y nadie a sí mismo); a quien ocupa un puesto solo lo
        // quitan el admin y la Subdirección (CV-S8). El motivo de lo deshabilitado se escribe junto al botón.
        $removal = [];
        $primaryReasons = [];
        $isSelf = $record->teacher->user_id !== null && $record->teacher->user_id === $context->user()?->getKey();

        foreach ($record->adscriptions as $adscription) {
            $removal[$adscription->id] = Gate::allows('removeAdscription', [Teacher::class, $adscription])
                ? null
                : match (true) {
                    ! $context->allows(FacultyPermission::TeacherDepartmentRemove, Target::of(unit: $adscription->department_id)) => 'Solo quien administra ese departamento lo quita.',
                    $isSelf => 'No te puedes quitar a ti mismo de un departamento.',
                    $adscriptionCount === 1 && ! $context->allows(FacultyPermission::TeacherDepartmentRemoveLast, Target::of(unit: $adscription->department_id)) => 'Es su último departamento: lo quita el jefe de departamento.',
                    default => 'Ocupa un puesto: lo quitan el administrador o la Subdirección.',
                };
            $primaryReasons[$adscription->id] = Gate::allows('setPrimaryAdscription', [Teacher::class, $adscription])
                ? null
                : 'Solo quien administra ese departamento la hace principal.';
        }

        $tabData = match ($this->tab) {
            'perfil' => $this->profileData($teacher, $records, $teachers, $context, $record->term),
            'historial' => ['history' => $records->history($teacher)],
            'disponibilidad' => [
                'availability' => $record->term === null ? null : $grids->for($teacher, $record->term, $grids->profile()),
                'availabilityReason' => Gate::allows('manageAvailability', $teacher) ? null : 'Solo quien administra al docente edita su disponibilidad.',
            ],
            default => [],
        };

        // Ver las jefaturas es ver nombramientos (el admin y, de solo lectura, el directivo, AZ-03); nombrarlas y terminarlas,
        // solo con su ability.
        $canSeeHeadships = Gate::allows('viewAny', RoleAssignment::class);
        $canAppoint = Gate::allows('create', RoleAssignment::class);
        $canEndHeadships = Gate::allows('end', RoleAssignment::class);
        $headOptions = $canAppoint && $this->showHeadshipModal ? $people->scopeOptions(Role::JefeProyectoDocente, null, $this->headModalityId) : ['modalities' => [], 'campuses' => [], 'units' => []];

        $removing = $this->removingAdscriptionId === null ? null : $record->adscriptions->firstWhere('id', $this->removingAdscriptionId);

        return [
            'subjectOptions' => collect(),
            ...$tabData,
            'canAppoint' => $canAppoint,
            'canSeeHeadships' => $canSeeHeadships,
            'canEndHeadships' => $canEndHeadships,
            'headships' => $canSeeHeadships && $this->tab === 'datos' ? $records->headships($teacher) : collect(),
            'endingHeadship' => $canEndHeadships && $this->endingHeadshipId !== null ? $records->findHeadship($teacher, $this->endingHeadshipId) : null,
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
            ...$this->accessActions($teacher, $record, $access),
            'canOpenPerson' => $canSeeHeadships,
            'resetMinutes' => $tokens->minutes(),
            'institutionalDomain' => config('nexus.institutional_email_domain'),
            'institutionalEmailReason' => $this->showDataModal ? $this->institutionalEmailReason($teacher) : null,
            'departures' => $this->tab === 'datos' && $context->allows(FacultyPermission::TeacherDeactivate) ? $records->departures($teacher) : collect(),
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
        $institutionalEmail = $this->institutionalEmailChange($teacher);

        if ($institutionalEmail === null) {
            return;
        }

        $teacher = $updateTeacher->handle($teacher, [
            'name' => $data['name'],
            'email' => (string) $data['email'],
            'degree' => (string) $data['degree'],
            'hired_on' => (string) $data['hired_on'],
            ...$institutionalEmail,
        ]);

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
     * «Restablecer contraseña» (CF-08): el enlace de AC-16 al correo de su usuario, como en «Personas y puestos»; solo
     * el admin (`core.user.reset_password`). El resultado, o por qué no se pudo, en un toast.
     */
    public function resetPassword(TeacherQuery $teachers, SendUserPasswordReset $sendUserPasswordReset, PasswordResetTokens $tokens, Actor $context): void
    {
        $teacher = $teachers->findOrFail($this->teacherId);
        $user = $teacher->user;

        if ($user === null) {
            abort_unless($context->allows(CorePermission::UserResetPassword), 403);
            $this->error('No se mandó el enlace', 'El docente no tiene usuario.');

            return;
        }

        $this->authorize('resetPassword', $user);

        try {
            $email = $sendUserPasswordReset->handle($user, $context->user());
        } catch (ValidationException $exception) {
            $this->error('No se mandó el enlace', e((string) collect($exception->errors())->flatten()->first()));

            return;
        } catch (Throwable $exception) {
            report($exception);
            $this->error('No se mandó el enlace', 'El correo no salió. Inténtalo otra vez en unos minutos.');

            return;
        }

        $this->success('Enlace enviado', e("Enlace enviado a {$email}. Vale {$tokens->minutes()} minutos."));
    }

    /**
     * «Enviar invitación» o «Reenviar invitación» a su correo institucional; el enlace anterior deja de servir. El admin
     * manda la suya (InviteTeacherAccess::byAdmin(), CF-07), aunque tenga otro puesto o su departamento no haya validado.
     * El jefe de su departamento reenvía la de AC-07, y a quien nunca se invitó se la manda en cuanto un departamento suyo
     * validó su catálogo de docentes; antes, la primera sale con esa validación. Un rechazo, en un toast.
     */
    public function resendInvitation(TeacherQuery $teachers, InviteTeacherAccess $inviteTeacherAccess, Actor $context): void
    {
        $teacher = $teachers->findOrFail($this->teacherId);
        $user = $teacher->user;
        $invited = $user?->access_invited_at !== null;
        $failure = $invited ? 'No se pudo reenviar la invitación' : 'No se pudo enviar la invitación';
        $email = $teacher->institutional_email;

        if ($user !== null && Gate::allows('invite', $user)) {
            $sent = $this->attempt(function () use ($inviteTeacherAccess, $teacher, $context, &$email): void {
                $email = $inviteTeacherAccess->byAdmin($teacher, $context->user());
            }, $failure);
        } else {
            $this->authorize('inviteAccess', $teacher);
            $sent = $this->attempt(fn () => $inviteTeacherAccess->resend($teacher, $context->user()), $failure);
        }

        if ($sent) {
            $this->success(
                $invited ? 'Invitación reenviada' : 'Invitación enviada',
                e("{$teacher->rfc}: a {$email}.".($invited ? ' El enlace anterior ya no sirve.' : '')),
            );
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
        $campus = $role === Role::JefeProyectoDocente ? $people->campus($data['headCampusId']) : null;

        if (! $teacher->is_active) {
            $this->addError('headKind', 'El docente está inactivo: reactívalo antes de nombrarlo.');

            return;
        }

        // Solo el campo vacío es «Todos los campus» (AZ-S17): un id que no es de un campus es un error, no un jefe de
        // proyecto docente de todos los campus.
        if ($role === Role::JefeProyectoDocente && filled($data['headCampusId'] ?? null) && $campus === null) {
            $this->addError('headCampusId', 'Elige el campus.');

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
                $campus,
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
        $this->authorize('end', RoleAssignment::class);

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

    /**
     * «Quitar de <depto>»: con la última adscripción, el docente queda dado de baja y su cuenta se inhabilita
     * (DischargeTeacherFromDepartment, CV8, confirmado el 12 de noviembre de 2026).
     */
    public function removeAdscription(TeacherQuery $teachers, TeacherRecordQuery $records, DischargeTeacherFromDepartment $discharge): void
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

        if (! $this->attempt(function () use ($discharge, $adscription, &$removal): void {
            $removal = $discharge->handle($adscription);
        }, 'No se pudo quitar de ese departamento')) {
            return;
        }

        $outcome = match (true) {
            $removal['deactivated'] => '; queda dado de baja y su cuenta se inhabilita',
            $removal['outcome'] === null => '',
            default => "; {$removal['outcome']}",
        };

        // Quien quitó la última adscripción de su alcance ya no cubre al docente: vuelve a la lista en lugar de un 403.
        $stillVisible = Gate::allows('view', $teachers->findOrFail($this->teacherId));

        $this->success(e("Quitado de {$adscription->department?->name}"), e($teacher->rfc.$outcome), redirectTo: $stillVisible ? null : route('faculty.teachers'));
    }

    public function setPrimary(string $id, TeacherQuery $teachers, TeacherRecordQuery $records, SetPrimaryAdscription $setPrimaryAdscription): void
    {
        $teacher = $teachers->findOrFail($this->teacherId);
        $this->authorize('update', $teacher);
        $adscription = $records->findAdscription($teacher, $id);
        $this->authorize('setPrimaryAdscription', [Teacher::class, $adscription]);

        if ($this->attempt(fn () => $setPrimaryAdscription->handle($adscription), 'No se pudo cambiar la principal')) {
            $this->success('Asignación principal cambiada', 'Queda en la bitácora.');
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
     * Lo que «Acciones» ofrece sobre el acceso de su usuario: `resetReason` (CF-08) si tiene contraseña e `inviteReason`
     * (CF-07, AC-07) si no, cada uno '' si se puede, el motivo si no y null si no se ofrece; `invited`, si ya tiene una
     * invitación, y `panelDeactivated`, si el admin dio de baja su cuenta en el panel (CF-09), y entonces no se ofrece
     * ninguno.
     *
     * @return array{resetReason: ?string, inviteReason: ?string, invited: bool, panelDeactivated: bool}
     */
    private function accessActions(Teacher $teacher, TeacherRecord $record, TeacherAccessQuery $access): array
    {
        $user = $record->user;
        $panelDeactivated = $user?->deactivated_at !== null;
        $invited = $record->access === UserAccess::Invited;
        $hasPassword = $record->access?->hasPassword() === true;

        return [
            'resetReason' => $panelDeactivated || ! $hasPassword ? null : (Gate::allows('resetPassword', $user)
                ? $access->resetBlocker($teacher) ?? ''
                : 'Solo el administrador restablece contraseñas.'),
            'inviteReason' => $panelDeactivated || $hasPassword ? null : $this->inviteReason($teacher, $access, $invited),
            'invited' => $invited,
            'panelDeactivated' => $panelDeactivated,
        ];
    }

    /**
     * Por qué «Reenviar invitación» (o «Enviar invitación») está deshabilitado para un usuario sin contraseña, o '' si se
     * puede: para el admin, TeacherAccessQuery::adminInvitationBlocker() (CF-07); para los demás, primero quién la manda
     * y luego TeacherAccessQuery::invitationBlocker() (AC-07).
     */
    private function inviteReason(Teacher $teacher, TeacherAccessQuery $access, bool $invited): string
    {
        if ($teacher->user_id !== null && Gate::allows('invite', $teacher->user)) {
            return $access->adminInvitationBlocker($teacher) ?? '';
        }

        return Gate::allows('inviteAccess', $teacher)
            ? $access->invitationBlocker($teacher) ?? ''
            : 'Solo su jefe de departamento o el administrador la '.($invited ? 'reenvían.' : 'mandan.');
    }

    /**
     * Corre una acción de confirmación; si una regla la rechaza o la acción falla con un RuntimeException, lo dice en un
     * toast de error con el motivo y da false.
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
     * El aviso del modal de «Quitar de <Departamento>» (D10): solo sale de ese departamento y qué principal le queda, con
     * la regla de RemoveAdscription (la más antigua de las que quedan; las adscripciones llegan en ese orden detrás de la
     * principal), o que sin otra queda dado de baja (CV8), con TeacherQuery::removalConsequence().
     */
    private function removalWarning(TeacherRecord $record, TeacherDepartment $adscription): string
    {
        $consequence = app(TeacherQuery::class)->removalConsequence($adscription, $record->adscriptions);

        return "¿Quitar al docente {$record->teacher->rfc} de {$adscription->department?->name}? {$consequence} Queda en la bitácora.";
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
