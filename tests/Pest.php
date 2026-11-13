<?php

use App\Modules\Academic\Enums\PrerequisiteKind;
use App\Modules\Academic\Models\Career;
use App\Modules\Academic\Models\Department;
use App\Modules\Academic\Models\Specialty;
use App\Modules\Academic\Models\StudyPlan;
use App\Modules\Academic\Models\StudyPlanSubject;
use App\Modules\Academic\Models\Subject;
use App\Modules\Academic\Models\SubjectPrerequisite;
use App\Modules\AcademicLoad\Models\AcademicLoad;
use App\Modules\CatalogValidation\Actions\ValidateReview;
use App\Modules\CatalogValidation\Models\CatalogReview;
use App\Modules\CatalogValidation\Models\CatalogReviewMark;
use App\Modules\CatalogValidation\Queries\CatalogContentsQuery;
use App\Modules\Core\Authorization\Actor;
use App\Modules\Core\Authorization\OrgTree;
use App\Modules\Core\Authorization\PermissionRegistry;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Modality;
use App\Modules\Core\Models\ModalityProfile;
use App\Modules\Core\Models\RoleAssignment;
use App\Modules\Core\Models\User;
use App\Modules\Core\Navigation\Navigation;
use Dom\Element;
use Dom\HTMLDocument;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Vite;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Livewire\Features\SupportTesting\Testable;
use Spatie\Activitylog\Models\Activity;
use Tests\Fixtures\Authorization\FakePermission;
use Tests\Fixtures\Modules\DemoCatalog\DemoCatalogServiceProvider;
use Tests\TestCase;

/*
| Feature y Browser corren con la aplicación de Laravel arrancada y la base de
| pruebas refrescada; Unit y Arch, sin ella.
*/

pest()->extend(TestCase::class)->use(RefreshDatabase::class)->in('Feature', 'Browser');

/*
| Los browser tests cargan el CSS y el JS compilados (`npm run build`), porque la banda, la credencial
| y el menú no funcionan sin ellos (TestCase los quita para el resto). Usan siempre el manifiesto,
| aunque corra el servidor de desarrollo de Vite: Chromium, dentro del contenedor, no llega a él.
|
| Su servidor atiende todas las peticiones con la misma aplicación, y la sesión conserva en memoria los
| datos de la petición anterior: el `url.intended` que dejó otra página (una tardía de la prueba que
| acababa) llegaba a la sesión de la siguiente, y entrar llevaba a otra pantalla. Al terminar cada
| petición se vacía, como en FPM u Octane; lo guardado ya está en el manejador.
*/
pest()->beforeEach(function () {
    $this->withVite();
    app(Vite::class)->useHotFile(storage_path('framework/testing/vite-hot-ignorado'));

    Event::listen(RequestHandled::class, function (): void {
        if (app()->resolved('session')) {
            app('session')->driver()->flush();
        }
    });
})->in('Browser');

/**
 * El último toast que el componente mandó mostrar: MaryUI lo evalúa en el navegador como `toast({...})`.
 *
 * @return array{type: string, title: string, description: ?string}|null
 */
function lastToastOf(Testable $component): ?array
{
    $expression = collect($component->effects['xjs'] ?? [])
        ->pluck('expression')
        ->last(fn (string $expression): bool => str_starts_with($expression, 'toast('));

    return $expression === null ? null : json_decode(substr($expression, 6, -1), true)['toast'];
}

/**
 * Marca como revisados todos los registros que hoy tiene la revisión (CV7), como si `$by` (o un usuario nuevo) los
 * hubiera revisado, sin bitácora ni cambio de estado: para poder validarla en una prueba que no trata de las marcas.
 */
function markAllReviewed(CatalogReview $review, ?User $by = null): void
{
    $by ??= User::factory()->create();

    foreach (app(CatalogContentsQuery::class)->records($review) as $record) {
        CatalogReviewMark::query()->firstOrCreate(
            ['catalog_review_id' => $review->getKey(), 'record_id' => $record['id']],
            ['reviewed_by' => $by->getKey(), 'reviewed_at' => now()],
        );
    }
}

