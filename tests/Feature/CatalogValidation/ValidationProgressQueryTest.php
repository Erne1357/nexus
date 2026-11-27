<?php

use App\Modules\Academic\Models\Department;
use App\Modules\AcademicLoad\Actions\CreateTermLoads;
use App\Modules\AcademicLoad\Enums\LoadStatus;
use App\Modules\AcademicLoad\Models\AcademicLoad;
use App\Modules\CatalogValidation\Actions\CreateTermReviews;
use App\Modules\CatalogValidation\Enums\Catalog;
use App\Modules\CatalogValidation\Enums\CatalogOwner;
use App\Modules\CatalogValidation\Enums\ReviewStatus;
use App\Modules\CatalogValidation\Models\CatalogReview;
use App\Modules\CatalogValidation\Models\CatalogReviewFlag;
use App\Modules\CatalogValidation\Models\CatalogReviewMark;
use App\Modules\CatalogValidation\Queries\CatalogReviewQuery;
use App\Modules\CatalogValidation\Queries\ReviewOwner;
use App\Modules\CatalogValidation\Queries\ValidationProgressQuery;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Modality;
use App\Modules\Core\Models\ModalityProfile;
use App\Modules\Core\Models\RoleAssignment;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\TermOpening;
use App\Modules\Core\Models\User;
use App\Modules\Faculty\Models\Teacher;

/*
| Tarea 2.5: lo que lee el tablero de avance de validación. Cada departamento tiene su renglón con sus docentes y una de
| salones para todos los campus si alguno abierto usa salones (decisión 6, confirmada el 12 de noviembre de 2026), y
| Servicios Escolares el suyo, primero, con materias, planes y carreras de todo el Tec (CV15). El total de cada renglón
| cuadra con CatalogReviewQuery::pendingOf.
*/

beforeEach(function () {
    $this->term = Term::factory()->current()->create(['code' => '20271']);
    $this->c2 = Campus::factory()->create(['code' => 'C2', 'name' => 'Campus II']);
    $this->c1 = Campus::factory()->create(['code' => 'C1', 'name' => 'Campus I']);
    $this->c3 = Campus::factory()->create(['code' => 'C3', 'name' => 'Campus III']);
    $this->sis = Department::factory()->create(['code' => 'SIS', 'name' => 'Sistemas']);
    $this->ele = Department::factory()->create(['code' => 'ELE', 'name' => 'Eléctrica']);
    Department::factory()->inactive()->create(['code' => 'OLD']);

    // El periodo abierto en Presencial (M4), que usa salones: sus grupos se dan en cualquiera de los tres campus (MS4).
    TermOpening::factory()->create(['term_id' => $this->term->id]);
    app(CreateTermReviews::class)->handle($this->term, Catalog::cases());

    $this->progress = fn (string $search = '', string $status = '') => app(ValidationProgressQuery::class)->board($this->term, $search, $status);
});

/**
 * Pone en `$status` la revisión del catálogo: la del departamento en docentes y salones, la de Servicios Escolares en
 * materias, planes y carreras (CV15).
 */
function progressValidate(Department $department, Catalog $catalog, ReviewStatus $status = ReviewStatus::Validated): void
{
    CatalogReview::query()
        ->where('term_id', test()->term->id)
        ->tap(fn ($query) => CatalogReviewQuery::ownedBy($query, $catalog->owner() === CatalogOwner::Department ? $department->id : null))
        ->where('catalog', $catalog)
        ->update(['status' => $status, 'validated_at' => $status === ReviewStatus::Validated ? now() : null]);
}

it('M4, MS4: lista los campus activos donde se dan los grupos de las modalidades donde el periodo está abierto, por código', function () {
    Campus::factory()->inactive()->create(['code' => 'AA']);

    expect(app(ValidationProgressQuery::class)->openCampuses($this->term)->pluck('code')->all())->toBe(['C1', 'C2', 'C3']);
});

it('MS4: un periodo abierto solo en modalidades sin salones no tiene campus abiertos', function () {
    $online = Modality::factory()->create(['settings' => [...ModalityProfile::DEFAULTS, 'uses_rooms' => false]]);
    $term = Term::factory()->create(['code' => '20272']);
    TermOpening::factory()->create(['term_id' => $term->id, 'modality_id' => $online->id]);

    expect(app(ValidationProgressQuery::class)->openCampuses($term))->toBeEmpty();
});

it('CV15: cada departamento cuenta sus docentes y salones, y Servicios Escolares, primero, sus tres catálogos', function () {
    $board = ($this->progress)();

    expect($board['global']['total'])->toBe(3 + 2 * 2)
        ->and($board['rows'])->toHaveCount(3)
        ->and($board['rows'][0]['department']->id)->toBe(ReviewOwner::SCHOOL_SERVICES)
        ->and($board['rows'][0]['total'])->toBe(3)
        ->and($board['rows'][1]['total'])->toBe(2)
        ->and(array_column($board['columns'], 'key'))->toBe(['teachers', 'rooms', 'subjects', 'study_plans', 'careers'])
        ->and(array_column($board['rows'][1]['columns'], 'key'))->toBe(['teachers', 'rooms']);
});

