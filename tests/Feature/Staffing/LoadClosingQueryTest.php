<?php

use App\Modules\Academic\Models\Department;
use App\Modules\AcademicLoad\Enums\AgreementStatus;
use App\Modules\AcademicLoad\Enums\LoadStatus;
use App\Modules\AcademicLoad\Models\AcademicLoad;
use App\Modules\AcademicLoad\Models\InterdepartmentalAgreement;
use App\Modules\Core\Authorization\Actor;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\ModalityProfile;
use App\Modules\Core\Models\User;
use App\Modules\Scheduling\Models\GroupSession;
use App\Modules\Staffing\Models\ChangeRequest;
use App\Modules\Staffing\Queries\LoadClosing;
use App\Modules\Staffing\Queries\LoadClosingQuery;
use Tests\Support\Staffing\ClosingWorld;

/*
| LoadClosingQuery (tareas 4.1 y 4.2): lo que le falta a una carga para cerrar, con el validador al momento y contado
| por grupo como el veto de HC-15, más lo que la detiene fuera de los grupos (solicitudes de cambio y acuerdos), los
| grupos que se justifican, el historial y las cargas del departamento en cada campus.
*/

beforeEach(function () {
    $this->world = ClosingWorld::create();
    $this->query = app(LoadClosingQuery::class);
});

function loadClosingOf(?AcademicLoad $load = null): LoadClosing
{
    return app(LoadClosingQuery::class)->of(($load ?? test()->world->load)->fresh());
}

/**
 * @return array<string, int>
 */
function loadClosingCounts(LoadClosing $closing): array
{
    return collect($closing->conditions())->mapWithKeys(fn (array $row): array => [$row['key'] => $row['count']])->all();
}

it('una carga completa no tiene faltantes y está lista para cerrar', function () {
    $this->world->group();
    $this->world->group();

    $closing = loadClosingOf();

    expect($closing->activeGroups)->toBe(2)
        ->and($closing->missing())->toBe(0)
        ->and($closing->isReady())->toBeTrue()
        ->and($closing->items)->toBe([]);
});

it('HC-15: cuenta cada faltante por grupo, con el choque duro del validador al momento', function () {
    $this->world->group();
    $this->world->group(teacher: false);
    $this->world->group(scheduled: false);
    $this->world->group(withRoom: false);
    $clashing = $this->world->group();
    $clashing->sessions()->update(['room_id' => $this->world->room(capacity: 20)->id]);

    $closing = loadClosingOf();

    expect(loadClosingCounts($closing))->toBe([
        'conflicted' => 1,
        'unscheduled' => 1,
        'teacher' => 1,
        'room' => 1,
        'inactive' => 0,
        'requests' => 0,
        'shared' => 0,
        'awaiting' => 0,
    ])
        ->and($closing->missing())->toBe(4)
        ->and($closing->hasHardConflicts())->toBeTrue()
        ->and($closing->isReady())->toBeFalse()
        ->and(collect($closing->conditions())->firstWhere('key', 'room')['detail'])->toBe('1 sesión presencial');
});

it('«Faltan» cuenta grupos distintos: un grupo sin docente ni salón cuenta una vez; los trámites van aparte', function () {
    $this->world->group();
    $this->world->group(teacher: false, withRoom: false);
    $this->world->group(teacher: false);
    ChangeRequest::factory()->forGroup($this->world->group())->pending()->create();

    $closing = loadClosingOf();

    expect(loadClosingCounts($closing))->toMatchArray(['teacher' => 2, 'room' => 1, 'requests' => 1])
        ->and($closing->missingGroups())->toBe(2)
        ->and($closing->procedures())->toBe(1)
        ->and($closing->missing())->toBe(3)
        ->and($closing->missingLabel())->toBe('Faltan 2 grupos y 1 trámite')
        ->and($closing->headline()['title'])->toBe('Faltan 2 grupos y 1 trámite para cerrar');
});

