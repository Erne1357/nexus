<?php

use App\Modules\Academic\Models\Department;
use App\Modules\Academic\Models\DepartmentHead;
use App\Modules\AcademicLoad\Actions\CreateTermLoads;
use App\Modules\AcademicLoad\Enums\LoadStatus;
use App\Modules\AcademicLoad\Models\AcademicLoad;
use App\Modules\CatalogValidation\Actions\CreateTermReviews;
use App\Modules\CatalogValidation\Enums\Catalog;
use App\Modules\CatalogValidation\Enums\ReviewStatus;
use App\Modules\CatalogValidation\Models\CatalogReview;
use App\Modules\CatalogValidation\Models\CatalogReviewFlag;
use App\Modules\CatalogValidation\Queries\CatalogReviewQuery;
use App\Modules\CatalogValidation\Queries\ValidationProgressQuery;
use App\Modules\Core\Database\Seeders\RoleSeeder;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\CampusProfile;
use App\Modules\Core\Models\RoleAssignment;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\TermOpening;
use App\Modules\Core\Models\User;
use Illuminate\Support\Collection;

/*
| Tarea 2.5: lo que lee el tablero de avance de validación. El total sigue la regla de HC-14 (las cuatro generales y
| los salones de cada campus abierto que usa salones) y cuadra con CatalogReviewQuery::pendingFor.
*/

beforeEach(function () {
    $this->term = Term::factory()->current()->create(['code' => '20271']);
    $this->c2 = Campus::factory()->create(['code' => 'C2', 'name' => 'Campus II']);
    $this->c1 = Campus::factory()->create(['code' => 'C1', 'name' => 'Campus I']);
    $this->online = Campus::factory()->create(['code' => 'EL', 'name' => 'EnLínea', 'settings' => [...CampusProfile::DEFAULTS, 'uses_rooms' => false]]);
    $this->sis = Department::factory()->create(['code' => 'SIS', 'name' => 'Sistemas']);
    $this->ele = Department::factory()->create(['code' => 'ELE', 'name' => 'Eléctrica']);
    Department::factory()->inactive()->create(['code' => 'OLD']);

    foreach ([$this->c2, $this->c1, $this->online] as $campus) {
        TermOpening::factory()->create(['term_id' => $this->term->id, 'campus_id' => $campus->id]);
        app(CreateTermReviews::class)->handle($this->term, $campus);
    }

    $this->progress = fn (?Collection $campuses = null, string $search = '', string $status = '') => app(ValidationProgressQuery::class)
        ->board($this->term, $campuses ?? app(ValidationProgressQuery::class)->openCampuses($this->term), $search, $status);
});

function progressValidate(Department $department, Catalog $catalog, ?Campus $campus = null, ReviewStatus $status = ReviewStatus::Validated): void
{
    CatalogReview::query()
        ->where('term_id', test()->term->id)
        ->where('department_id', $department->id)
        ->where('catalog', $catalog)
        ->when($catalog->isGeneral(), fn ($query) => $query->whereNull('campus_id'), fn ($query) => $query->where('campus_id', $campus->id))
        ->update(['status' => $status, 'validated_at' => $status === ReviewStatus::Validated ? now() : null]);
}

it('lista los campus donde el periodo está abierto, por código', function () {
    $inactive = Campus::factory()->inactive()->create(['code' => 'AA']);
    TermOpening::factory()->create(['term_id' => $this->term->id, 'campus_id' => $inactive->id]);

    expect(app(ValidationProgressQuery::class)->openCampuses($this->term)->pluck('code')->all())->toBe(['C1', 'C2', 'EL']);
});

it('cuenta el total con HC-14: cuatro generales más salones por cada campus abierto que usa salones', function () {
    $board = ($this->progress)();

    expect($board['global']['total'])->toBe(2 * (4 + 2))
        ->and($board['rows'])->toHaveCount(2)
        ->and($board['rows'][0]['total'])->toBe(6)
        ->and(array_column($board['columns'], 'key'))->toBe(['teachers', 'subjects', 'study_plans', 'careers', 'rooms:'.$this->c1->id, 'rooms:'.$this->c2->id])
        ->and(array_column($board['general_columns'], 'key'))->toBe(['teachers', 'subjects', 'study_plans', 'careers'])
        ->and(array_column($board['room_columns'], 'key'))->toBe(['rooms:'.$this->c1->id, 'rooms:'.$this->c2->id]);
});

it('una revisión que falta cuenta como pendiente y cuadra con pendingFor', function () {
    CatalogReview::query()->where('department_id', $this->sis->id)->where('catalog', Catalog::Careers)->delete();
    progressValidate($this->sis, Catalog::Teachers);
    progressValidate($this->sis, Catalog::Rooms, $this->c2);

    $board = ($this->progress)();
    $row = collect($board['rows'])->firstWhere('department.code', 'SIS');
    $pendingFor = collect([$this->c1, $this->c2])->sum(fn (Campus $campus) => count(app(CatalogReviewQuery::class)->pendingFor($this->term, $campus, $this->sis)));

    expect($row['cells']['careers'])->toBeNull()
        ->and($row['validated'])->toBe(2)
        ->and($row['pending'])->toBe(4)
        // pendingFor cuenta las generales en cada campus: se descuentan las repetidas.
        ->and($row['total'] - $row['validated'])->toBe($pendingFor - 3 * 1);
});

