<?php

use App\Modules\Academic\Models\Department;
use App\Modules\AcademicLoad\Enums\LoadStatus;
use App\Modules\AcademicLoad\Models\AcademicLoad;
use App\Modules\CatalogValidation\Enums\Catalog;
use App\Modules\CatalogValidation\Enums\ReviewStatus;
use App\Modules\CatalogValidation\Models\CatalogReview;
use App\Modules\CatalogValidation\Queries\CatalogReviewQuery;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Term;
use Spatie\Activitylog\Models\Activity;

/*
| CatalogReviewQuery::reopeningVetoes: lo que la pantalla de validación necesita para deshabilitar «Reabrir» con su
| motivo (CV5) sin que CatalogValidation dependa de AcademicLoad: despacha CatalogReviewReopening sin transacción
| y devuelve los motivos del veto. No escribe nada.
*/

it('CV5: devuelve el motivo del veto si una carga del departamento ya salió de borrador, sin escribir nada', function () {
    $term = Term::factory()->create();
    $department = Department::factory()->create(['code' => 'SIS']);
    $campus = Campus::factory()->create(['code' => 'C2']);
    AcademicLoad::factory()->create(['term_id' => $term->id, 'modality_id' => modalityOf('PRES')->id, 'department_id' => $department->id, 'status' => LoadStatus::Proposed]);
    $review = CatalogReview::factory()->general(Catalog::Subjects)->validated()->create(['term_id' => $term->id, 'department_id' => $department->id]);
    $activities = Activity::query()->count();

    expect(app(CatalogReviewQuery::class)->reopeningVetoes($review))->toBe(['La carga académica de SIS ya salió de borrador en Presencial.'])
        ->and($review->fresh()->status)->toBe(ReviewStatus::Validated)
        ->and(Activity::query()->count())->toBe($activities);
});

it('CV5: sin cargas fuera de borrador no hay motivos', function () {
    $term = Term::factory()->create();
    $department = Department::factory()->create();
    AcademicLoad::factory()->create(['term_id' => $term->id, 'modality_id' => modalityOf('PRES')->id, 'department_id' => $department->id, 'status' => LoadStatus::Draft]);
    $review = CatalogReview::factory()->general(Catalog::Careers)->validated()->create(['term_id' => $term->id, 'department_id' => $department->id]);

    expect(app(CatalogReviewQuery::class)->reopeningVetoes($review))->toBe([]);
});