it('dice lo que falta en grupos y trámites, en singular o plural', function (int $groups, int $procedures, string $label) {
    expect(LoadClosing::missingPhrase($groups, $procedures))->toBe($label);
})->with([
    [1, 0, 'Falta 1 grupo'],
    [255, 0, 'Faltan 255 grupos'],
    [0, 1, 'Falta 1 trámite'],
    [0, 3, 'Faltan 3 trámites'],
    [255, 3, 'Faltan 255 grupos y 3 trámites'],
    [1, 1, 'Faltan 1 grupo y 1 trámite'],
]);

it('un grupo justificado no cuenta como faltante y queda con quién y cuándo', function () {
    $jefe = $this->world->head();
    $pending = $this->world->group(teacher: false);
    $justified = $this->world->justify($this->world->group(teacher: false), 'Contratación en trámite', $jefe, now()->setDate(2026, 11, 28)->setTime(16, 5));
    $roomJustified = $this->world->justify($this->world->group(withRoom: false), 'El laboratorio se entrega en enero', $jefe);

    $closing = loadClosingOf();
    $items = collect($closing->items)->keyBy('code');

    expect(loadClosingCounts($closing)['teacher'])->toBe(1)
        ->and(loadClosingCounts($closing)['room'])->toBe(0)
        ->and(collect($closing->conditions())->firstWhere('key', 'teacher')['detail'])->toBe('1 justificado')
        ->and(collect($closing->conditions())->firstWhere('key', 'room')['detail'])->toBe('1 justificado')
        ->and($closing->justifiedCount())->toBe(2)
        ->and($closing->pendingJustifications())->toBe(1)
        ->and($items[$pending->code])->toMatchArray(['lacks_teacher' => true, 'justification' => null, 'justified_by' => null])
        ->and($items[$justified->code])->toMatchArray(['lacks_teacher' => true, 'justification' => 'Contratación en trámite', 'justified_by' => 'Laura Ramos', 'subject_code' => 'SCD-1027', 'schedule' => 'L 7:00–9:00'])
        ->and($items[$justified->code]['justified_at']->format('d/m/Y H:i'))->toBe('28/11/2026 16:05')
        ->and($items[$roomJustified->code])->toMatchArray(['lacks_teacher' => false, 'roomless_sessions' => 1]);
});

it('P10: un grupo con docente dado de baja cuenta aparte, con su nombre, y no se justifica', function () {
    $this->world->group(teacher: $this->world->teacher(active: false, name: 'Ernesto Villalobos Ruiz'));

    $closing = loadClosingOf();

    expect(loadClosingCounts($closing)['inactive'])->toBe(1)
        ->and(collect($closing->conditions())->firstWhere('key', 'inactive')['detail'])->toBe('Ernesto Villalobos Ruiz')
        ->and($closing->items)->toBe([]);
});

it('cuenta las solicitudes de cambio pendientes y aprobadas sin aplicar del departamento en el campus', function () {
    $group = $this->world->group();
    ChangeRequest::factory()->forGroup($group)->pending()->count(2)->create();
    ChangeRequest::factory()->forGroup($group)->approved()->create();
    ChangeRequest::factory()->forGroup($group)->rejected()->create();
    ChangeRequest::factory()->forGroup($group)->applied()->create();
    // M1: la de un grupo de otro campus de la misma modalidad es de la misma carga; la de otra modalidad, no.
    ChangeRequest::factory()->forGroup($this->world->group(load: $this->world->loadIn(Campus::factory()->create(['modality_id' => modalityOf('EAD')->id]))))->pending()->create();

    $requests = collect(loadClosingOf()->conditions())->firstWhere('key', 'requests');

    expect($requests['count'])->toBe(3)
        ->and($requests['detail'])->toBe('2 por decidir · 1 aprobada sin aplicar');
});