it('con un solo campus filtrado cuenta sus salones y cuadra con pendingFor', function () {
    progressValidate($this->sis, Catalog::Teachers);
    progressValidate($this->sis, Catalog::Rooms, $this->c2);

    $board = ($this->progress)(collect([$this->c2]));
    $row = collect($board['rows'])->firstWhere('department.code', 'SIS');

    expect($board['global']['total'])->toBe(10)
        ->and($row['total'])->toBe(5)
        ->and($row['total'] - $row['validated'])->toBe(count(app(CatalogReviewQuery::class)->pendingFor($this->term, $this->c2, $this->sis)));
});

it('los salones solo cuentan en los campus que los usan', function () {
    $board = ($this->progress)(collect([$this->online]));

    expect($board['global']['total'])->toBe(8)
        ->and(array_column($board['columns'], 'key'))->toBe(['teachers', 'subjects', 'study_plans', 'careers']);
});

it('suma validadas, en revisión y pendientes por fila, por columna y en lo global', function () {
    progressValidate($this->sis, Catalog::Teachers);
    progressValidate($this->ele, Catalog::Teachers);
    progressValidate($this->sis, Catalog::Subjects, status: ReviewStatus::InReview);

    $board = ($this->progress)();

    expect($board['global'])->toMatchArray(['validated' => 2, 'in_review' => 1, 'pending' => 9, 'total' => 12, 'percent' => 17])
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

it('el jefe sale de quien recibe el aviso: alcance de rol vigente y usuario activo, aunque no tenga fila en department_heads', function () {
    $this->seed(RoleSeeder::class);
    $jefe = User::factory()->withRole(Role::JefeDepartamento, ['department_id' => $this->sis->id])->create(['name' => 'Dra. Ana Soto']);
    User::factory()->withRole(Role::JefeDepartamento, ['department_id' => $this->ele->id])->create(['name' => 'Ing. Baja', 'is_active' => false]);
    DepartmentHead::factory()->create(['department_id' => $this->ele->id, 'user_id' => User::factory()->create(['name' => 'Solo en la tabla'])->id]);

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
    $this->seed(RoleSeeder::class);
    $jefe = User::factory()->withRole(Role::JefeDepartamento, ['department_id' => $this->sis->id])->create();
    RoleAssignment::factory()->for($jefe)->create(['role' => Role::JefeDepartamento, 'org_unit_id' => $this->ele->id]);

    expect(($this->progress)()['remindable'])->toBe(['departments' => 2, 'heads' => 1]);

    foreach ([$this->c1, $this->c2, null] as $campus) {
        foreach (Catalog::cases() as $catalog) {
            if ($campus === null && ! $catalog->isGeneral() || $campus !== null && $catalog->isGeneral()) {
                continue;
            }
            progressValidate($this->sis, $catalog, $campus);
        }
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
    $this->seed(RoleSeeder::class);
    User::factory()->withRole(Role::JefeDepartamento, ['department_id' => $this->ele->id])->create(['name' => 'Ing. Raúl Terrazas']);
    foreach (Catalog::cases() as $catalog) {
        progressValidate($this->sis, $catalog, $this->c2);
        progressValidate($this->sis, $catalog, $this->c1);
    }

    $codes = fn (string $search = '', string $status = '') => array_map(fn (array $row) => $row['department']->code, ($this->progress)(null, $search, $status)['rows']);

    expect($codes('sist'))->toBe(['SIS'])
        ->and($codes('raul'))->toBe(['ELE'])
        ->and($codes('', 'pending'))->toBe(['ELE'])
        ->and($codes('', 'validated'))->toBe(['SIS'])
        ->and($codes('nadie'))->toBe([])
        // El global no cambia con los filtros de la tabla.
        ->and(($this->progress)(null, 'nadie')['global']['total'])->toBe(12);
});

it('reporta el estado de la carga de cada departamento y campus mediante el evento con reporte', function () {
    foreach ([$this->c2, $this->c1] as $campus) {
        app(CreateTermLoads::class)->handle($this->term, $campus);
    }

    AcademicLoad::query()->where('department_id', $this->sis->id)->where('campus_id', $this->c2->id)->update(['status' => LoadStatus::Proposed]);

    $row = collect(($this->progress)()['rows'])->firstWhere('department.code', 'SIS');

    expect($row['loads'][$this->c2->id]['label'])->toBe('Propuesta')
        ->and($row['loads'][$this->c1->id]['label'])->toBe('Borrador');
});

it('entrega por renglón las columnas que aún no están validadas, para el celular', function () {
    progressValidate($this->sis, Catalog::Teachers);

    $board = ($this->progress)();
    $sis = collect($board['rows'])->first(fn (array $row) => $row['department']->is($this->sis));

    expect(array_column($sis['pending_columns'], 'key'))->toBe(['subjects', 'study_plans', 'careers', 'rooms:'.$this->c1->id, 'rooms:'.$this->c2->id]);
});