/**
 * Valida la revisión con ValidateReview después de marcar como revisado todo lo que tiene (CV7).
 */
function validateAllReviewed(CatalogReview $review, User $actor): CatalogReview
{
    markAllReviewed($review, $actor);

    return app(ValidateReview::class)->handle($review, $actor);
}

/**
 * Las pantallas que siguen, hasta el final de la prueba, abren en `$campus` (o sin campus, con null): lo deja como el
 * último elegido de la sesión (Campus::LAST_CHOSEN_KEY, AZ-02), que es el campus de omisión de WithCampusFilter y
 * WithModalityCampusFilter, porque Livewire::test no lleva el `?campus=` de la URL.
 */
function onCampusPage(?Campus $campus): void
{
    $campus === null ? session()->forget(Campus::LAST_CHOSEN_KEY) : session()->put(Campus::LAST_CHOSEN_KEY, $campus->code);
    app()->forgetScopedInstances();
}

/**
 * Entra como `$user` con `$role` como perfil activo (Actor::ACTIVE_ROLE en la sesión, como lo deja el cambio
 * de perfil): en esta sesión solo cuentan ese puesto y sus nombramientos. Para quien tiene varios puestos; con uno
 * solo, `actingAs()` basta. Lo ven también las peticiones de Livewire::test().
 */
function actingAsRole(User $user, Role $role): TestCase
{
    return test()->actingAs($user)->withSession([Actor::ACTIVE_ROLE => $role->value]);
}

/**
 * Registra el módulo de ejemplo DemoCatalog (tests/Fixtures/Modules) como si hubiera arrancado con la app: indexa los
 * nombres de sus rutas y olvida el menú, el registro de permisos y los servicios de la petición para que se armen otra
 * vez con sus permisos y sus entradas.
 */
function registerDemoCatalog(): void
{
    app()->register(DemoCatalogServiceProvider::class);
    app('router')->getRoutes()->refreshNameLookups();
    app()->forgetInstance(Navigation::class);
    app()->forgetInstance(PermissionRegistry::class);
    app()->forgetScopedInstances();
}

/**
 * Cambia el registro de permisos por uno con solo los de prueba (FakePermission) y olvida los servicios de la petición
 * para que Actor lo use. Lo que concede: Subdirección, jefe de proyecto docente y Coordinación, todos los de gestión
 * (Coordinación también el personal); el jefe de departamento, los de gestión que no declaran carrera, más
 * ViewCampusUnitCareer (de los de modalidad, ViewModality y ViewModalityUnit); Docente, solo el personal. El admin tiene todos menos ViewCampusCareer, su excepción.
 */
function useFakePermissions(): void
{
    $management = [
        FakePermission::ViewCampus, FakePermission::ViewUnit, FakePermission::ViewCareer, FakePermission::ViewCampusUnit,
        FakePermission::ViewCampusCareer, FakePermission::ViewCampusUnitCareer, FakePermission::ViewAny,
        FakePermission::ViewModality, FakePermission::ViewModalityUnit, FakePermission::ViewModalityCareer,
        FakePermission::ViewModalityCampusCareer,
    ];

    app()->instance(PermissionRegistry::class, PermissionRegistry::fromDeclarations([FakePermission::class], [
        Role::Subdireccion->value => $management,
        Role::JefeDepartamento->value => [
            FakePermission::ViewCampus, FakePermission::ViewUnit, FakePermission::ViewCampusUnit, FakePermission::ViewCampusUnitCareer,
            FakePermission::ViewAny, FakePermission::ViewModality, FakePermission::ViewModalityUnit,
        ],
        Role::JefeProyectoDocente->value => $management,
        Role::Coordinador->value => [...$management, FakePermission::ViewOwn],
        Role::Docente->value => [FakePermission::ViewOwn],
    ], [FakePermission::ViewCampusCareer]));

    app()->forgetScopedInstances();
}

/**
 * La modalidad del catálogo con esa clave (`PRES`, `EAD`, `ENL` o `TNMV`), aunque esté inactiva o borrada: la siembra
 * la migración del catálogo.
 */
