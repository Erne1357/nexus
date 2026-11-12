<?php

use App\Modules\Academic\Models\Department;
use App\Modules\AcademicLoad\Actions\Transitions\ProposeLoad;
use App\Modules\AcademicLoad\Enums\LoadStatus;
use App\Modules\AcademicLoad\Models\AcademicLoad;
use App\Modules\AcademicLoad\Models\CareerProposal;
use App\Modules\AcademicLoad\Models\ProposalReview;
use App\Modules\CatalogValidation\Enums\Catalog;
use App\Modules\CatalogValidation\Models\CatalogReview;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\CampusProfile;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/*
| ProposeLoad pasa la carga de borrador a propuesta con dos guardas: HC-14 (como la reescribe D2), los
| cuatro catálogos generales del departamento validados en el periodo y, si el campus usa salones, los
| salones del departamento en el campus; y HC-20, al menos una parte aceptada del departamento en una
| propuesta de carrera del periodo y campus (supuesto por confirmar).
*/

function loadWith(?Campus $campus = null): AcademicLoad
{
    return AcademicLoad::factory()->create([
        'term_id' => Term::factory()->create()->id,
        'campus_id' => ($campus ?? Campus::factory()->create())->id,
        'department_id' => Department::factory()->create()->id,
    ]);
}

/**
 * Deja abiertos todos los catálogos del periodo de la carga (con revisiones pendientes de otro departamento): sin esto
 * ninguno se exige (HC-14, CV9).
 */
function openAllCatalogs(AcademicLoad $load): void
{
    $other = Department::factory()->create();

    foreach (Catalog::cases() as $catalog) {
        CatalogReview::factory()->create(['catalog' => $catalog, 'term_id' => $load->term_id, 'department_id' => $other->id]);
    }
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
    CatalogReview::factory()->rooms()->validated()->create(['term_id' => $load->term_id, 'department_id' => $load->department_id]);
}

/**
 * Una parte del departamento de la carga en una propuesta de carrera del mismo periodo y campus; aceptada, salvo
 * que se pida otro estado.
 *
 * @param  array<string, mixed>  $proposal
 */
function partFor(AcademicLoad $load, string $state = 'accepted', array $proposal = []): ProposalReview
{
    $factory = ProposalReview::factory();

    return ($state === 'pending' ? $factory : $factory->{$state}())->create([
        'career_proposal_id' => CareerProposal::factory()->submitted()->create(['term_id' => $load->term_id, 'campus_id' => $load->campus_id, ...$proposal])->id,
        'department_id' => $load->department_id,
    ]);
}

/**
 * Los motivos con los que ProposeLoad bloquea la carga, o ninguno si la propone.
 *
 * @return list<string>
 */
function blockedReasons(AcademicLoad $load): array
{
    try {
        app(ProposeLoad::class)->handle($load, test()->user);
    } catch (ValidationException $e) {
        return $e->errors()['status'];
    }

    return [];
}

function blockedReason(AcademicLoad $load): string
{
    return blockedReasons($load)[0] ?? '';
}

beforeEach(function () {
    $this->user = User::factory()->admin()->create();
    $this->actingAs($this->user);
});

