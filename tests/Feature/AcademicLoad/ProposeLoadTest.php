<?php

use App\Modules\Academic\Models\Department;
use App\Modules\AcademicLoad\Actions\Transitions\ProposeLoad;
use App\Modules\AcademicLoad\Enums\LoadStatus;
use App\Modules\AcademicLoad\Models\AcademicLoad;
use App\Modules\CatalogValidation\Enums\Catalog;
use App\Modules\CatalogValidation\Models\CatalogReview;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\CampusProfile;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\User;
use Illuminate\Validation\ValidationException;

/*
| ProposeLoad pasa la carga de borrador a propuesta con la guarda de HC-14 (como la reescribe D2): los
| cuatro catálogos generales del departamento validados en el periodo y, si el campus usa salones, los
| salones del departamento en el campus.
*/

function loadWith(?Campus $campus = null): AcademicLoad
{
    return AcademicLoad::factory()->create([
        'term_id' => Term::factory()->create()->id,
        'campus_id' => ($campus ?? Campus::factory()->create())->id,
        'department_id' => Department::factory()->create()->id,
    ]);
}

function validateGenerals(AcademicLoad $load, array $except = []): void
{
    foreach (Catalog::general() as $catalog) {
        if (! in_array($catalog, $except, true)) {
            CatalogReview::factory()->general($catalog)->validated()->create(['term_id' => $load->term_id, 'department_id' => $load->department_id]);
        }
    }
}

function validateRooms(AcademicLoad $load): void
{
    CatalogReview::factory()->rooms($load->campus)->validated()->create(['term_id' => $load->term_id, 'department_id' => $load->department_id]);
}

function blockedReason(AcademicLoad $load): string
{
    try {
        app(ProposeLoad::class)->handle($load, test()->user);
    } catch (ValidationException $e) {
        return $e->errors()['status'][0];
    }

    return '';
}

beforeEach(function () {
    $this->user = User::factory()->admin()->create();
    $this->actingAs($this->user);
});

it('HC-14: con los cuatro generales y los salones validados la carga pasa a propuesta', function () {
    $load = loadWith();
    validateGenerals($load);
    validateRooms($load);

    app(ProposeLoad::class)->handle($load, test()->user, 'Adelante');

    expect($load->fresh()->status)->toBe(LoadStatus::Proposed)
        ->and($load->transitions()->firstOrFail()->comment)->toBe('Adelante');
});

it('HC-14: bloquea y nombra el catálogo general pendiente', function () {
    $load = loadWith();
    validateGenerals($load, [Catalog::Subjects]);
    CatalogReview::factory()->general(Catalog::Subjects)->create(['term_id' => $load->term_id, 'department_id' => $load->department_id]);
    validateRooms($load);

    expect(blockedReason($load))->toBe('HC-14: faltan por validar los catálogos de Materias.')
        ->and($load->fresh()->status)->toBe(LoadStatus::Draft)
        ->and($load->transitions()->count())->toBe(0);
});

it('HC-14: bloquea si las revisiones no existen y lista todos los catálogos', function () {
    expect(blockedReason(loadWith()))->toBe('HC-14: faltan por validar los catálogos de Docentes, Salones, Materias, Planes de estudio y Carreras.');
});

it('HC-14: bloquea si los salones están pendientes', function () {
    $load = loadWith();
    validateGenerals($load);
    CatalogReview::factory()->rooms($load->campus)->inReview()->create(['term_id' => $load->term_id, 'department_id' => $load->department_id]);

    expect(blockedReason($load))->toBe('HC-14: faltan por validar los catálogos de Salones.');
});

it('HC-14: no exige salones si el perfil del campus no los usa', function () {
    $campus = Campus::factory()->create(['settings' => [...CampusProfile::DEFAULTS, 'uses_rooms' => false]]);
    $load = loadWith($campus);
    validateGenerals($load);

    app(ProposeLoad::class)->handle($load, test()->user);

    expect($load->fresh()->status)->toBe(LoadStatus::Proposed);
});

it('HC-14: las revisiones generales de otro departamento no cuentan', function () {
    $load = loadWith();
    validateRooms($load);
    $other = AcademicLoad::factory()->create(['term_id' => $load->term_id, 'campus_id' => $load->campus_id]);
    validateGenerals($other);

    expect(blockedReason($load))->toBe('HC-14: faltan por validar los catálogos de Docentes, Materias, Planes de estudio y Carreras.');
});

it('HC-14: la revisión de salones de otro campus no cuenta', function () {
    $load = loadWith();
    validateGenerals($load);
    CatalogReview::factory()->rooms()->validated()->create(['term_id' => $load->term_id, 'department_id' => $load->department_id]);

    expect(blockedReason($load))->toBe('HC-14: faltan por validar los catálogos de Salones.');
});
