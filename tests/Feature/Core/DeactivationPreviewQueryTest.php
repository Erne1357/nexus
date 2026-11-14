<?php

use App\Modules\Academic\Models\Career;
use App\Modules\Academic\Models\CareerCampus;
use App\Modules\Academic\Models\Department;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\RoleAssignment;
use App\Modules\Core\Models\User;
use App\Modules\Core\Queries\DeactivationPreviewQuery;
use App\Modules\Core\Queries\UnitAdscriptions;
use App\Modules\Core\Rules\UserAccountRules;

/*
| DeactivationPreviewQuery, lo que el cajón «Dar de baja» muestra antes de confirmar (CF-05, CF-09): los nombramientos
| que terminarían hoy o se quitarían, cuáles son de un puesto exclusivo que se quedaría sin titular (AZ-S7), si también
| es docente y en qué departamentos, y si sería el último admin (AZ-S12). El de docente y el de alumno no se listan:
| la baja no los toca.
*/

beforeEach(function () {
    $this->travelTo('2026-11-14 10:00:00');

    $this->c2 = Campus::factory()->create(['code' => 'C2', 'name' => 'Campus II']);
    $this->isc = Career::factory()->create(['code' => 'ISC']);
    CareerCampus::factory()->create(['career_id' => $this->isc->id, 'campus_id' => $this->c2->id]);
    $this->systems = Department::factory()->create(['code' => 'SC', 'name' => 'Sistemas y Computación']);
    $this->industrial = Department::factory()->create(['code' => 'II', 'name' => 'Ingeniería Industrial']);

    $this->admin = User::factory()->admin()->create(['name' => 'Ada Admin']);
    $this->rosa = User::factory()->create(['name' => 'Rosa Ruiz']);
});

/**
 * Las adscripciones que Faculty le daría a Core: `$byUnit` es id de unidad → ids de usuario.
 *
 * @param  array<string, list<string>>  $byUnit
 */
function fakeAdscriptions(array $byUnit): void
{
    app()->instance(UnitAdscriptions::class, new readonly class($byUnit) implements UnitAdscriptions
    {
        /**
         * @param  array<string, list<string>>  $byUnit
         */
        public function __construct(private array $byUnit) {}

        public function userIdsByUnit(): array
        {
            return $this->byUnit;
        }
    });
}

function previewFor(User $person, ?User $actor = null)
{
    return app(DeactivationPreviewQuery::class)->for($person, $actor ?? test()->admin);
}

it('CF-05: lista lo que terminaría hoy y lo que se quitaría, y marca el puesto exclusivo que se queda sin titular', function () {
    RoleAssignment::factory()->create(['user_id' => $this->rosa->id, 'role' => Role::Coordinador, 'campus_id' => $this->c2->id, 'career_id' => $this->isc->id, 'valid_from' => '2026-08-01']);
    RoleAssignment::factory()->create(['user_id' => $this->rosa->id, 'role' => Role::JefeDepartamento, 'org_unit_id' => $this->systems->id, 'valid_from' => '2026-12-01']);
    RoleAssignment::factory()->create(['user_id' => $this->rosa->id, 'role' => Role::Coordinador, 'campus_id' => $this->c2->id, 'career_id' => $this->isc->id, 'valid_from' => '2026-01-01', 'valid_to' => '2026-06-30']);

    $preview = previewFor($this->rosa);

    expect($preview->blocker)->toBeNull()
        ->and($preview->adminGap)->toBeNull()
        ->and($preview->assignments)->toBe([
            ['summary' => 'Coordinador de carrera · ISC · Presencial · Campus II', 'removed' => false, 'vacates' => false],
            ['summary' => 'Jefe de departamento académico · Sistemas y Computación', 'removed' => true, 'vacates' => true],
        ])
        ->and($preview->vacatedSeats())->toBe(['Jefe de departamento académico · Sistemas y Computación']);
});

it('CF-09: si también es docente, lo dice con sus departamentos y no lista su nombramiento de docente', function () {
    RoleAssignment::factory()->create(['user_id' => $this->rosa->id, 'role' => Role::Docente, 'valid_from' => '2026-08-01']);
    fakeAdscriptions([$this->systems->id => [$this->rosa->id, $this->admin->id], $this->industrial->id => [$this->rosa->id]]);

    $preview = previewFor($this->rosa);

    expect($preview->isTeacher())->toBeTrue()
        ->and($preview->assignments)->toBe([])
        ->and($preview->teacherDepartments)->toBe([['code' => 'II', 'name' => 'Ingeniería Industrial'], ['code' => 'SC', 'name' => 'Sistemas y Computación']])
        ->and($preview->teacherNotice())->toBe('Sigue en el catálogo de docentes. Para darlo de baja como docente, usa «Quitar de II» y «Quitar de SC» en Docentes.');
});

it('CF-09: un docente sin departamento también sigue en el catálogo', function () {
    RoleAssignment::factory()->create(['user_id' => $this->rosa->id, 'role' => Role::Docente, 'valid_from' => '2026-08-01']);

    $preview = previewFor($this->rosa);

    expect($preview->teacherDepartments)->toBe([])
        ->and($preview->teacherNotice())->toBe('Sigue en el catálogo de docentes. Para darlo de baja como docente, usa Docentes.');
});

it('quien no es docente no lleva el aviso', function () {
    fakeAdscriptions([$this->systems->id => [$this->admin->id]]);

    expect(previewFor($this->rosa)->isTeacher())->toBeFalse()
        ->and(previewFor($this->rosa)->teacherNotice())->toBeNull();
});

it('AZ-S12: dice si sería el último admin, y eso bloquea la baja', function () {
    $actor = User::factory()->create();

    $preview = previewFor($this->admin, $actor);

    expect($preview->adminGap)->toBe('Es el último admin vigente hoy: nombra a otra persona antes de darlo de baja.')
        ->and($preview->wouldLeaveNoAdmin())->toBeTrue()
        ->and($preview->blocker)->toBe($preview->adminGap)
        ->and($preview->assignments)->toBe([['summary' => 'Administración', 'removed' => false, 'vacates' => false]]);
});

it('CF-05 y CF-S4: no se da de baja a uno mismo ni a un alumno', function () {
    User::factory()->admin()->create();
    $student = User::factory()->withRole(Role::Alumno)->create();

    expect(previewFor($this->admin)->blocker)->toBe(UserAccountRules::SELF)
        ->and(previewFor($student)->blocker)->toBe(UserAccountRules::STUDENT);
});
