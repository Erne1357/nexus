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
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\CampusProfile;
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
        ->and(array_column($board['columns'], 'key'))->toBe(['teachers', 'subjects', 'study_plans', 'careers', 'rooms:'.$this->c1->id, 'rooms:'.$this->c2->id]);
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
        ->and($board['totals']['teachers'])->toMatchArray(['validated' => 2, 'total' => 2, 'unvalidated' => 0])
        ->and($board['totals']['subjects'])->toMatchArray(['validated' => 0, 'in_review' => 1, 'total' => 2, 'unvalidated' => 2]);
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

it('trae el jefe vigente de cada departamento y nada si no lo tiene', function () {
    $jefe = User::factory()->create(['name' => 'Dra. Ana Soto']);
    DepartmentHead::factory()->create(['department_id' => $this->sis->id, 'user_id' => $jefe->id]);

    $board = ($this->progress)();

    expect(collect($board['rows'])->firstWhere('department.code', 'SIS')['head']?->name)->toBe('Dra. Ana Soto')
        ->and(collect($board['rows'])->firstWhere('department.code', 'ELE')['head'])->toBeNull();
});

it('filtra por departamento o jefe y por estado', function () {
    $jefe = User::factory()->create(['name' => 'Ing. Raúl Terrazas']);
    DepartmentHead::factory()->create(['department_id' => $this->ele->id, 'user_id' => $jefe->id]);
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