function modalityOf(string $code): Modality
{
    return Modality::withTrashed()->where('code', $code)->sole();
}

/**
 * Un campus de la modalidad de la carga (M1: la carga ya no tiene campus; sus grupos sí): el primero activo por clave, o
 * uno nuevo de esa modalidad.
 */
function loadCampus(AcademicLoad $load): Campus
{
    return Campus::query()->where('is_active', true)->orderBy('code')->first() ?? Campus::factory()->create();
}

/**
 * Un campus con la forma de antes de contraer la carga por modalidad (2026_11_12_211102, ADR-0025): con su `modality`,
 * su perfil en `settings` y el puente `modality_id` a la modalidad del catálogo que le corresponde. Para las pruebas de
 * las migraciones de la ronda, que corren después del `down()` de la contracción.
 *
 * @param  array<string, mixed>  $settings
 */
function campusBeforeContraction(string $code, string $modality = 'presencial', bool $active = true, array $settings = ModalityProfile::DEFAULTS): Campus
{
    $catalog = ['presencial' => 'PRES', 'mixta' => 'EAD', 'en_linea' => 'ENL', 'virtual_tecnm' => 'TNMV'][$modality];
    $id = (string) Str::uuid7();

    DB::table('campuses')->insert([
        'id' => $id,
        'code' => $code,
        'name' => "Campus {$code}",
        'modality' => $modality,
        'modality_id' => Modality::withTrashed()->where('code', $catalog)->value('id'),
        'settings' => json_encode($settings),
        'is_active' => $active,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return Campus::withTrashed()->findOrFail($id);
}

/**
 * Una foto con la forma v1 (MS13): el campus en la raíz y los grupos sin campus, como las que se publicaron antes de la
 * carga por modalidad. Sale de una v2 (la de LoadSnapshotFactory) quitándole la modalidad y el campus de cada grupo.
 *
 * @param  array<string, mixed>  $payload
 * @return array<string, mixed>
 */
function legacySnapshotPayload(array $payload, Campus $campus): array
{
    $legacy = ['schema_version' => 1];

    foreach ($payload as $key => $value) {
        match ($key) {
            'schema_version' => null,
            'modality' => $legacy['campus'] = ['id' => $campus->id, 'code' => $campus->code, 'name' => $campus->name],
            'groups' => $legacy['groups'] = array_map(fn (array $group): array => array_diff_key($group, ['campus' => true]), $value),
            default => $legacy[$key] = $value,
        };
    }

    return $legacy;
}

/**
 * Deja libre un puesto exclusivo (AZ-S7: una Subdirección y un jefe por unidad en cada fecha) para nombrar a otra
 * persona desde hoy: los nombramientos de `$role` en la unidad que empezaron antes de hoy terminan ayer, como un
 * relevo, y los que empiezan hoy o después se borran. La Subdirección va, sin `$orgUnitId`, en Subdirección
 * Académica. El nombramiento nuevo debe empezar hoy (`'valid_from' => today()->toDateString()`).
 */
function vacate(Role $role, ?string $orgUnitId = null): void
{
    $unit = $orgUnitId ?? app(OrgTree::class)->idOf(OrgTree::ACADEMIC);
    $today = today()->toDateString();
    $seat = fn () => RoleAssignment::query()
        ->where('role', $role)
        ->where('org_unit_id', $unit)
        ->where(fn (Builder $query) => $query->whereNull('valid_to')->orWhere('valid_to', '>=', $today));

    $seat()->where('valid_from', '>=', $today)->delete();
    $seat()->update(['valid_to' => today()->subDay()->toDateString()]);
}

/**
 * Un uuid fijo: `fakeKey(10)` siempre da el mismo valor. Sirve para un registro que la prueba no crea,
 * o, con departmentKey() y careerKey(), para comparar contra el que sí crea.
 */
function fakeKey(int $number): string
{
    return sprintf('00000000-0000-7000-8000-%012d', $number);
}

/**
 * El id de un departamento con el uuid de fakeKey($number), que se crea la primera vez: los alcances de
 * las pruebas solo necesitan que exista, y las pruebas comparan contra fakeKey($number).
 */
function departmentKey(int $number): string
{
    return fixedKeyOf(Department::class, $number);
}

/**
 * El id de una carrera con el uuid de fakeKey($number), que se crea la primera vez (ver departmentKey()).
 */
function careerKey(int $number): string
{
    return fixedKeyOf(Career::class, $number);
}

/**
 * Crea con su factory, si no existe, el registro del modelo con el uuid de fakeKey($number), y da ese uuid.
 *
 * @param  class-string<Model>  $model
 */
function fixedKeyOf(string $model, int $number): string
{
    $id = fakeKey($number);

    if (! $model::query()->withTrashed()->whereKey($id)->exists()) {
        $model::factory()->create(['id' => $id]);
    }

    return $id;
}

/**
 * Pone una materia en la retícula de un plan (Academic, tarea 1.7): para las pruebas de retícula, progreso
 * y elegibles.
 */
function placeInPlan(StudyPlan $plan, Subject $subject, int $semester = 1, ?Specialty $specialty = null): StudyPlanSubject
{
    return StudyPlanSubject::factory()->for($plan)->for($subject)->create(['semester' => $semester, 'specialty_id' => $specialty?->id]);
}

/**
 * Seria una materia con su requisito en un plan; las dos tienen que estar en su retícula (placeInPlan()).
 */
function seriate(StudyPlan $plan, Subject $subject, Subject $prerequisite, PrerequisiteKind $kind = PrerequisiteKind::Seriacion): SubjectPrerequisite
{
    return SubjectPrerequisite::factory()->create([
        'study_plan_id' => $plan->id,
        'subject_id' => $subject->id,
        'prerequisite_subject_id' => $prerequisite->id,
        'kind' => $kind,
    ]);
}

/**
 * El HTML de una respuesta o de un componente como documento, para afirmar dentro de una parte de la
 * página (la banda, la credencial, el menú) con selectores CSS.
 */
function domOf(TestResponse|Testable|string $html): HTMLDocument
{
    $html = match (true) {
        $html instanceof TestResponse => (string) $html->getContent(),
        $html instanceof Testable => $html->html(),
        default => $html,
    };

    return HTMLDocument::createFromString($html, LIBXML_NOERROR);
}

/**
 * El texto de un elemento con los espacios colapsados; cadena vacía si el elemento no existe.
 */
function textOf(?Element $element): string
{
    return trim((string) preg_replace('/\s+/u', ' ', $element?->textContent ?? ''));
}

/**
 * Expresión de JS para los browser tests: si la búsqueda, los filtros y la acción de la barra de herramientas de
 * catálogo están en un mismo renglón y ningún texto de la búsqueda ni de los filtros se corta (DESIGN.md, "Anatomía
 * de una pantalla de catálogo").
 */
function catalogToolbarInOneRowScript(): string
{
    return <<<'JS'
        (() => {
            const bar = document.querySelector('[data-testid=catalog-toolbar]');
            const rects = [bar.querySelector('[data-testid=catalog-search]'), ...bar.querySelectorAll('[data-testid=catalog-filters] > *'), bar.lastElementChild]
                .map((element) => element.getBoundingClientRect());
            const oneRow = Math.max(...rects.map((rect) => rect.top)) < Math.min(...rects.map((rect) => rect.bottom));
            const context = document.createElement('canvas').getContext('2d');
            const fits = (element, text) => {
                const style = getComputedStyle(element);
                context.font = style.font;
                return context.measureText(text).width <= element.clientWidth - parseFloat(style.paddingLeft) - parseFloat(style.paddingRight);
            };
            const search = bar.querySelector('input');

            return oneRow
                && fits(search, search.placeholder)
                && [...bar.querySelectorAll('select')].every((select) => fits(select, select.selectedOptions[0]?.text ?? ''));
        })()
        JS;
}

/**
 * La bitácora escrita después del arranque de la prueba (la bitácora es inmutable: no se vacía).
 *
 * @return Builder<Activity>
 */
function activitySince(): Builder
{
    return Activity::query()->where('id', '>', test()->activityBase);
}
