<?php

use App\Modules\Academic\Models\Department;
use App\Modules\AcademicLoad\Actions\CreateTermLoads;
use App\Modules\AcademicLoad\Enums\LoadStatus;
use App\Modules\AcademicLoad\Models\AcademicLoad;
use App\Modules\CatalogValidation\Actions\CreateTermReviews;
use App\Modules\CatalogValidation\Enums\Catalog;
use App\Modules\CatalogValidation\Enums\ReviewStatus;
use App\Modules\CatalogValidation\Models\CatalogReview;
use App\Modules\CatalogValidation\Models\CatalogReviewFlag;
use App\Modules\CatalogValidation\Models\CatalogReviewMark;
use App\Modules\CatalogValidation\Queries\CatalogReviewQuery;
use App\Modules\CatalogValidation\Queries\ValidationProgressQuery;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\CampusProfile;
use App\Modules\Core\Models\Modality;
use App\Modules\Core\Models\RoleAssignment;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\TermOpening;
use App\Modules\Core\Models\User;
use App\Modules\Faculty\Models\Teacher;
use Illuminate\Support\Collection;

/*
| Tarea 2.5: lo que lee el tablero de avance de validación. El total sigue la regla de HC-14 (las cuatro que no son
| salones y una de salones para todos los campus si alguno abierto usa salones; decisión 6, confirmada el 12 de
| noviembre de 2026) y cuadra con CatalogReviewQuery::pendingFor.
*/

beforeEach(function () {
    $this->term = Term::factory()->current()->create(['code' => '20271']);
    $this->c2 = Campus::factory()->create(['code' => 'C2', 'name' => 'Campus II']);
    $this->c1 = Campus::factory()->create(['code' => 'C1', 'name' => 'Campus I']);
    $this->online = Campus::factory()->create(['code' => 'EL', 'name' => 'EnLínea', 'settings' => [...CampusProfile::DEFAULTS, 'uses_rooms' => false]]);
    $this->sis = Department::factory()->create(['code' => 'SIS', 'name' => 'Sistemas']);
    $this->ele = Department::factory()->create(['code' => 'ELE', 'name' => 'Eléctrica']);
    Department::factory()->inactive()->create(['code' => 'OLD']);

    // El periodo abierto en Presencial (M4): los tres campus son de ella por el puente `campuses.modality_id`.
    TermOpening::factory()->create(['term_id' => $this->term->id]);
    app(CreateTermReviews::class)->handle($this->term, modalityOf('PRES'));

    $this->progress = fn (?Collection $campuses = null, string $search = '', string $status = '') => app(ValidationProgressQuery::class)
        ->board($this->term, $campuses ?? app(ValidationProgressQuery::class)->openCampuses($this->term), $search, $status);
});

function progressValidate(Department $department, Catalog $catalog, ReviewStatus $status = ReviewStatus::Validated): void
{
    CatalogReview::query()
        ->where('term_id', test()->term->id)
        ->where('department_id', $department->id)
        ->where('catalog', $catalog)
        ->update(['status' => $status, 'validated_at' => $status === ReviewStatus::Validated ? now() : null]);
}

it('M4: lista los campus activos de las modalidades donde el periodo está abierto, por código', function () {
    Campus::factory()->inactive()->create(['code' => 'AA']);
    Campus::factory()->create(['code' => 'ZZ', 'modality_id' => Modality::factory()->create()->id]);

    expect(app(ValidationProgressQuery::class)->openCampuses($this->term)->pluck('code')->all())->toBe(['C1', 'C2', 'EL']);
});

it('cuenta el total con HC-14: las cuatro que no son salones más una de salones para todos los campus', function () {
    $board = ($this->progress)();

    expect($board['global']['total'])->toBe(2 * 5)
        ->and($board['rows'])->toHaveCount(2)
        ->and($board['rows'][0]['total'])->toBe(5)
        ->and(array_column($board['columns'], 'key'))->toBe(['teachers', 'subjects', 'study_plans', 'careers', 'rooms']);
});

it('una revisión que falta cuenta como pendiente y cuadra con pendingFor', function () {
    CatalogReview::query()->where('department_id', $this->sis->id)->where('catalog', Catalog::Careers)->delete();
    progressValidate($this->sis, Catalog::Teachers);
    progressValidate($this->sis, Catalog::Rooms);

    $board = ($this->progress)();
    $row = collect($board['rows'])->firstWhere('department.code', 'SIS');

    expect($row['cells']['careers'])->toBeNull()
        ->and($row['validated'])->toBe(2)
        ->and($row['pending'])->toBe(3)
        ->and($row['total'] - $row['validated'])->toBe(count(app(CatalogReviewQuery::class)->pendingFor($this->term, $this->c2, $this->sis)));
});

it('con un solo campus filtrado que usa salones cuenta la de salones y cuadra con pendingFor', function () {
    progressValidate($this->sis, Catalog::Teachers);
    progressValidate($this->sis, Catalog::Rooms);

    $board = ($this->progress)(collect([$this->c2]));
    $row = collect($board['rows'])->firstWhere('department.code', 'SIS');

    expect($board['global']['total'])->toBe(10)
        ->and($row['total'])->toBe(5)
        ->and($row['total'] - $row['validated'])->toBe(count(app(CatalogReviewQuery::class)->pendingFor($this->term, $this->c2, $this->sis)));
});

