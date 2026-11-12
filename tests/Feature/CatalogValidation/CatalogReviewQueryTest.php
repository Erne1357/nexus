<?php

use App\Modules\Academic\Models\Career;
use App\Modules\Academic\Models\Department;
use App\Modules\CatalogValidation\Enums\Catalog;
use App\Modules\CatalogValidation\Models\CatalogReview;
use App\Modules\CatalogValidation\Models\CatalogReviewFlag;
use App\Modules\CatalogValidation\Queries\CatalogReviewQuery;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\CampusProfile;
use App\Modules\Core\Models\Term;
use Illuminate\Support\Facades\DB;

/*
| CatalogReviewQuery::pendingFor: los catálogos que bloquean HC-14 para la carga de un departamento en un
| campus y un periodo, en el orden del enum; la revisión de salones es una para todos los campus (decisión 6). forDepartment, previousValidation y summary: lo que el jefe ve
| de sus revisiones, con las altas y bajas contra la foto de la última validación anterior (confirmado el 2
| de noviembre de 2026).
*/

beforeEach(function () {
    $this->term = Term::factory()->create();
    $this->campus = Campus::factory()->create();
    $this->department = Department::factory()->create();
});

it('sin revisiones lista todos los generales y los salones, en el orden del enum', function () {
    expect(app(CatalogReviewQuery::class)->pendingFor($this->term, $this->campus, $this->department))
        ->toBe([Catalog::Teachers, Catalog::Rooms, Catalog::Subjects, Catalog::StudyPlans, Catalog::Careers]);
});

it('acepta los ids en lugar de los modelos y quita los validados', function () {
    CatalogReview::factory()->general(Catalog::Teachers)->validated()->create(['term_id' => $this->term->id, 'department_id' => $this->department->id]);
    CatalogReview::factory()->rooms()->validated()->create(['term_id' => $this->term->id, 'department_id' => $this->department->id]);
    CatalogReview::factory()->general(Catalog::Careers)->inReview()->create(['term_id' => $this->term->id, 'department_id' => $this->department->id]);

    expect(app(CatalogReviewQuery::class)->pendingFor($this->term->id, $this->campus, $this->department->id))
        ->toBe([Catalog::Subjects, Catalog::StudyPlans, Catalog::Careers]);
});

it('no pide salones si el perfil del campus no los usa', function () {
    $campus = Campus::factory()->create(['settings' => [...CampusProfile::DEFAULTS, 'uses_rooms' => false]]);

    expect(app(CatalogReviewQuery::class)->pendingFor($this->term, $campus, $this->department))
        ->not->toContain(Catalog::Rooms);
});

it('no cuenta las revisiones de otro periodo ni de otro departamento; la de salones vale en cualquier campus', function () {
    foreach (Catalog::cases() as $catalog) {
        CatalogReview::factory()->create(['catalog' => $catalog, 'status' => 'validated', 'term_id' => Term::factory()->create()->id, 'department_id' => $this->department->id]);
        CatalogReview::factory()->create(['catalog' => $catalog, 'status' => 'validated', 'term_id' => $this->term->id, 'department_id' => Department::factory()->create()->id]);
    }

    expect(app(CatalogReviewQuery::class)->pendingFor($this->term, $this->campus, $this->department))->toHaveCount(5);

    CatalogReview::factory()->rooms()->validated()->create(['term_id' => $this->term->id, 'department_id' => $this->department->id]);

    expect(app(CatalogReviewQuery::class)->pendingFor($this->term, Campus::factory()->create(), $this->department))->not->toContain(Catalog::Rooms);
});

it('con lock lee las revisiones con FOR SHARE y sin él no', function () {
    $queries = [];
    DB::listen(function ($query) use (&$queries) {
        $queries[] = $query->sql;
    });

    app(CatalogReviewQuery::class)->pendingFor($this->term, $this->campus, $this->department);
    expect(collect($queries)->filter(fn (string $sql) => str_contains($sql, 'for share')))->toBeEmpty();

    $queries = [];
    app(CatalogReviewQuery::class)->pendingFor($this->term, $this->campus, $this->department, lock: true);
    expect(collect($queries)->filter(fn (string $sql) => str_contains($sql, 'catalog_reviews') && str_contains($sql, 'for share')))->toHaveCount(1);
});

