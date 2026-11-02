<?php

use App\Modules\Academic\Models\Department;
use App\Modules\CatalogValidation\Enums\Catalog;
use App\Modules\CatalogValidation\Models\CatalogReview;
use App\Modules\CatalogValidation\Queries\CatalogReviewQuery;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\CampusProfile;
use App\Modules\Core\Models\Term;

/*
| CatalogReviewQuery::pendingFor: los catálogos que bloquean HC-14 para la carga de un departamento en un
| campus y un periodo, en el orden del enum.
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
    CatalogReview::factory()->rooms($this->campus)->validated()->create(['term_id' => $this->term->id, 'department_id' => $this->department->id]);
    CatalogReview::factory()->general(Catalog::Careers)->inReview()->create(['term_id' => $this->term->id, 'department_id' => $this->department->id]);

    expect(app(CatalogReviewQuery::class)->pendingFor($this->term->id, $this->campus, $this->department->id))
        ->toBe([Catalog::Subjects, Catalog::StudyPlans, Catalog::Careers]);
});

it('no pide salones si el perfil del campus no los usa', function () {
    $campus = Campus::factory()->create(['settings' => [...CampusProfile::DEFAULTS, 'uses_rooms' => false]]);

    expect(app(CatalogReviewQuery::class)->pendingFor($this->term, $campus, $this->department))
        ->not->toContain(Catalog::Rooms);
});

it('no cuenta las revisiones de otro periodo, otro departamento ni los salones de otro campus', function () {
    foreach (Catalog::general() as $catalog) {
        CatalogReview::factory()->general($catalog)->validated()->create(['term_id' => Term::factory()->create()->id, 'department_id' => $this->department->id]);
        CatalogReview::factory()->general($catalog)->validated()->create(['term_id' => $this->term->id, 'department_id' => Department::factory()->create()->id]);
    }
    CatalogReview::factory()->rooms()->validated()->create(['term_id' => $this->term->id, 'department_id' => $this->department->id]);

    expect(app(CatalogReviewQuery::class)->pendingFor($this->term, $this->campus, $this->department))->toHaveCount(5);
});
