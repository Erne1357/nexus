<?php

use App\Modules\Academic\Enums\SubjectKind;
use App\Modules\Academic\Models\Department;
use App\Modules\CatalogValidation\Actions\AddReviewTeacher;
use App\Modules\CatalogValidation\Actions\CorrectRecord;
use App\Modules\CatalogValidation\Actions\DischargeReviewTeacher;
use App\Modules\CatalogValidation\Actions\FlagRecord;
use App\Modules\CatalogValidation\Actions\MarkRecordsReviewed;
use App\Modules\CatalogValidation\Actions\ReopenReview;
use App\Modules\CatalogValidation\Actions\ResolveFlag;
use App\Modules\CatalogValidation\Actions\UnmarkRecordReviewed;
use App\Modules\CatalogValidation\Actions\ValidateReview;
use App\Modules\CatalogValidation\Enums\Catalog;
use App\Modules\CatalogValidation\Enums\CatalogValidationPermission;
use App\Modules\CatalogValidation\Enums\ReviewFlag;
use App\Modules\CatalogValidation\Enums\ReviewStatus;
use App\Modules\CatalogValidation\Models\CatalogReview;
use App\Modules\CatalogValidation\Queries\CatalogContentsQuery;
use App\Modules\CatalogValidation\Queries\CatalogRecordsQuery;
use App\Modules\CatalogValidation\Queries\CatalogReviewQuery;
use App\Modules\CatalogValidation\Rules\CatalogReviewRules;
use App\Modules\Core\Authorization\Actor;
use App\Modules\Core\Authorization\Target;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\User;
use App\Modules\Facilities\Enums\RoomType;
use App\Modules\Facilities\Queries\BuildingQuery;
use App\Modules\Facilities\Rules\RoomRules;
use App\Modules\Faculty\Enums\FacultyPermission;
use App\Modules\Faculty\Models\Teacher;
use App\Modules\Faculty\Queries\TeacherAccessQuery;
use App\Modules\Faculty\Queries\TeacherQuery;
use App\Modules\Faculty\Queries\TeacherTermReadinessQuery;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Mary\Traits\Toast;

/**
 * Validación de catálogos del departamento (docs/dominio/reglas-de-negocio.md, "Validación de catálogos"),
 * variante C «tablero de tarjetas» (DESIGN.md). Toma el periodo activo de la credencial y, de la URL,
 * el departamento (`?department=`) y el catálogo abierto (`?catalogo=`).
 *
 * Tablero: el avance (HC-14) y una tarjeta por revisión del periodo, con «N de M revisados», su barra y Validar o
 * Reabrir. Lista de un catálogo (opción A «Tabla con columna Revisión», elegida por el usuario el 12 de noviembre de
 * 2026): búsqueda, el filtro segmentado Por revisar · Revisados · Con observación · Todos (`?revision=`), la tabla con
 * casillas y la columna «Revisión», y un drawer por registro para corregirlo (CV1, CV2) y ver, poner y resolver
 * observaciones (CV4). Las revisiones no son de un campus: la de salones cubre los de todos los campus (decisión 6), y
 * el jefe mueve un salón a un edificio de otro campus (CV-S10). En docentes, el jefe captura el correo institucional en
 * un recuadro junto al nombre (CV1, AC-06), da de baja a uno de su departamento (CV8, CV-S8) y agrega a uno que ya
 * existe, buscándolo por RFC o nombre (CV-S9).
 * Cada registro se marca como revisado desde su fila, o varios a la vez (la selección vive en el navegador y llega a
 * markSelected()), y Validar solo se activa con todos revisados (CV7); la tarjeta, la pestaña y el renglón de la lista
 * dicen cuántos van.
 *
 * El jefe cubre su departamento; Subdirección y el admin abren cualquiera en lectura, y solo resuelven
 * observaciones (CV-S1). Toda escritura pasa por su Action, después de autorizar con CatalogReviewPolicy en cada
 * método público, porque la petición puede llegar con una pantalla que abrió otra sesión. Los errores de las
 * Actions (vetos, revisión validada) salen en la pantalla.
 */