it('una revisión que falta cuenta como pendiente y cuadra con pendingOf', function () {
    CatalogReview::query()->where('department_id', $this->sis->id)->where('catalog', Catalog::Rooms)->delete();
    progressValidate($this->sis, Catalog::Teachers);

    $board = ($this->progress)();
    $row = collect($board['rows'])->firstWhere('department.code', 'SIS');

    expect($row['cells']['rooms'])->toBeNull()
        ->and($row['validated'])->toBe(1)
        ->and($row['pending'])->toBe(1)
        ->and($row['total'] - $row['validated'])->toBe(count(app(CatalogReviewQuery::class)->pendingOf($this->term, ReviewOwner::ofDepartment($this->sis))));
});

it('cuenta la de salones, una para todo el Tec, y cuadra con pendingOf', function () {
    progressValidate($this->sis, Catalog::Teachers);
    progressValidate($this->sis, Catalog::Rooms);

    $board = ($this->progress)();
    $row = collect($board['rows'])->firstWhere('department.code', 'SIS');

    expect($board['global']['total'])->toBe(7)
        ->and($row['total'])->toBe(2)
        ->and($row['total'] - $row['validated'])->toBe(count(app(CatalogReviewQuery::class)->pendingOf($this->term, ReviewOwner::ofDepartment($this->sis))));
});

it('CV9: las columnas y el total son los catálogos abiertos en el periodo', function () {
    CatalogReview::query()->whereIn('catalog', [Catalog::Rooms->value, Catalog::Careers->value])->delete();

    $board = ($this->progress)();

    expect($board['global']['total'])->toBe(4)
        ->and(array_column($board['columns'], 'key'))->toBe(['teachers', 'subjects', 'study_plans']);

    CatalogReview::query()->delete();

    expect(array_column(($this->progress)()['columns'], 'key'))->toBe([]);
});

it('suma validadas, en revisión y pendientes por fila, por columna y en lo global', function () {
    progressValidate($this->sis, Catalog::Teachers);
    progressValidate($this->ele, Catalog::Teachers);
    progressValidate($this->sis, Catalog::Subjects, ReviewStatus::InReview);

    $board = ($this->progress)();

    expect($board['global'])->toMatchArray(['validated' => 2, 'in_review' => 1, 'pending' => 4, 'total' => 7, 'percent' => 29])
        ->and($board['totals']['teachers'])->toMatchArray(['validated' => 2, 'total' => 2])
        ->and($board['totals']['subjects'])->toMatchArray(['validated' => 0, 'in_review' => 1, 'total' => 1]);
});

it('cuenta las observaciones abiertas y en cuántos departamentos, y las resueltas', function () {
    $review = CatalogReview::query()->where('department_id', $this->sis->id)->where('catalog', Catalog::Teachers)->sole();
    CatalogReviewFlag::factory()->count(2)->create(['review_id' => $review->id]);
    CatalogReviewFlag::factory()->resolved()->create(['review_id' => $review->id]);

    $board = ($this->progress)();
    $row = collect($board['rows'])->firstWhere('department.code', 'SIS');

    expect($board['flags'])->toBe(['open' => 2, 'departments' => 1, 'resolved' => 1])
        ->and($row['open_flags'])->toBe(2)
        ->and(collect($board['rows'])->firstWhere('department.code', 'ELE')['open_flags'])->toBe(0);
});

it('el jefe sale de quien recibe el aviso: nombramiento vigente y usuario activo', function () {
    $jefe = User::factory()->withRole(Role::JefeDepartamento, ['department_id' => $this->sis->id])->create(['name' => 'Dra. Ana Soto']);
    User::factory()->withRole(Role::JefeDepartamento, ['department_id' => $this->ele->id])->create(['name' => 'Ing. Baja', 'is_active' => false]);

    $board = ($this->progress)();
    $sis = collect($board['rows'])->firstWhere('department.code', 'SIS');
    $ele = collect($board['rows'])->firstWhere('department.code', 'ELE');

    expect($sis['head']?->is($jefe))->toBeTrue()
        ->and($sis['remind_blocked_reason'])->toBeNull()
        ->and($ele['head'])->toBeNull()
        ->and($ele['remind_blocked_reason'])->toBe('No tiene jefe vigente.')
        ->and($board['remindable'])->toBe(['departments' => 1, 'heads' => 1]);
});