it('HC-14 y HC-20: con los cuatro generales y los salones validados y una parte aceptada la carga pasa a propuesta', function () {
    $load = loadWith();
    validateGenerals($load);
    validateRooms($load);
    partFor($load);

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

it('HC-14 y CV9: sin ningún catálogo abierto en el periodo no bloquea', function () {
    expect(collect(blockedReasons(loadWith()))->contains(fn (string $reason): bool => str_starts_with($reason, 'HC-14')))->toBeFalse();
});

it('HC-14: bloquea si las revisiones del departamento no existen en un periodo con los catálogos abiertos y lista todos', function () {
    $load = loadWith();
    openAllCatalogs($load);

    expect(blockedReason($load))->toBe('HC-14: faltan por validar los catálogos de Docentes, Salones, Materias, Planes de estudio y Carreras.');
});

it('HC-14: bloquea si los salones están pendientes', function () {
    $load = loadWith();
    validateGenerals($load);
    CatalogReview::factory()->rooms()->inReview()->create(['term_id' => $load->term_id, 'department_id' => $load->department_id]);

    expect(blockedReason($load))->toBe('HC-14: faltan por validar los catálogos de Salones.');
});

it('HC-14: no exige salones si el perfil del campus no los usa', function () {
    $campus = Campus::factory()->create(['settings' => [...CampusProfile::DEFAULTS, 'uses_rooms' => false]]);
    $load = loadWith($campus);
    validateGenerals($load);
    partFor($load);

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

it('HC-14: la revisión de salones es una para todos los campus: validada, vale en el campus de la carga (decisión 6)', function () {
    $load = loadWith();
    validateGenerals($load);
    CatalogReview::factory()->rooms()->validated()->create(['term_id' => $load->term_id, 'department_id' => $load->department_id]);
    partFor($load);

    app(ProposeLoad::class)->handle($load, test()->user);

    expect($load->fresh()->status)->toBe(LoadStatus::Proposed);
});

it('HC-14: lee las revisiones con bloqueo compartido al proponer', function () {
    $load = loadWith();
    validateGenerals($load);
    validateRooms($load);
    partFor($load);
    $sql = [];
    DB::listen(function ($query) use (&$sql) {
        $sql[] = $query->sql;
    });

    app(ProposeLoad::class)->handle($load, test()->user);

    expect(collect($sql)->filter(fn (string $s) => str_contains($s, 'catalog_reviews') && str_contains($s, 'for share')))->toHaveCount(1);
});

it('HC-20: bloquea sin una parte aceptada del departamento y no escribe nada', function () {
    $load = loadWith();
    validateGenerals($load);
    validateRooms($load);

    expect(blockedReasons($load))->toBe(['HC-20: el departamento todavía no acepta la parte de ninguna propuesta de carrera.'])
        ->and($load->fresh()->status)->toBe(LoadStatus::Draft)
        ->and($load->transitions()->count())->toBe(0);
});

it('HC-20: una parte pendiente o regresada no cuenta', function (string $state) {
    $load = loadWith();
    validateGenerals($load);
    validateRooms($load);
    partFor($load, $state);

    expect(blockedReason($load))->toStartWith('HC-20:');
})->with(['pending', 'returned']);

it('HC-20: no cuenta la parte aceptada de otro departamento, de otro campus ni de otro periodo', function () {
    $load = loadWith();
    validateGenerals($load);
    validateRooms($load);
    ProposalReview::factory()->accepted()->create([
        'career_proposal_id' => CareerProposal::factory()->submitted()->create(['term_id' => $load->term_id, 'campus_id' => $load->campus_id])->id,
    ]);
    partFor($load, proposal: ['campus_id' => Campus::factory()->create()->id]);
    partFor($load, proposal: ['term_id' => Term::factory()->create()->id]);

    expect(blockedReason($load))->toStartWith('HC-20:');
});

it('HC-20: cuenta la parte aceptada aunque la propuesta esté regresada por otro departamento', function () {
    $load = loadWith();
    validateGenerals($load);
    validateRooms($load);
    partFor($load)->proposal->update(['status' => 'returned']);

    expect(blockedReasons($load))->toBe([])
        ->and($load->fresh()->status)->toBe(LoadStatus::Proposed);
});

it('HC-14 y HC-20: sin catálogos ni partes aceptadas da los dos motivos', function () {
    $load = loadWith();
    openAllCatalogs($load);

    expect(blockedReasons($load))->toBe([
        'HC-14: faltan por validar los catálogos de Docentes, Salones, Materias, Planes de estudio y Carreras.',
        'HC-20: el departamento todavía no acepta la parte de ninguna propuesta de carrera.',
    ]);
});

it('HC-20: lee las partes aceptadas con bloqueo compartido al proponer', function () {
    $load = loadWith();
    validateGenerals($load);
    validateRooms($load);
    partFor($load);
    $sql = [];
    DB::listen(function ($query) use (&$sql) {
        $sql[] = $query->sql;
    });

    app(ProposeLoad::class)->handle($load, test()->user);

    expect(collect($sql)->filter(fn (string $s) => str_contains($s, 'from "proposal_reviews"') && str_contains($s, 'for share')))->toHaveCount(1);
});