describe('revisiones de un departamento', function () {
    it('da las del periodo y el departamento, la de salones para todos los campus, en el orden del enum', function () {
        $own = ['term_id' => $this->term->id, 'department_id' => $this->department->id];
        foreach ([Catalog::Careers, Catalog::Subjects, Catalog::Teachers, Catalog::StudyPlans] as $catalog) {
            CatalogReview::factory()->general($catalog)->create($own);
        }
        CatalogReview::factory()->rooms()->create($own);
        CatalogReview::factory()->general(Catalog::Teachers)->create(['term_id' => $this->term->id]);
        CatalogReview::factory()->general(Catalog::Teachers)->create(['department_id' => $this->department->id]);

        $reviews = app(CatalogReviewQuery::class)->forDepartment($this->term, $this->department);

        expect($reviews->map(fn (CatalogReview $review) => $review->catalog)->all())
            ->toBe([Catalog::Teachers, Catalog::Rooms, Catalog::Subjects, Catalog::StudyPlans, Catalog::Careers]);
    });

    it('acepta los ids', function () {
        CatalogReview::factory()->general(Catalog::Subjects)->create(['term_id' => $this->term->id, 'department_id' => $this->department->id]);

        expect(app(CatalogReviewQuery::class)->forDepartment($this->term->id, $this->department->id)->pluck('catalog')->all())
            ->toBe([Catalog::Subjects]);
    });
});

describe('validación anterior', function () {
    function reviewInTermStarting(string $startsOn, array $attributes = [], string $state = 'validated'): CatalogReview
    {
        $factory = CatalogReview::factory()->general(Catalog::Careers);

        return ($state === 'validated' ? $factory->validated() : $factory->inReview())->create([
            'term_id' => Term::factory()->create(['starts_on' => $startsOn, 'ends_on' => $startsOn])->id,
            'department_id' => test()->department->id,
            'snapshot' => [],
            ...$attributes,
        ]);
    }

    it('es la última validada con foto del mismo departamento y catálogo en un periodo que empieza antes', function () {
        $current = reviewInTermStarting('2027-01-18', state: 'in_review');
        reviewInTermStarting('2026-01-18');
        $latest = reviewInTermStarting('2026-08-18');
        reviewInTermStarting('2026-10-01', state: 'in_review');
        reviewInTermStarting('2026-11-01', ['department_id' => Department::factory()->create()->id]);
        reviewInTermStarting('2026-12-01', ['catalog' => Catalog::Subjects]);
        reviewInTermStarting('2026-12-15', ['snapshot' => null]);
        reviewInTermStarting('2027-08-18');

        expect(app(CatalogReviewQuery::class)->previousValidation($current)?->is($latest))->toBeTrue();
    });

    it('en el primer periodo no hay', function () {
        expect(app(CatalogReviewQuery::class)->previousValidation(reviewInTermStarting('2027-01-18')))->toBeNull();
    });
});

describe('resumen', function () {
    it('cuenta activos e inactivos y da las altas y bajas contra la foto anterior', function () {
        $kept = Career::factory()->create(['code' => 'ISC', 'name' => 'Sistemas', 'department_id' => $this->department->id]);
        $new = Career::factory()->create(['code' => 'IIA', 'name' => 'Inteligencia', 'department_id' => $this->department->id]);
        $deactivated = Career::factory()->inactive()->create(['code' => 'IEL', 'name' => 'Electrónica', 'department_id' => $this->department->id]);
        $previousTerm = Term::factory()->create(['starts_on' => '2020-01-20', 'ends_on' => '2020-06-10']);
        CatalogReview::factory()->general(Catalog::Careers)->validated()->create([
            'term_id' => $previousTerm->id,
            'department_id' => $this->department->id,
            'snapshot' => [
                ['id' => $kept->id, 'code' => 'ISC', 'name' => 'Sistemas'],
                ['id' => $deactivated->id, 'code' => 'IEL', 'name' => 'Electrónica'],
                ['id' => fakeKey(99), 'code' => 'IND', 'name' => 'Industrial'],
            ],
        ]);
        $review = CatalogReview::factory()->general(Catalog::Careers)->create(['term_id' => $this->term->id, 'department_id' => $this->department->id]);

        expect(app(CatalogReviewQuery::class)->summary($review))->toBe([
            'active' => 2,
            'inactive' => 1,
            'added' => [['id' => $new->id, 'code' => 'IIA', 'name' => 'Inteligencia']],
            'removed' => [['id' => $deactivated->id, 'code' => 'IEL', 'name' => 'Electrónica'], ['id' => fakeKey(99), 'code' => 'IND', 'name' => 'Industrial']],
            'open_flags' => 0,
        ]);
    });

    it('sin foto anterior no da altas ni bajas, y cuenta solo las observaciones abiertas', function () {
        $career = Career::factory()->create(['department_id' => $this->department->id]);
        $review = CatalogReview::factory()->general(Catalog::Careers)->create(['term_id' => $this->term->id, 'department_id' => $this->department->id]);
        CatalogReviewFlag::factory()->for($review, 'review')->on($career)->count(2)->create();
        CatalogReviewFlag::factory()->for($review, 'review')->on($career)->resolved()->create();

        expect(app(CatalogReviewQuery::class)->summary($review))->toBe([
            'active' => 1,
            'inactive' => 0,
            'added' => null,
            'removed' => null,
            'open_flags' => 2,
        ]);
    });
});