it('cuenta los grupos compartidos que propuso sin respuesta y los acuerdos que esperan su respuesta', function () {
    $other = Department::factory()->create(['name' => 'Ciencias Económico-Administrativas']);
    $base = ['term_id' => $this->world->term->id, 'campus_id' => $this->world->campus->id];
    InterdepartmentalAgreement::factory()->sharedGroup()->create([...$base, 'from_department_id' => $this->world->department->id, 'to_department_id' => $other->id]);
    InterdepartmentalAgreement::factory()->create([...$base, 'from_department_id' => $other->id, 'to_department_id' => $this->world->department->id]);
    InterdepartmentalAgreement::factory()->sharedGroup()->create([...$base, 'from_department_id' => $other->id, 'to_department_id' => $this->world->department->id]);
    InterdepartmentalAgreement::factory()->sharedGroup()->inStatus(AgreementStatus::Accepted)->create([...$base, 'from_department_id' => $this->world->department->id, 'to_department_id' => $other->id]);

    $conditions = collect(loadClosingOf()->conditions())->keyBy('key');

    expect($conditions['shared'])->toMatchArray(['count' => 1, 'detail' => 'Espera a Ciencias Económico-Administrativas', 'screen' => 'agreements'])
        ->and($conditions['awaiting'])->toMatchArray(['count' => 2, 'detail' => '1 préstamo de salón · 1 grupo compartido']);
});

it('sin bloques de tiempo ni salones, no pide horario ni salón', function () {
    $this->world->campus->catalogModality->update(['settings' => [...ModalityProfile::DEFAULTS, 'uses_rooms' => false, 'uses_time_blocks' => false]]);
    $this->world->group(scheduled: false);

    expect(array_column(loadClosingOf()->conditions(), 'key'))->not->toContain('unscheduled', 'room');
});

it('una carga publicada o en borrador no calcula lo que falta', function (LoadStatus $status) {
    $this->world->load->update(['status' => $status]);
    $this->world->group(teacher: false);

    $closing = loadClosingOf();

    expect($closing->blockers)->toBeNull()
        ->and($closing->conditions())->toBe([])
        ->and($closing->isReady())->toBeFalse();
})->with([LoadStatus::Draft, LoadStatus::Published]);

it('cuenta solo los grupos activos de la carga', function () {
    $this->world->group();
    $this->world->group(['status' => 'cancelled']);
    $this->world->group(load: $this->world->loadIn($this->world->campus, department: Department::factory()->create()));

    expect(loadClosingOf()->activeGroups)->toBe(1);
});

it('da el historial con nombres, del más reciente al más antiguo', function () {
    $jefe = $this->world->head();
    $subdireccion = User::factory()->withRole(Role::Subdireccion)->create(['name' => 'Héctor Salinas']);
    $this->world->transition(LoadStatus::Proposed, LoadStatus::Staffing, $jefe, at: now()->subDays(5));
    $this->world->transition(LoadStatus::Staffing, LoadStatus::Closed, $jefe, 'Todo asignado', now()->subDays(3));
    $this->world->transition(LoadStatus::Closed, LoadStatus::Adjusting, $subdireccion, 'Falta el grupo vespertino', now()->subDay());

    $history = loadClosingOf()->history;

    expect(array_map(fn (array $entry): string => LoadClosing::stepTitle($entry).' · '.$entry['by'], $history))->toBe([
        'Reabierta · Héctor Salinas',
        'Cerrada · Laura Ramos',
        'Pasó a Asignación · Laura Ramos',
    ])->and($history[0]['comment'])->toBe('Falta el grupo vespertino');
});

it('da las cargas del departamento con grupos, una por modalidad con los grupos de todos sus campus, y deja fuera la que no tiene grupos (M1)', function () {
    $c1 = Campus::factory()->create(['code' => 'C1', 'name' => 'Campus I']);
    $ead = Campus::factory()->create(['code' => 'C3', 'name' => 'Campus III', 'modality_id' => modalityOf('EAD')->id]);
    $enl = Campus::factory()->create(['code' => 'C4', 'name' => 'Campus IV', 'modality_id' => modalityOf('ENL')->id]);
    $published = $this->world->loadIn($ead, LoadStatus::Published);
    $this->world->loadIn($enl, LoadStatus::Draft);
    $this->world->group();
    $this->world->group(load: $this->world->loadIn($c1));
    $this->world->group(load: $published);
    $this->world->group(load: $this->world->loadIn($c1, department: Department::factory()->create()));

    $loads = $this->query->loadsOf($this->world->term->id, $this->world->department->id);

    expect($loads->map(fn (AcademicLoad $load): string => "{$load->modality->code}:{$load->status->value}:{$load->active_groups}")->all())
        ->toBe(['EAD:published:1', 'PRES:staffing:2']);
});