it('«Recordar» queda bloqueado si no falta nada, y la insignia cuenta jefes distintos', function () {
    $jefe = User::factory()->withRole(Role::JefeDepartamento, ['department_id' => $this->sis->id])->create();
    RoleAssignment::factory()->for($jefe)->create(['role' => Role::JefeDepartamento, 'org_unit_id' => $this->ele->id]);

    expect(($this->progress)()['remindable'])->toBe(['departments' => 2, 'heads' => 1]);

    foreach (Catalog::ownedBy(CatalogOwner::Department) as $catalog) {
        progressValidate($this->sis, $catalog);
    }

    $sis = collect(($this->progress)()['rows'])->firstWhere('department.code', 'SIS');

    expect($sis['remind_blocked_reason'])->toBe('No le falta nada por validar.')
        ->and(($this->progress)()['remindable'])->toBe(['departments' => 1, 'heads' => 1]);
});

it('con un departamento dado arma solo su fila', function () {
    $board = app(ValidationProgressQuery::class)->board($this->term, '', 'validated', $this->ele->id);

    expect($board['rows'])->toBe([])
        ->and(app(ValidationProgressQuery::class)->board($this->term, ownerId: $this->ele->id)['rows'])->toHaveCount(1)
        ->and(app(ValidationProgressQuery::class)->board($this->term, ownerId: ReviewOwner::SCHOOL_SERVICES)['rows'][0]['department']->name)->toBe('Servicios Escolares');
});

it('el último recordatorio cuenta los de cualquier campus: la validación es de todo el Tec', function () {
    activity()->performedOn($this->sis)->event('reminded')->withProperties(['term_id' => $this->term->id, 'campuses' => ['C1'], 'notified' => 1, 'recipients' => []])->log('x');
    activity()->performedOn($this->ele)->event('reminded')->withProperties(['term_id' => fakeKey(1), 'notified' => 1, 'recipients' => []])->log('x');

    expect(app(ValidationProgressQuery::class)->lastReminders($this->term, [$this->sis->id, $this->ele->id]))->toHaveKey($this->sis->id)->not->toHaveKey($this->ele->id);
});

it('filtra por departamento o jefe y por estado', function () {
    User::factory()->withRole(Role::JefeDepartamento, ['department_id' => $this->ele->id])->create(['name' => 'Ing. Raúl Terrazas']);
    foreach (Catalog::ownedBy(CatalogOwner::Department) as $catalog) {
        progressValidate($this->sis, $catalog);
    }

    $codes = fn (string $search = '', string $status = '') => array_map(fn (array $row) => $row['department']->code, ($this->progress)($search, $status)['rows']);

    expect($codes('sist'))->toBe(['SIS'])
        ->and($codes('raul'))->toBe(['ELE'])
        ->and($codes('', 'pending'))->toBe(['SE', 'ELE'])
        ->and($codes('', 'validated'))->toBe(['SIS'])
        ->and($codes('nadie'))->toBe([])
        // El global no cambia con los filtros de la tabla.
        ->and(($this->progress)('nadie')['global']['total'])->toBe(7);
});

it('reporta el estado de la carga de cada departamento en la modalidad de los campus mediante el evento con reporte (M1)', function () {
    app(CreateTermLoads::class)->handle($this->term, modalityOf('PRES'));

    AcademicLoad::query()->where('department_id', $this->sis->id)->update(['status' => LoadStatus::Proposed]);

    $row = collect(($this->progress)()['rows'])->firstWhere('department.code', 'SIS');

    expect($row['loads'])->toHaveCount(1)
        ->and($row['loads'][0]['modality']->code)->toBe('PRES')
        ->and($row['loads'][0]['load']['label'])->toBe('Propuesta');
});

it('entrega por renglón las columnas que aún no están validadas, para el celular', function () {
    progressValidate($this->sis, Catalog::Teachers);

    $board = ($this->progress)();
    $sis = collect($board['rows'])->first(fn (array $row) => $row['department']->id === $this->sis->id);

    expect(array_column($sis['pending_columns'], 'key'))->toBe(['rooms']);
});

it('CV7: dice por celda cuántos registros van revisados de cuántos, y nulo si falta la revisión', function () {
    CatalogReview::query()->where('department_id', $this->sis->id)->where('catalog', Catalog::Rooms)->delete();
    $review = CatalogReview::query()->where('department_id', $this->sis->id)->where('catalog', Catalog::Teachers)->sole();
    $teacher = Teacher::factory()->adscribedTo($this->sis)->create();
    Teacher::factory()->adscribedTo($this->sis)->create();
    CatalogReviewMark::factory()->for($review, 'review')->on($teacher)->create();

    $sis = collect(($this->progress)()['rows'])->firstWhere('department.code', 'SIS');

    expect($sis['reviewed']['teachers'])->toBe(['reviewed' => 1, 'total' => 2])
        ->and($sis['reviewed']['rooms'])->toBeNull()
        ->and($sis['reviewed'])->not->toHaveKey('subjects');
});