new #[Title('Validación de catálogos')] class extends Component
{
    use Toast, WithPagination;

    /** Por qué un docente dado de baja que ocupa un puesto no se agrega desde aquí (CV-S9). */
    private const string STAFF_REACTIVATION = 'Está dado de baja y ocupa un puesto: lo reactivan el administrador o la Subdirección.';

    /** Por qué el correo institucional de quien ocupa otro puesto no se cambia desde aquí (AC-06). */
    private const string STAFF_EMAIL = 'El correo institucional de un docente que ocupa otro puesto solo lo cambia el administrador.';

    /** Cuántos registros trae cada página de la lista. */
    private const int PER_PAGE = 50;

    /** El departamento abierto; vacío es el primero que cubre el jefe. Se valida en cada petición. */
    #[Url]
    public string $department = '';

    /** El catálogo de la lista abierta (`teachers`, `rooms`, `subjects`, `study_plans`, `careers`); vacío es el tablero. */
    #[Url(as: 'catalogo', history: true)]
    public string $catalog = '';

    /** Solo docentes: '' (todos), 'disponibilidad', 'perfil' (los activos a los que les falta, CV6) o 'correo' (sin correo institucional, AC-06). */
    #[Url(as: 'falta')]
    public string $missing = '';

    public string $search = '';

    /** Solo con foto anterior: '' (todos) o 'added' (las altas); 'removed' no se queda, abre el drawer de bajas. */
    public string $change = '';

    public bool $showRemoved = false;

    /**
     * El filtro segmentado de la lista (CV7): 'por-revisar' (activos sin revisar), 'revisados', 'con-observacion' (con
     * observación abierta) o 'todos'. Al abrir un catálogo, «Por revisar» si queda alguno y la revisión no está validada;
     * si no, «Todos» (segmentOrDefault()).
     */
    #[Url(as: 'revision')]
    public string $segment = '';

    /** Solo salones: el campus y el edificio que deja la lista (ids; vacío, todos). */
    public string $roomCampus = '';

    public string $roomBuilding = '';

    public bool $showConfirm = false;

    /** 'validate' o 'reopen'; solo lo pone confirm(). */
    #[Locked]
    public string $confirmAction = '';

    #[Locked]
    public string $confirmCatalog = '';

    public bool $showDrawer = false;

    /** El registro del drawer; null si no hay. El navegador no lo puede cambiar. */
    #[Locked]
    public ?string $editingId = null;

    public string $name = '';

    public string $theory_hours = '';

    public string $practice_hours = '';

    public string $credits = '';

    public string $kind = '';

    public bool $needs_lab = false;

    public string $version = '';

    public string $semesters_count = '';

    public string $total_credits = '';

    public string $valid_from = '';

    public string $type = '';

    public string $capacity = '';

    public string $features = '';

    /** El edificio de un salón; uno de otro campus lo mueve a ese campus (CV-S10). */
    public string $building_id = '';

    /** El correo institucional de un docente (AC-06). */
    public string $institutional_email = '';

    /** El drawer «Agregar docente» y lo que se busca en él, RFC o nombre (CV-S9). */
    public bool $showAddTeacher = false;

    public string $teacherSearch = '';

    public bool $is_active = true;

    /** El tipo de la observación nueva. */
    public string $flag = '';

    public string $comment = '';

    /** Lo resuelto en esta petición: departamentos, selección, periodo y revisiones. */
    private ?array $resolved = null;

    public function mount(): void
    {
        $state = $this->state();

        if ($this->department === '' && $state['department'] !== null) {
            $this->department = $state['department']->getKey();
        }

        $this->segment = $this->segmentOrDefault($this->segment);
    }

    /**
     * @return array<string, mixed>
     */
    public function with(CatalogReviewQuery $reviews, CatalogRecordsQuery $records, CatalogContentsQuery $contents, TeacherAccessQuery $access): array
    {
        $state = $this->state();
        $data = ['departments' => $state['departments'], 'department' => $state['department'], 'term' => $state['term']];

        $empty = match (true) {
            $state['department'] === null => 'department',
            $state['term'] === null => 'term',
            $state['reviews']->isEmpty() => 'reviews',
            default => null,
        };

        if ($empty !== null) {
            return [...$data, 'empty' => $empty, 'firstTerm' => false, 'cards' => collect(), 'tabs' => [], 'progress' => null, 'current' => null, 'confirming' => null, 'missingInvitations' => ['email' => 0, 'user' => 0]];
        }

        $reviewed = $contents->progressOf($state['reviews']);

        $open = $this->openCatalog();
        $openReview = $open === null ? null : $state['reviews']->first(fn (CatalogReview $review): bool => $review->catalog === $open);

        /*
        | El tablero arma las cinco tarjetas y el avance (HC-14); la lista, solo la revisión abierta y sin preguntar
        | por el veto de Reabrir (confirm() lo vuelve a preguntar al pedirlo).
        */
        $current = $openReview === null ? null : $this->card($openReview, $reviews, false, $reviewed[$openReview->getKey()]);
        $cards = $current === null ? $this->byWork($state['reviews']->map(fn (CatalogReview $review): array => $this->card($review, $reviews, true, $reviewed[$review->getKey()]))) : collect([$current]);
        $firstTerm = $current === null
            ? $cards->every(fn (array $card): bool => $card['summary']['added'] === null)
            : $state['reviews']->every(fn (CatalogReview $review): bool => $reviews->previousValidation($review) === null);
        $data += ['empty' => null, 'firstTerm' => $firstTerm, 'cards' => $cards, 'tabs' => $state['reviews']->map(fn (CatalogReview $review): array => ['catalog' => $review->catalog, 'status' => $review->status, 'reviewed' => $reviewed[$review->getKey()]])->all(), 'progress' => $current === null ? $this->progress($state, $reviewed) : null, 'current' => $current];

        $data += $this->confirmation($cards, $access);

        return [...$data, ...($current === null ? ['page' => null] : $this->list($current, $records, $contents))];
    }

    public function updated(string $property): void
    {
        if ($property === 'department') {
            $this->resolved = null;
        }

        if (in_array($property, ['search', 'segment', 'missing', 'change', 'roomCampus', 'roomBuilding', 'department', 'catalog'], true)) {
            $this->resetPage();
        }

        /*
        | Las bajas ya no están en el catálogo: «Bajas» no filtra la tabla, abre el drawer con la lista de la foto.
        */
        if ($property === 'change') {
            $this->showRemoved = $this->change === 'removed';
            $this->change = $this->change === 'added' ? 'added' : '';
        }

        /*
        | «Falta» deja docentes activos con o sin marca: al elegirlo, el filtro segmentado pasa a «Todos».
        */
        if ($property === 'missing' && $this->missing !== '') {
            $this->segment = 'todos';
        }

        if ($property === 'segment') {
            $this->segment = $this->segmentOrDefault($this->segment);
        }

        if ($property === 'roomCampus') {
            $this->roomBuilding = '';
        }

        if (in_array($property, ['department', 'catalog'], true)) {
            $this->reset('search', 'segment', 'missing', 'change', 'roomCampus', 'roomBuilding', 'showDrawer', 'showRemoved', 'showAddTeacher', 'teacherSearch', 'editingId');
            $this->segment = $this->segmentOrDefault('');
        }
    }

    /**
     * «Quitar filtros» de una lista sin resultados: todo, y el filtro segmentado en «Todos».
     */
    public function clearFilters(): void
    {
        $this->reset('search', 'missing', 'change', 'roomCampus', 'roomBuilding');
        $this->segment = 'todos';
        $this->resetPage();
    }

    /**
     * Pide confirmar Validar o Reabrir una revisión (el modal del tablero y de la lista).
     */
    public function confirm(string $action, string $catalog): void
    {
        $review = $this->reviewOf($catalog);
        $this->resetValidation();

        if ($action === 'reopen') {
            $this->authorize('reopen', $review);
            $vetoes = app(CatalogReviewQuery::class)->reopeningVetoes($review);

            if ($vetoes !== []) {
                throw ValidationException::withMessages(['status' => $vetoes]);
            }
        } else {
            abort_unless($action === 'validate', 404);
            $this->authorize('validate', $review);
            app(CatalogReviewRules::class)->ensureAllReviewed($review);
        }

        $this->confirmAction = $action;
        $this->confirmCatalog = $catalog;
        $this->showConfirm = true;
    }

    public function confirmed(ValidateReview $validateReview, ReopenReview $reopenReview): void
    {
        $review = $this->reviewOf($this->confirmCatalog);
        $action = $this->confirmAction;
        $this->showConfirm = false;
        $this->reset('confirmAction', 'confirmCatalog');

        if ($action === 'reopen') {
            $this->authorize('reopen', $review);
            $reopenReview->handle($review, $this->user());
            $this->success('Revisión reabierta', e($review->title));
        } else {
            $this->authorize('validate', $review);
            $validateReview->handle($review, $this->user());
            $this->success('Revisión validada', e($review->title));
        }
    }

    public function cancelConfirm(): void
    {
        $this->showConfirm = false;
        $this->reset('confirmAction', 'confirmCatalog');
    }

    /**
     * Abre el drawer de un registro: sus datos, sus observaciones y, si la policy lo deja, el formulario.
     */
    public function edit(string $id, CatalogContentsQuery $contents): void
    {
        $review = $this->currentReview();
        $this->authorize('view', $review);
        $record = $contents->findOrFail($review, $id);

        $this->resetValidation();
        $this->reset('flag', 'comment');
        $this->editingId = $record->getKey();
        $this->fill($this->formOf($review->catalog, $record));
        $this->showDrawer = true;
    }

    public function closeDrawer(): void
    {
        $this->showDrawer = false;
        $this->editingId = null;
    }

    public function save(CorrectRecord $correctRecord, CatalogContentsQuery $contents): void
    {
        $review = $this->currentReview();
        $this->authorize('correct', $review);
        $record = $contents->findOrFail($review, (string) $this->editingId);

        if ($review->catalog === Catalog::Teachers) {
            $this->authorize('updateInstitutionalEmail', $record);
        }

        $record = $correctRecord->handle($review, $record, $this->validatedRecord($review->catalog), $this->user());

        $this->success(match ($review->catalog) {
            Catalog::Teachers => 'Docente actualizado',
            Catalog::Rooms => 'Salón actualizado',
            Catalog::StudyPlans => 'Plan actualizado',
            Catalog::Careers => 'Carrera actualizada',
            default => 'Materia actualizada',
        }, $this->describe($review, $record));
        $this->closeDrawer();
    }

    public function toggleActive(string $id, CorrectRecord $correctRecord, CatalogContentsQuery $contents): void
    {
        $review = $this->currentReview();
        $this->authorize('correct', $review);
        abort_unless(in_array($review->catalog, [Catalog::Subjects, Catalog::Rooms], true), 403, 'La actividad de carreras y planes no se cambia aquí.');
        $record = $contents->findOrFail($review, $id);

        try {
            $record = $correctRecord->handle($review, $record, ['is_active' => ! $record->is_active], $this->user());
        } catch (ValidationException $exception) {
            $this->error('No se pudo cambiar '.e($this->describe($review, $record)), e(implode(' ', Arr::flatten($exception->errors()))));

            return;
        }

        $this->success($record->is_active ? 'Registro activado' : 'Registro desactivado', $this->describe($review, $record));
    }

    /**
     * «Dar de baja de <depto>»: el docente sale del departamento y, sin otra adscripción, queda dado de baja (CV8). A
     * uno que ocupa un puesto solo lo dan de baja el admin o la Subdirección (CV-S8).
     */
    public function dischargeTeacher(string $id, DischargeReviewTeacher $dischargeTeacher, CatalogContentsQuery $contents, CatalogRecordsQuery $records): void
    {
        $review = $this->currentReview();
        $this->authorize('correct', $review);
        abort_unless($review->catalog === Catalog::Teachers, 404);
        $teacher = $contents->findOrFail($review, $id);
        $this->authorize('removeAdscription', [Teacher::class, $records->teacherAdscriptions($review, [$teacher->getKey()])[$teacher->getKey()]]);

        $result = $dischargeTeacher->handle($review, $teacher, $this->user());

        $this->success(
            $result['deactivated'] ? 'Docente dado de baja' : "Docente quitado de {$review->department->code}",
            $this->describe($review, $teacher).e($result['outcome'] === null ? '' : " · {$result['outcome']}"),
        );
    }

    /**
     * «Agregar docente» (CV-S9): uno que ya existe, activo o dado de baja (se reactiva), que el jefe encontró en el
     * drawer por su RFC o su nombre, al departamento; queda revisado (CV7). A uno dado de baja que ocupa un puesto solo lo
     * reactivan el admin o la Subdirección: el drawer lo muestra deshabilitado con su motivo y aquí se rechaza.
     */
    public function addTeacher(string $id, AddReviewTeacher $addTeacher, TeacherQuery $teachers): void
    {
        $review = $this->currentReview();
        $this->authorize('correct', $review);
        abort_unless($review->catalog === Catalog::Teachers, 404);
        $this->authorize('adscribeToDepartment', [Teacher::class, $review->department]);

        $teacher = $teachers->findOrFail($id);

        if (Gate::denies('adscribeToDepartment', [Teacher::class, $review->department, $teacher])) {
            throw ValidationException::withMessages(['candidate' => self::STAFF_REACTIVATION]);
        }

        try {
            $addTeacher->handle($review, $teacher, $this->user());
        } catch (ValidationException $exception) {
            throw ValidationException::withMessages(['candidate' => Arr::flatten($exception->errors())]);
        }

        $this->reset('showAddTeacher', 'teacherSearch');
        $this->success(e("Agregaste a {$teacher->name}"), e("{$teacher->rfc} · queda revisado en {$review->department->code}."));
    }

    /**
     * El correo institucional de un docente desde el recuadro de su fila (CV1, AC-06): `$value` es el usuario (se le
     * agrega el dominio institucional) o el correo completo. Lo guarda CorrectRecord, que lo marca como revisado (CV7).
     * Devuelve si se guardó y, si no, el motivo, para que el recuadro lo diga sin cerrarse.
     *
     * @return array{saved: bool, error: ?string}
     */
    public function saveEmail(string $id, string $value, CorrectRecord $correctRecord, CatalogContentsQuery $contents): array
    {
        $review = $this->currentReview();
        $this->authorize('correct', $review);
        abort_unless($review->catalog === Catalog::Teachers, 404);
        $teacher = $contents->findOrFail($review, $id);
        $this->authorize('updateInstitutionalEmail', $teacher);

        $value = mb_strtolower(trim($value));

        if ($value === '') {
            return ['saved' => false, 'error' => 'Escribe el usuario de su correo institucional.'];
        }

        $email = str_contains($value, '@') ? $value : $value.'@'.config('nexus.institutional_email_domain');

        try {
            $teacher = $correctRecord->handle($review, $teacher, ['institutional_email' => $email], $this->user());
        } catch (ValidationException $exception) {
            return ['saved' => false, 'error' => (string) Arr::first(Arr::flatten($exception->errors()))];
        }

        $this->success('Correo institucional guardado', e("{$teacher->institutional_email} · {$teacher->name} queda revisado."));

        return ['saved' => true, 'error' => null];
    }

    /**
     * Marca (`$reviewed`) o desmarca un registro como revisado desde su fila (CV7). El estado que se quiere viene de la
     * pantalla, no de leer la marca aquí: así un envío repetido o una pantalla vieja no la invierten (las dos Actions no
     * hacen nada si ya está como se pide).
     */
    public function setReviewed(string $id, bool $reviewed, MarkRecordsReviewed $markRecords, UnmarkRecordReviewed $unmarkRecord, CatalogContentsQuery $contents): void
    {
        $review = $this->currentReview();
        $this->authorize('mark', $review);
        $record = $contents->findOrFail($review, $id);

        if ($reviewed) {
            $markRecords->handle($review, [$record->getKey()], $this->user());
        } else {
            $unmarkRecord->handle($review, $record, $this->user());
        }
    }

    /**
     * «Marcar N como revisados»: los registros elegidos en el navegador, juntos (CV7). Si uno no es de la revisión, no
     * marca ninguno.
     *
     * @param  array<int, mixed>  $ids
     */
    public function markSelected(array $ids, MarkRecordsReviewed $markRecords): void
    {
        $review = $this->currentReview();
        $this->authorize('mark', $review);

        $data = Validator::make(
            ['selected' => $ids],
            ['selected' => ['required', 'array', 'max:500'], 'selected.*' => ['string']],
            ['selected.required' => 'Elige los registros que ya revisaste.', 'selected.max' => 'Marca hasta 500 a la vez.'],
        )->validate();

        $count = $markRecords->handle($review, array_values(array_unique($data['selected'])), $this->user());

        $this->success($count === 1 ? '1 registro revisado' : "{$count} registros revisados", e($review->title));
    }

    public function addFlag(FlagRecord $flagRecord, CatalogContentsQuery $contents): void
    {
        $review = $this->currentReview();
        $this->authorize('flag', $review);
        $record = $contents->findOrFail($review, (string) $this->editingId);

        $data = $this->validate([
            'flag' => ['required', Rule::enum(ReviewFlag::class)],
            'comment' => ['nullable', 'string', 'max:1000'],
        ]);

        $flagRecord->handle($review, $record, ReviewFlag::from($data['flag']), $data['comment'] ?? null, $this->user());

        $this->reset('flag', 'comment');
        $this->success('Observación agregada', $this->describe($review, $record));
    }

    public function resolveFlag(string $id, ResolveFlag $resolveFlag, CatalogRecordsQuery $records): void
    {
        $review = $this->currentReview();
        $this->authorize('resolveFlag', $review);

        $resolveFlag->handle($review, $records->findFlag($review, $id), $this->user());

        $this->success('Observación resuelta', e($review->title));
    }

    /**
     * @return array<string, string>
     */
    protected function validationAttributes(): array
    {
        return [
            'name' => 'nombre',
            'theory_hours' => 'horas de teoría',
            'practice_hours' => 'horas de práctica',
            'credits' => 'créditos',
            'kind' => 'tipo',
            'version' => 'versión',
            'semesters_count' => 'semestres',
            'total_credits' => 'créditos del plan',
            'valid_from' => 'vigente desde',
            'type' => 'tipo',
            'capacity' => 'capacidad',
            'features' => 'características',
            'flag' => 'tipo de observación',
            'comment' => 'comentario',
            'building_id' => 'edificio',
            'institutional_email' => 'correo institucional',
        ];
    }

    /**
     * Lo resuelto de la petición, memorizado: los departamentos que el usuario abre, el seleccionado (403 si
     * `?department=` no es de los suyos o no hay ninguno), el periodo y el campus activos y las revisiones.
     *
     * @return array{departments: Collection<int, Department>, department: ?Department, term: ?Term, reviews: Collection<int, CatalogReview>}
     */
    private function state(): array
    {
        if ($this->resolved !== null) {
            return $this->resolved;
        }

        $context = app(Actor::class);
        $departments = app(CatalogReviewQuery::class)->departmentsFor($context);
        abort_if($departments->isEmpty(), 403);

        if ($this->department !== '') {
            $department = $departments->first(fn (Department $candidate): bool => $candidate->getKey() === $this->department);
            abort_if($department === null, 403);
        } else {
            $department = $departments->first(fn (Department $candidate): bool => CatalogReviewQuery::allowsOnDepartment($context, CatalogValidationPermission::ReviewFlag, null, $candidate->getKey()));
        }

        $term = $context->activeTerm();
        $reviews = $department === null || $term === null
            ? new Collection
            : app(CatalogReviewQuery::class)->forDepartment($term, $department)->load(['department', 'validator'])
                ->filter(fn (CatalogReview $review): bool => Gate::allows('view', $review))
                ->values()
                ->each(fn (CatalogReview $review) => $review->setRelation('term', $term));

        return $this->resolved = compact('departments', 'department', 'term', 'reviews');
    }

    private function user(): User
    {
        return app(Actor::class)->user() ?? abort(403);
    }

    private function openCatalog(): ?Catalog
    {
        return Catalog::tryFrom($this->catalog);
    }

    /**
     * La revisión del catálogo dado en el departamento, periodo y campus de la pantalla (404 si no existe).
     */
    private function reviewOf(string $catalog): CatalogReview
    {
        return $this->state()['reviews']->first(fn (CatalogReview $review): bool => $review->catalog->value === $catalog) ?? abort(404);
    }

    private function currentReview(): CatalogReview
    {
        return $this->reviewOf($this->openCatalog()?->value ?? '');
    }

    /**
     * Lo que muestra la tarjeta de una revisión y lo que el usuario puede hacer con ella.
     *
     * @param  array{reviewed: int, total: int}  $reviewed  cuántos registros van revisados (CV7)
     * @return array<string, mixed>
     */
    private function card(CatalogReview $review, CatalogReviewQuery $reviews, bool $withVetoes, array $reviewed): array
    {
        $validated = $review->status === ReviewStatus::Validated;
        $isHead = Gate::allows('flag', $review);
        $canReopen = Gate::allows('reopen', $review);
        $vetoes = $withVetoes && $validated && $canReopen ? $reviews->reopeningVetoes($review) : [];
        $isTeachers = $review->catalog === Catalog::Teachers;

        $editReason = match (true) {
            ! $isHead || ! Gate::allows('correct', $review) => 'Solo el jefe del departamento corrige.',
            $validated => 'La revisión ya está validada: reábrela para corregir.',
            default => null,
        };

        $canMark = Gate::allows('mark', $review);

        return [
            'review' => $review,
            'summary' => $reviews->summary($review),
            'reviewed' => $reviewed,
            'canMark' => ! $validated && $canMark,
            'markReason' => match (true) {
                ! $canMark => 'Solo el jefe del departamento marca lo revisado.',
                $validated => 'La revisión ya está validada: reábrela para marcar.',
                default => null,
            },
            'validateReason' => CatalogReviewRules::unreviewedMessage($reviewed['total'] - $reviewed['reviewed']),
            'readiness' => $isTeachers && $withVetoes ? [
                ...app(TeacherTermReadinessQuery::class)->counts($review->department, $review->term),
                'no_email' => count(app(CatalogRecordsQuery::class)->teachersWithoutEmail($review)),
            ] : null,
            'canValidate' => Gate::allows('validate', $review),
            'canReopen' => $canReopen,
            'reopenVetoes' => $vetoes,
            'canResolve' => Gate::allows('resolveFlag', $review),
            'canEdit' => ! $validated && Gate::allows('correct', $review),
            'canFlag' => ! $validated && $isHead,
            'canToggle' => ! $validated && in_array($review->catalog, [Catalog::Subjects, Catalog::Rooms], true) && Gate::allows('correct', $review),
            'toggleReason' => match ($review->catalog) {
                Catalog::Careers, Catalog::StudyPlans => 'Carreras y planes no se activan ni se desactivan aquí: pon la observación «Debe desactivarse».',
                Catalog::Teachers => "Un docente se da de baja con «Dar de baja de {$review->department?->code}».",
                default => $editReason,
            },
            'editReason' => $editReason,
            'flagReason' => match (true) {
                ! $isHead => 'Solo el jefe del departamento pone observaciones.',
                $validated => 'La revisión ya está validada: reábrela para observar.',
                default => null,
            },
        ];
    }

    /**
     * «N de M validados» (HC-14, CV9): los catálogos que el admin abrió en el periodo (los que tienen revisiones en él) y
     * que se exigen al departamento (los salones, si algún campus donde el periodo está abierto usa salones; una revisión
     * de salones para todos los campus, decisión 6), y los que todavía bloquean la salida de borrador de sus cargas. Uno
     * que no se abrió no cuenta ni se menciona; uno abierto cuya revisión le falta al departamento cuenta como pendiente.
     * `items` trae cada catálogo contado con su revisión, si existe y el usuario la ve.
     *
     * @param  array{term: ?Term, department: ?Department, reviews: Collection<int, CatalogReview>}  $state
     * @param  array<string, array{reviewed: int, total: int}>  $reviewed  cuántos registros van revisados en cada revisión (CV7)
     * @return array{validated: int, total: int, left: int, items: list<array{catalog: Catalog, review: ?CatalogReview, reviewed: ?array{reviewed: int, total: int}}>}
     */
    private function progress(array $state, array $reviewed): array
    {
        $catalogReviews = app(CatalogReviewQuery::class);
        $opened = $catalogReviews->openedCatalogs($state['term']);
        $required = array_values(array_filter(Catalog::requiredFor($catalogReviews->roomsApply($state['term'])), fn (Catalog $catalog): bool => in_array($catalog, $opened, true)));
        $existing = $state['reviews']->keyBy(fn (CatalogReview $review): string => $review->catalog->value);
        $pending = array_filter($required, fn (Catalog $catalog): bool => $existing->get($catalog->value)?->status !== ReviewStatus::Validated);

        return [
            'validated' => count($required) - count($pending),
            'total' => count($required),
            'left' => count($pending),
            'items' => array_map(function (Catalog $catalog) use ($existing, $reviewed): array {
                $review = $existing->get($catalog->value);

                return ['catalog' => $catalog, 'review' => $review, 'reviewed' => $review === null ? null : ($reviewed[$review->getKey()] ?? null)];
            }, $required),
        ];
    }

    /**
     * La revisión que espera confirmación, con lo que valida, para el modal. Al validar la de docentes, cuántos docentes
     * activos sin acceso no recibirán su invitación, por falta de correo institucional o de usuario activo (AC-09).
     *
     * @param  Collection<int, array<string, mixed>>  $cards
     * @return array<string, mixed>
     */
    private function confirmation(Collection $cards, TeacherAccessQuery $access): array
    {
        $card = $this->confirmAction === '' ? null : $cards->first(fn (array $card): bool => $card['review']->catalog->value === $this->confirmCatalog);
        $missing = $card !== null && $this->confirmAction === 'validate' && $card['review']->catalog === Catalog::Teachers
            ? $access->missingInvitations($card['review']->department_id)
            : ['email' => 0, 'user' => 0];

        return ['confirming' => $card, 'missingInvitations' => $missing];
    }

    /**
     * La tabla de un catálogo y lo que el drawer abierto necesita: la página con el filtro segmentado y sus cifras, quién
     * y cuándo marcó cada registro, los pendientes que «Elegir los N por revisar» elige, lo de cada docente (sus otros
     * departamentos, la baja y el correo) y, con «Agregar docente» abierto, los docentes que coinciden.
     *
     * @param  array<string, mixed>  $current
     * @return array<string, mixed>
     */
    private function list(array $current, CatalogRecordsQuery $records, CatalogContentsQuery $contents): array
    {
        /** @var CatalogReview $review */
        $review = $current['review'];
        $isRooms = $review->catalog === Catalog::Rooms;
        $onlyIds = $this->onlyIds($review, $current['summary']);
        [$campusId, $buildingId] = $isRooms ? [$this->roomCampus, $this->roomBuilding] : ['', ''];
        $query = fn (): LengthAwarePaginator => $records->page($review, $this->search, null, $this->segment === 'con-observacion' ? 'with' : '', self::PER_PAGE, $onlyIds, match ($this->segment) {
            'por-revisar' => 'pending',
            'revisados' => 'reviewed',
            default => '',
        }, $campusId, $buildingId);
        $page = $query();

        /*
        | Marcar los de la última página de «Por revisar» la deja vacía aunque queden pendientes en las anteriores: se pasa
        | a la que ahora es la última.
        */
        if ($page->isEmpty() && $page->currentPage() > 1) {
            $this->setPage($page->lastPage());
            $page = $query();
        }

        $ids = $page->getCollection()->map->getKey()->all();

        $readiness = $review->catalog === Catalog::Teachers
            ? app(TeacherTermReadinessQuery::class)->forTeachers($review->department, $review->term, $ids)
            : [];
        $editing = null;

        if ($this->showDrawer && $this->editingId !== null) {
            try {
                $record = $contents->findOrFail($review, $this->editingId);
                $editing = ['record' => $record, 'flags' => $records->flagsOf($review, $record), 'locked' => $this->lockedOf($review, $record), 'facts' => $this->factsOf($review->catalog, $record), 'mark' => $this->describeMarks($records->marksOf($review, [$record->getKey()]))[$record->getKey()] ?? null, ...$this->editableOf($current, $record)];
            } catch (ModelNotFoundException) {
                $this->closeDrawer();
            }
        }

        $segmentCounts = $records->segmentCounts($review);
        $buildingGroups = $isRooms && ($editing['canEdit'] ?? false) ? app(BuildingQuery::class)->groupedByActiveCampus() : [];
        $marks = $this->describeMarks($records->marksOf($review, $ids));
        $onlySegment = $this->search === '' && $this->missing === '' && $this->change === '' && $this->roomCampus === '' && $this->roomBuilding === '';

        return [
            'page' => $page,
            'readiness' => $readiness,
            'teacherRows' => $review->catalog === Catalog::Teachers ? $this->teacherRows($current, $records, $page->getCollection()) : [],
            'buildingGroups' => $buildingGroups,
            'buildingCampus' => $this->campusOfBuilding($buildingGroups, $this->building_id),
            'marks' => $marks,
            'pagePending' => $page->getCollection()->filter(fn (Model $record): bool => $record->is_active && ! isset($marks[$record->getKey()]))->count(),
            'allReviewed' => $this->segment === 'por-revisar' && $segmentCounts['pending'] === 0 && $onlySegment,
            'doneMessage' => $this->doneMessage($review->catalog, $current['reviewed']['total']),
            'pendingIds' => $current['canMark'] ? $records->pendingIds($review, $this->search, $onlyIds, $campusId, $buildingId) : [],
            'segments' => [
                ['id' => 'por-revisar', 'label' => 'Por revisar', 'count' => $segmentCounts['pending']],
                ['id' => 'revisados', 'label' => 'Revisados', 'count' => $segmentCounts['reviewed']],
                ['id' => 'con-observacion', 'label' => 'Con observación', 'count' => $segmentCounts['flagged']],
                ['id' => 'todos', 'label' => 'Todos', 'count' => $segmentCounts['all']],
            ],
            'roomFilters' => $isRooms ? $records->roomFilterOptions($review) : ['campuses' => [], 'buildings' => []],
            'missingFilters' => [['id' => 'disponibilidad', 'name' => 'Sin disponibilidad'], ['id' => 'perfil', 'name' => 'Sin perfil'], ['id' => 'correo', 'name' => 'Sin correo institucional']],
            'flagMap' => $records->openFlags($review, $ids),
            'editing' => $editing,
            'candidates' => $this->showAddTeacher && $review->catalog === Catalog::Teachers ? $this->candidates($review, $records) : null,
            'emailDomain' => (string) config('nexus.institutional_email_domain'),
            'removed' => $current['summary']['removed'] ?? [],
            'changeFilters' => [['id' => 'added', 'name' => 'Altas'], ['id' => 'removed', 'name' => 'Bajas']],
            'hasFilters' => $this->search !== '' || ! in_array($this->segment, ['', 'todos'], true) || $this->missing !== '' || $this->change !== '' || $this->roomCampus !== '' || $this->roomBuilding !== '',
            'flagOptions' => collect(ReviewFlag::cases())->filter(fn (ReviewFlag $flag): bool => $flag->appliesTo($review->catalog))->map(fn (ReviewFlag $flag): array => ['id' => $flag->value, 'name' => $flag->label()])->values()->all(),
            'kinds' => array_map(fn (SubjectKind $kind): array => ['id' => $kind->value, 'name' => $kind->label()], SubjectKind::cases()),
            'roomTypes' => array_map(fn (RoomType $type): array => ['id' => $type->value, 'name' => $type->label()], RoomType::cases()),
        ];
    }

    /**
     * El campus del edificio elegido en el drawer de un salón (el campus sigue al edificio, CV-S10), de los grupos de
     * BuildingQuery::groupedByActiveCampus(); null si no está entre ellos.
     *
     * @param  list<array{id: string, code: string, name: string, buildings: list<array{id: string, name: string}>}>  $groups
     * @return ?array{id: string, code: string, name: string}
     */
    private function campusOfBuilding(array $groups, string $buildingId): ?array
    {
        foreach ($groups as $group) {
            if (in_array($buildingId, array_column($group['buildings'], 'id'), true)) {
                return ['id' => $group['id'], 'code' => $group['code'], 'name' => $group['name']];
            }
        }

        return null;
    }

    /**
     * Lo que dice «Por revisar» cuando ya no queda ninguno: «Revisaste los 40 docentes.», «Revisaste las 3 carreras.»
     * (opción C, tomada para la A por el usuario el 12 de noviembre de 2026).
     */
    private function doneMessage(Catalog $catalog, int $total): string
    {
        if ($total === 0) {
            return 'No hay registros activos que revisar.';
        }

        [$one, $many] = match ($catalog) {
            Catalog::Teachers => ['el único docente', "los {$total} docentes"],
            Catalog::Rooms => ['el único salón', "los {$total} salones"],
            Catalog::Subjects => ['la única materia', "las {$total} materias"],
            Catalog::StudyPlans => ['el único plan de estudio', "los {$total} planes de estudio"],
            Catalog::Careers => ['la única carrera', "las {$total} carreras"],
        };

        return 'Revisaste '.($total === 1 ? $one : $many).'.';
    }

    /**
     * Quién y cuándo marcó cada registro, como lo dice la columna «Revisión» («Laura · 10 nov., 12:40») y su menú («Lo
     * marcó Laura Ramos el 10 nov. 2026, 12:40.»), en la zona de la aplicación.
     *
     * @param  array<string, array{by: ?string, at: CarbonImmutable}>  $marks
     * @return array<string, array{short: string, long: string}>
     */
    private function describeMarks(array $marks): array
    {
        return array_map(function (array $mark): array {
            $at = $mark['at']->timezone(config('app.timezone'));
            $by = $mark['by'] ?? 'un usuario ya dado de baja';

            return [
                'short' => ($mark['by'] === null ? 'Usuario dado de baja' : Str::before($mark['by'], ' ')).' · '.$at->translatedFormat('j M, H:i'),
                'long' => "Lo marcó {$by} el {$at->translatedFormat('j M Y, H:i')}.",
            ];
        }, $marks);
    }

    /**
     * El filtro segmentado que se pide si es uno de los cuatro; si no, el de entrada del catálogo abierto: «Todos» con
     * el filtro «Falta» puesto (el tablero enlaza a esa lista), «Por revisar» si a la revisión abierta le queda algún
     * activo sin revisar (CV7) y «Todos» si no; sin catálogo abierto, ninguno.
     */
    private function segmentOrDefault(string $segment): string
    {
        if (in_array($segment, ['por-revisar', 'revisados', 'con-observacion', 'todos'], true)) {
            return $segment;
        }

        $review = $this->openCatalog() === null ? null : $this->state()['reviews']->first(fn (CatalogReview $candidate): bool => $candidate->catalog === $this->openCatalog());

        return match (true) {
            $review === null => '',
            $this->missing !== '' => 'todos',
            $review->status !== ReviewStatus::Validated && app(CatalogContentsQuery::class)->unreviewedCount($review) > 0 => 'por-revisar',
            default => 'todos',
        };
    }

    /**
     * Lo de cada docente de la página (CV8, CV-S8, AC-06): sus otros departamentos («También en», «Principal en»), por
     * qué no se da de baja o null, la confirmación de la baja según le quede o no otro departamento, y por qué no se
     * captura su correo institucional o null.
     *
     * @param  array<string, mixed>  $current
     * @param  Collection<int, Model>  $teachers
     * @return array<string, array{departments: array{here_primary: bool, others: list<array{code: string, name: string, is_primary: bool}>}, dischargeReason: ?string, emailReason: ?string, confirmTitle: string, confirmMessage: string}>
     */
    private function teacherRows(array $current, CatalogRecordsQuery $records, Collection $teachers): array
    {
        $ids = $teachers->map->getKey()->all();
        $departments = $records->teacherDepartments($current['review'], $ids);
        $staff = $records->staffUserIds(array_values(array_filter($teachers->pluck('user_id')->all())));
        $dischargeReasons = $this->dischargeReasons($current, $records, $ids, $staff, $departments);
        $context = app(Actor::class);
        $unit = Target::of(unit: $current['review']->department_id);
        $canEmail = $context->allows(FacultyPermission::TeacherUpdate, $unit) && $context->allows(FacultyPermission::TeacherUserInvite, $unit);
        $canStaffEmail = $context->allows(FacultyPermission::TeacherStaffEmailUpdate);
        $departmentName = (string) $current['review']->department?->name;

        return $teachers->mapWithKeys(function (Model $teacher) use ($current, $departments, $staff, $dischargeReasons, $canEmail, $canStaffEmail, $departmentName): array {
            $own = $departments[$teacher->getKey()];
            $other = $own['others'][0] ?? null;

            /*
            | Al darlo de baja (CV8): sin otro departamento, queda inactivo y su cuenta se desactiva; con otro, sigue en él y,
            | si este era su principal, la principal pasa al más antiguo de los que le quedan (RemoveAdscription).
            */
            [$title, $message] = match (true) {
                $other === null => ["¿Dar de baja a {$teacher->name}?", 'No tiene otro departamento: su registro queda inactivo y su cuenta se desactiva. Puedes volver a agregarlo con «Agregar docente».'],
                $own['here_primary'] => ["¿Dar de baja a {$teacher->name} de {$departmentName}?", "Sigue como docente de {$other['name']}, que pasa a ser su adscripción principal. Deja de aparecer en tu departamento y conserva su cuenta."],
                default => ["¿Dar de baja a {$teacher->name} de {$departmentName}?", "Sigue como docente de {$other['name']}, su adscripción principal. Solo deja de aparecer en tu departamento."],
            };

            return [$teacher->getKey() => [
                'departments' => $own,
                'dischargeReason' => $dischargeReasons[$teacher->getKey()],
                'emailReason' => match (true) {
                    ! $current['canEdit'] => $current['editReason'],
                    ! $canEmail => 'Solo quien administra el departamento captura el correo institucional.',
                    $teacher->user_id !== null && isset($staff[$teacher->user_id]) && ! $canStaffEmail => self::STAFF_EMAIL,
                    default => null,
                },
                'confirmTitle' => $title,
                'confirmMessage' => $message,
            ]];
        })->all();
    }

    /**
     * Los docentes que «Agregar docente» ofrece (CV-S9) y qué pasa con cada uno: `add` (activo; se agrega), `reactivate`
     * (dado de baja; se reactiva y se agrega), `here` (ya está en el departamento) o `blocked` (dado de baja y ocupa un
     * puesto: lo reactivan el admin o la Subdirección), con el texto que lo explica.
     *
     * @return array{search: string, tooShort: bool, more: bool, items: list<array{id: string, rfc: string, name: string, active: bool, state: string, note: string, reason: ?string}>}
     */
    private function candidates(CatalogReview $review, CatalogRecordsQuery $records): array
    {
        $found = $records->teacherCandidates($this->teacherSearch);
        $staff = $records->staffUserIds(array_values(array_filter($found['teachers']->where('is_active', false)->pluck('user_id')->all())));
        $canReactivateStaff = app(Actor::class)->allows(FacultyPermission::TeacherDeactivate);
        $department = $review->department;

        $items = $found['teachers']->map(function (Teacher $teacher) use ($department, $staff, $canReactivateStaff): array {
            $adscriptions = $teacher->adscriptions;
            $here = $adscriptions->contains('department_id', $department->getKey());
            $primary = $adscriptions->first(fn ($adscription): bool => $adscription->is_primary) ?? $adscriptions->first();
            $primaryName = $primary?->department?->name;

            [$state, $note] = match (true) {
                $here => ['here', "Ya está en {$department->name}."],
                ! $teacher->is_active && $teacher->user_id !== null && isset($staff[$teacher->user_id]) && ! $canReactivateStaff => ['blocked', $primaryName === null ? 'Ocupa un puesto.' : "Ocupa un puesto. Su principal es {$primaryName}."],
                ! $teacher->is_active => ['reactivate', $primaryName === null ? "Se reactiva y queda con {$department->name} como principal." : "Se reactiva y se agrega a {$department->name}; su principal sigue siendo {$primaryName}."],
                $primaryName === null => ['add', "Sin departamento. Queda con {$department->name} como principal."],
                default => ['add', "Principal en {$primaryName}. Se agrega a {$department->name} como adscripción adicional; su principal no cambia."],
            };

            return ['id' => $teacher->getKey(), 'rfc' => $teacher->rfc, 'name' => $teacher->name, 'active' => (bool) $teacher->is_active, 'state' => $state, 'note' => $note, 'reason' => $state === 'blocked' ? self::STAFF_REACTIVATION : null];
        })->all();

        return ['search' => trim($this->teacherSearch), 'tooShort' => mb_strlen(trim($this->teacherSearch)) < 2, 'more' => $found['more'], 'items' => $items];
    }

    /**
     * Si el registro del drawer se corrige y, si no, por qué: lo de la tarjeta y, en docentes, quién cambia el correo
     * institucional (AC-06: el de quien ocupa otro puesto solo lo cambia el admin).
     *
     * @param  array<string, mixed>  $current
     * @return array{canEdit: bool, editReason: ?string}
     */
    private function editableOf(array $current, Model $record): array
    {
        if ($current['canEdit'] && $record instanceof Teacher && ! Gate::allows('updateInstitutionalEmail', $record)) {
            return ['canEdit' => false, 'editReason' => self::STAFF_EMAIL];
        }

        return ['canEdit' => $current['canEdit'], 'editReason' => $current['editReason']];
    }

    /**
     * Por qué no se da de baja cada docente de la página, o null si se puede (CV8, CV-S8).
     *
     * @param  array<string, mixed>  $current
     * @param  list<string>  $ids
     * @param  array<string, true>  $staff  los usuarios de la página que ocupan un puesto
     * @param  array<string, array{here_primary: bool, others: list<array{code: string, name: string, is_primary: bool}>}>  $departments  los otros departamentos de cada docente
     * @return array<string, ?string>
     */
    private function dischargeReasons(array $current, CatalogRecordsQuery $records, array $ids, array $staff, array $departments): array
    {
        /*
        | La regla de TeacherPolicy::removeAdscription para toda la página, con dos consultas y no una por docente:
        | dischargeTeacher() vuelve a preguntar a la policy al pedirlo. La última adscripción solo la quita quien tiene
        | `remove_last` en el departamento (CV8: el jefe de proyecto docente no).
        */
        $adscriptions = $records->teacherAdscriptions($current['review'], $ids);
        $context = app(Actor::class);
        $unit = Target::of(unit: $current['review']->department_id);
        $canRemove = $context->allows(FacultyPermission::TeacherDepartmentRemove, $unit);
        $canRemoveLast = $context->allows(FacultyPermission::TeacherDepartmentRemoveLast, $unit);
        $canDeactivate = $context->allows(FacultyPermission::TeacherDeactivate);
        $me = $context->user()?->getKey();

        return collect($ids)->mapWithKeys(function (string $id) use ($current, $adscriptions, $staff, $departments, $canRemove, $canRemoveLast, $canDeactivate, $me): array {
            $userId = $adscriptions[$id]->teacher?->user_id ?? null;

            return [$id => match (true) {
                ! $current['canEdit'] => $current['editReason'],
                ! isset($adscriptions[$id]) || ! $canRemove => 'Solo quien administra el departamento da de baja a sus docentes.',
                $userId !== null && $userId === $me => 'No te puedes dar de baja a ti mismo.',
                $userId !== null && isset($staff[$userId]) && ! $canDeactivate => 'Ocupa un puesto: lo da de baja el administrador o la Subdirección.',
                ! $canRemoveLast && ($departments[$id]['others'] ?? []) === [] => 'Es su único departamento: lo dan de baja el jefe de departamento, la Subdirección o el administrador.',
                default => null,
            }];
        })->all();
    }

    /**
     * Los registros que deja el filtro «Falta» de docentes y el de «Cambio: Altas», juntos; null si no hay ninguno.
     *
     * @param  array<string, mixed>  $summary
     * @return ?list<string>
     */
    private function onlyIds(CatalogReview $review, array $summary): ?array
    {
        $missing = $this->missingIds($review);
        $added = $this->change === 'added' && $summary['added'] !== null ? array_column($summary['added'], 'id') : null;

        return match (true) {
            $missing !== null && $added !== null => array_values(array_intersect($missing, $added)),
            default => $missing ?? $added,
        };
    }

    /**
     * Las tarjetas con trabajo primero: pendientes, en revisión y validadas, cada grupo en el orden del enum.
     *
     * @param  Collection<int, array<string, mixed>>  $cards
     * @return Collection<int, array<string, mixed>>
     */
    private function byWork(Collection $cards): Collection
    {
        $rank = [ReviewStatus::Pending->value => 0, ReviewStatus::InReview->value => 1, ReviewStatus::Validated->value => 2];

        return $cards->sortBy(fn (array $card): int => $rank[$card['review']->status->value])->values();
    }

    /**
     * Los docentes activos que deja el filtro «Falta»; null si no hay filtro o el catálogo no es el de docentes.
     *
     * @return ?list<string>
     */
    private function missingIds(CatalogReview $review): ?array
    {
        if ($review->catalog !== Catalog::Teachers || ! in_array($this->missing, ['disponibilidad', 'perfil', 'correo'], true)) {
            return null;
        }

        return $this->missing === 'correo'
            ? app(CatalogRecordsQuery::class)->teachersWithoutEmail($review)
            : app(TeacherTermReadinessQuery::class)->missingIds($review->department, $review->term, $this->missing);
    }

    /**
     * Los datos que el jefe no corrige, como texto (sin permiso de corregir el drawer los muestra así, no como campos
     * deshabilitados): el nombre ya va en el encabezado del drawer.
     *
     * @return list<array{label: string, value: ?string, mono: bool}>
     */
    private function factsOf(Catalog $catalog, Model $record): array
    {
        $fact = fn (string $label, mixed $value, bool $mono = false): array => ['label' => $label, 'value' => $value === null || $value === '' ? null : (string) $value, 'mono' => $mono];

        return match ($catalog) {
            Catalog::Subjects => [
                $fact('Teoría', $record->theory_hours, true), $fact('Práctica', $record->practice_hours, true), $fact('Créditos', $record->credits, true),
                $fact('Tipo', $record->kind?->label()), $fact('Requiere laboratorio', $record->needs_lab ? 'Sí' : 'No'),
            ],
            Catalog::StudyPlans => [
                $fact('Versión', $record->version), $fact('Vigente desde', $record->valid_from?->translatedFormat('j M Y')),
                $fact('Semestres', $record->semesters_count, true), $fact('Créditos', $record->total_credits, true),
            ],
            Catalog::Rooms => [
                $fact('Campus', $record->campus?->code), $fact('Edificio', $record->building === null ? null : "{$record->building->code} · {$record->building->name}"),
                $fact('Tipo', $record->type->label()), $fact('Capacidad', $record->capacity, true),
                $fact('Características', implode(', ', $record->features ?? [])),
            ],
            default => [],
        };
    }

    /**
     * Lo que ata el registro al SII o a su departamento y el drawer muestra deshabilitado (CV-S3).
     *
     * @return list<array{label: string, value: ?string, mono?: bool}>
     */
    private function lockedOf(CatalogReview $review, Model $record): array
    {
        return match ($review->catalog) {
            Catalog::Subjects, Catalog::Careers => [
                ['label' => 'Clave', 'value' => $record->code, 'mono' => true],
                ['label' => 'Departamento', 'value' => $review->department?->name],
            ],
            Catalog::StudyPlans => [
                ['label' => 'Clave', 'value' => $record->code, 'mono' => true],
                ['label' => 'Carrera', 'value' => $record->career?->name],
            ],
            Catalog::Rooms => [
                ['label' => 'Clave', 'value' => $record->code, 'mono' => true],
                ['label' => 'Departamento', 'value' => $review->department?->name],
            ],
            Catalog::Teachers => [['label' => 'RFC', 'value' => $record->rfc, 'mono' => true]],
        };
    }

    /**
     * Los campos del formulario del drawer con los valores del registro.
     *
     * @return array<string, mixed>
     */
    private function formOf(Catalog $catalog, Model $record): array
    {
        $common = ['name' => (string) $record->name, 'is_active' => (bool) $record->is_active];

        return $common + match ($catalog) {
            Catalog::Subjects => [
                'theory_hours' => (string) $record->theory_hours,
                'practice_hours' => (string) $record->practice_hours,
                'credits' => (string) $record->credits,
                'kind' => $record->kind?->value ?? '',
                'needs_lab' => (bool) $record->needs_lab,
            ],
            Catalog::StudyPlans => [
                'version' => (string) $record->version,
                'semesters_count' => (string) $record->semesters_count,
                'total_credits' => (string) $record->total_credits,
                'valid_from' => $record->valid_from?->toDateString() ?? '',
            ],
            Catalog::Rooms => [
                'building_id' => (string) $record->building_id,
                'type' => $record->type->value,
                'capacity' => (string) $record->capacity,
                'features' => implode(', ', $record->features ?? []),
            ],
            Catalog::Teachers => ['institutional_email' => (string) $record->institutional_email],
            default => [],
        };
    }

    /**
     * Los datos que acepta la Action del módulo dueño, validados en forma y con sus tipos; lo que ata el registro
     * (clave, departamento, carrera) no se manda, y CorrectRecord lo quitaría de todos modos. El campus de un salón sigue
     * a su edificio (CV-S10); de un docente solo va el correo institucional.
     *
     * @return array<string, mixed>
     */
    private function validatedRecord(Catalog $catalog): array
    {
        $common = ['is_active' => ['boolean']];

        return match ($catalog) {
            Catalog::Subjects => $this->subjectData($common),
            Catalog::Careers => $this->validate(['name' => ['required', 'string', 'max:255']]),
            Catalog::StudyPlans => $this->planData(),
            Catalog::Rooms => $this->roomData($common),
            Catalog::Teachers => ['institutional_email' => $this->validate(['institutional_email' => ['nullable', 'string', 'max:255']])['institutional_email'] ?? null],
        };
    }

    /**
     * @param  array<string, list<string>>  $common
     * @return array<string, mixed>
     */
    private function subjectData(array $common): array
    {
        $data = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'theory_hours' => ['required', 'integer', 'min:0', 'max:32767'],
            'practice_hours' => ['required', 'integer', 'min:0', 'max:32767'],
            'credits' => ['required', 'integer', 'min:0', 'max:32767'],
            'kind' => ['nullable', Rule::enum(SubjectKind::class)],
            'needs_lab' => ['boolean'],
        ] + $common);

        return [
            ...$data,
            'theory_hours' => (int) $data['theory_hours'],
            'practice_hours' => (int) $data['practice_hours'],
            'credits' => (int) $data['credits'],
            'kind' => (string) $data['kind'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function planData(): array
    {
        $data = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'version' => ['required', 'string', 'max:255'],
            'semesters_count' => ['nullable', 'integer', 'min:1', 'max:32767'],
            'total_credits' => ['nullable', 'integer', 'min:0', 'max:32767'],
            'valid_from' => ['nullable', 'date_format:Y-m-d'],
        ]);

        return [
            ...$data,
            'semesters_count' => ($data['semesters_count'] ?? '') === '' ? null : (int) $data['semesters_count'],
            'total_credits' => ($data['total_credits'] ?? '') === '' ? null : (int) $data['total_credits'],
            'valid_from' => ($data['valid_from'] ?? '') === '' ? null : $data['valid_from'],
        ];
    }

    /**
     * @param  array<string, list<string>>  $common
     * @return array<string, mixed>
     */
    private function roomData(array $common): array
    {
        $data = $this->validate([
            'name' => ['nullable', 'string', 'max:255'],
            'building_id' => ['required', 'string'],
            'type' => ['required', Rule::enum(RoomType::class)],
            'capacity' => ['required', 'integer', 'min:1', 'max:100000'],
            'features' => ['nullable', 'string', 'max:1000', app(RoomRules::class)->validateFeatureLength(...)],
        ] + $common);

        return [
            ...$data,
            'name' => $data['name'] ?? null,
            'capacity' => (int) $data['capacity'],
            'features' => app(RoomRules::class)->featureList((string) $data['features']),
        ];
    }

    /**
     * Clave y nombre para el toast, escapados: MaryUI lo pinta con x-html.
     */
    private function describe(CatalogReview $review, Model $record): string
    {
        $code = match ($review->catalog) {
            Catalog::Teachers => $record->rfc,
            Catalog::Rooms => CatalogContentsQuery::roomCode($record),
            default => $record->code,
        };

        return e("{$code} · {$record->name}");
    }
};