it('el jefe ve el cierre de su departamento; Subdirección y el admin, de todos, y se sabe cuáles tienen grupos', function () {
    $other = Department::factory()->create(['code' => 'CB', 'name' => 'Ciencias Básicas']);
    $empty = Department::factory()->create(['code' => 'LO']);
    $this->world->group();
    $this->world->group(load: $this->world->loadIn($this->world->campus, department: $other));
    $this->world->loadIn($this->world->campus, LoadStatus::Draft, $empty);

    $codesFor = fn (User $user): array => $this->query->departmentsFor(app(Actor::class)->forUser($user), $this->world->term->id)
        ->mapWithKeys(fn (Department $department): array => [$department->code => $department->has_groups])->all();

    expect($codesFor($this->world->head()))->toBe(['SC' => true])
        ->and($codesFor(User::factory()->withRole(Role::Subdireccion)->create()))->toBe(['CB' => true, 'LO' => false, 'SC' => true])
        ->and($codesFor(User::factory()->admin()->create()))->toBe(['CB' => true, 'LO' => false, 'SC' => true])
        ->and($codesFor(User::factory()->withRole(Role::JefeProyectoDocente, ['campus_id' => $this->world->campus->id, 'department_id' => $this->world->department->id])->create()))->toBe([]);
});

it('la línea de estados marca un paso actual en cualquier estado, con o sin visto bueno de Subdirección', function (LoadStatus $status, bool $approval) {
    $this->world->campus->catalogModality->update(['settings' => [...ModalityProfile::DEFAULTS, 'requires_subdireccion_approval' => $approval]]);
    $this->world->load->update(['status' => $status]);

    $current = array_values(array_filter(loadClosingOf()->steps(), fn (array $step): bool => $step['state'] === 'current'));

    expect($current)->toHaveCount(1)
        ->and($current[0]['status'])->toBe($status === LoadStatus::Reanalysis ? LoadStatus::Published : $status);
})->with(LoadStatus::cases())->with(['con visto bueno' => true, 'sin visto bueno' => false]);

it('una carga con visto bueno en un campus que no lo exige tiene su paso en la línea, entre Cerrada y Publicada', function () {
    $this->world->load->update(['status' => LoadStatus::Approved]);
    $this->world->group();

    expect(array_map(fn (array $step): string => "{$step['status']->value}:{$step['state']}", loadClosingOf()->steps()))->toBe([
        'draft:past', 'proposed:past', 'staffing:past', 'adjusting:skipped', 'closed:past', 'approved:current', 'published:next',
    ]);
});

it('una carga en asignación sin grupos activos no está lista para cerrar: la línea dice que no tiene grupos', function () {
    $this->world->group(['status' => 'cancelled']);

    $closing = loadClosingOf();

    expect($closing->isReady())->toBeFalse()
        ->and($closing->headline())->toMatchArray(['tone' => 'neutral', 'title' => 'Sin grupos']);
});

it('trae el departamento de la carga aunque esté dado de baja', function () {
    $this->world->group();
    $this->world->department->delete();

    expect($this->query->findOrFail($this->world->load->id)->department?->name)->toBe('Sistemas y Computación')
        ->and($this->query->loadsOf($this->world->term->id, $this->world->department->id)->first()->department?->name)->toBe('Sistemas y Computación');
});

it('una sesión virtual sin salón no falta', function () {
    $group = $this->world->group(scheduled: false);
    GroupSession::factory()->for($group)->virtual()->on(2, '07:00', '09:00')->create();

    expect(loadClosingOf()->missing())->toBe(0);
});