it('los salones solo cuentan si algún campus del alcance los usa', function () {
    $board = ($this->progress)(collect([$this->online]));

    expect($board['global']['total'])->toBe(8)
        ->and(array_column($board['columns'], 'key'))->toBe(['teachers', 'subjects', 'study_plans', 'careers']);
});

it('suma validadas, en revisión y pendientes por fila, por columna y en lo global', function () {
    progressValidate($this->sis, Catalog::Teachers);
    progressValidate($this->ele, Catalog::Teachers);
    progressValidate($this->sis, Catalog::Subjects, ReviewStatus::InReview);

    $board = ($this->progress)();

    expect($board['global'])->toMatchArray(['validated' => 2, 'in_review' => 1, 'pending' => 7, 'total' => 10, 'percent' => 20])
        ->and($board['totals']['teachers'])->toMatchArray(['validated' => 2, 'total' => 2])
        ->and($board['totals']['subjects'])->toMatchArray(['validated' => 0, 'in_review' => 1, 'total' => 2]);
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

    foreach (Catalog::cases() as $catalog) {
        progressValidate($this->sis, $catalog);
    }

    $sis = collect(($this->progress)()['rows'])->firstWhere('department.code', 'SIS');

    expect($sis['remind_blocked_reason'])->toBe('No le falta nada por validar.')
        ->and(($this->progress)()['remindable'])->toBe(['departments' => 1, 'heads' => 1]);
});

it('con un departamento dado arma solo su fila', function () {
    $board = app(ValidationProgressQuery::class)->board($this->term, collect([$this->c2]), '', 'validated', $this->ele->id);

    expect($board['rows'])->toBe([])
        ->and(app(ValidationProgressQuery::class)->board($this->term, collect([$this->c2]), departmentId: $this->ele->id)['rows'])->toHaveCount(1);
});

it('el último recordatorio solo cuenta los campus del alcance', function () {
    activity()->performedOn($this->sis)->event('reminded')->withProperties(['term_id' => $this->term->id, 'campuses' => ['C1'], 'notified' => 1, 'recipients' => []])->log('x');

    $query = app(ValidationProgressQuery::class);

    expect($query->lastReminders($this->term, [$this->sis->id], ['C2']))->toBe([])
        ->and($query->lastReminders($this->term, [$this->sis->id], ['C2', 'C1']))->toHaveKey($this->sis->id)
        ->and($query->lastReminders($this->term, [$this->sis->id], []))->toBe([]);
});

it('filtra por departamento o jefe y por estado', function () {
    User::factory()->withRole(Role::JefeDepartamento, ['department_id' => $this->ele->id])->create(['name' => 'Ing. Raúl Terrazas']);
    foreach (Catalog::cases() as $catalog) {
        progressValidate($this->sis, $catalog);
    }

    $codes = fn (string $search = '', string $status = '') => array_map(fn (array $row) => $row['department']->code, ($this->progress)(null, $search, $status)['rows']);

    expect($codes('sist'))->toBe(['SIS'])
        ->and($codes('raul'))->toBe(['ELE'])
        ->and($codes('', 'pending'))->toBe(['ELE'])
        ->and($codes('', 'validated'))->toBe(['SIS'])
        ->and($codes('nadie'))->toBe([])
        // El global no cambia con los filtros de la tabla.
        ->and(($this->progress)(null, 'nadie')['global']['total'])->toBe(10);
});

it('reporta el estado de la carga de cada departamento y campus mediante el evento con reporte', function () {
    app(CreateTermLoads::class)->handle($this->term, modalityOf('PRES'));

    AcademicLoad::query()->where('department_id', $this->sis->id)->where('campus_id', $this->c2->id)->update(['status' => LoadStatus::Proposed]);

    $row = collect(($this->progress)()['rows'])->firstWhere('department.code', 'SIS');

    expect($row['loads'][$this->c2->id]['label'])->toBe('Propuesta')
        ->and($row['loads'][$this->c1->id]['label'])->toBe('Borrador');
});

it('entrega por renglón las columnas que aún no están validadas, para el celular', function () {
    progressValidate($this->sis, Catalog::Teachers);

    $board = ($this->progress)();
    $sis = collect($board['rows'])->first(fn (array $row) => $row['department']->is($this->sis));

    expect(array_column($sis['pending_columns'], 'key'))->toBe(['subjects', 'study_plans', 'careers', 'rooms']);
});

it('CV7: dice por celda cuántos registros van revisados de cuántos, y nulo si falta la revisión', function () {
    CatalogReview::query()->where('department_id', $this->sis->id)->where('catalog', Catalog::Careers)->delete();
    $review = CatalogReview::query()->where('department_id', $this->sis->id)->where('catalog', Catalog::Teachers)->sole();
    $teacher = Teacher::factory()->adscribedTo($this->sis)->create();
    Teacher::factory()->adscribedTo($this->sis)->create();
    CatalogReviewMark::factory()->for($review, 'review')->on($teacher)->create();

    $sis = collect(($this->progress)()['rows'])->firstWhere('department.code', 'SIS');

    expect($sis['reviewed']['teachers'])->toBe(['reviewed' => 1, 'total' => 2])
        ->and($sis['reviewed']['subjects'])->toBe(['reviewed' => 0, 'total' => 0])
        ->and($sis['reviewed']['careers'])->toBeNull();
});
