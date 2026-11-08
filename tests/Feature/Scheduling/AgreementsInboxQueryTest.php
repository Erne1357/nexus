<?php

use App\Modules\Academic\Models\Career;
use App\Modules\Academic\Models\Department;
use App\Modules\Academic\Models\Subject;
use App\Modules\AcademicLoad\Enums\AcademicLoadPermission;
use App\Modules\AcademicLoad\Enums\AgreementKind;
use App\Modules\AcademicLoad\Enums\AgreementStatus;
use App\Modules\AcademicLoad\Models\InterdepartmentalAgreement;
use App\Modules\Core\Authorization\Actor;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\User;
use App\Modules\Facilities\Models\Building;
use App\Modules\Facilities\Models\Room;
use App\Modules\Facilities\Models\RoomBlock;
use App\Modules\Scheduling\Models\Group;
use App\Modules\Scheduling\Models\GroupSession;
use App\Modules\Scheduling\Queries\AgreementsInboxQuery;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

/*
| AgreementsInboxQuery (tareas 3.6 y 3.7): la lista de la pantalla Acuerdos en tres grupos, con el alcance del actor
| por departamento y campus, filtros por tipo y texto, y el detalle de un préstamo con la semana del salón.
*/

beforeEach(function () {
    $this->term = Term::factory()->create();
    $this->campus = Campus::factory()->create();
    $this->sc = Department::factory()->create(['code' => 'SC', 'name' => 'Sistemas y Computación']);
    $this->ii = Department::factory()->create(['code' => 'II', 'name' => 'Ingeniería Industrial']);
    $this->cb = Department::factory()->create(['code' => 'CB', 'name' => 'Ciencias Básicas']);
    $this->building = Building::factory()->create(['campus_id' => $this->campus->id]);
});

function inboxOf(User $user, Term $term, Campus $campus, ?AgreementKind $kind = null, string $search = ''): array
{
    return app(AgreementsInboxQuery::class)->inbox(app(Actor::class)->forUser($user), $term, $campus, $kind, $search);
}

function loanOf(Department $from, Department $to, array $attributes = [], ?string $subjectCode = null): InterdepartmentalAgreement
{
    $campusId = $attributes['campus_id'] ?? test()->campus->id;
    $subject = Subject::factory()->create(['department_id' => $from->id, 'code' => $subjectCode ?? 'SCC-'.fake()->unique()->numberBetween(1000, 9999), 'name' => 'Redes']);
    $group = Group::factory()->create(['term_id' => $attributes['term_id'] ?? test()->term->id, 'campus_id' => $campusId, 'subject_id' => $subject->id, 'code' => '3A']);
    $room = Room::factory()->create(['building_id' => Building::factory()->create(['campus_id' => $campusId])->id, 'department_id' => $to->id, 'code' => 'LC-'.fake()->unique()->numberBetween(100, 999)]);

    $status = $attributes['status'] ?? AgreementStatus::Proposed;
    unset($attributes['status']);

    return InterdepartmentalAgreement::factory()->inStatus($status)->create([
        'term_id' => test()->term->id, 'campus_id' => $campusId, 'group_id' => $group->id, 'room_id' => $room->id,
        'from_department_id' => $from->id, 'to_department_id' => $to->id, ...$attributes,
    ]);
}

function shareOf(Department $from, Department $to, array $attributes = []): InterdepartmentalAgreement
{
    $status = $attributes['status'] ?? AgreementStatus::Proposed;
    unset($attributes['status']);

    return InterdepartmentalAgreement::factory()->sharedGroup(6)->inStatus($status)->create([
        'term_id' => test()->term->id, 'campus_id' => test()->campus->id,
        'from_department_id' => $from->id, 'to_department_id' => $to->id,
        'career_id' => Career::factory()->create(['code' => Career::query()->where('code', 'ISC')->exists() ? 'K'.fake()->unique()->numerify('###') : 'ISC', 'name' => 'Sistemas Computacionales', 'department_id' => $to->id])->id, ...$attributes,
    ]);
}

it('el jefe ve «Por decidir» lo dirigido a su departamento y «Enviados» lo que pidió, y los decididos de ambos lados', function () {
    $in = loanOf($this->ii, $this->sc);
    $out = shareOf($this->sc, $this->ii);
    $accepted = loanOf($this->ii, $this->sc, ['status' => AgreementStatus::Accepted, 'decided_at' => now()]);
    $rejected = shareOf($this->sc, $this->cb, ['status' => AgreementStatus::Rejected, 'comment' => 'No hay lugares.', 'decided_at' => now()]);
    $cancelled = loanOf($this->sc, $this->cb, ['status' => AgreementStatus::Cancelled, 'cancelled_at' => now()]);
    $unrelated = loanOf($this->ii, $this->cb);
    $jefe = User::factory()->withRole(Role::JefeDepartamento, ['campus_id' => $this->campus->id, 'department_id' => $this->sc->id])->create();

    $inbox = inboxOf($jefe, $this->term, $this->campus);
    $ids = fn (string $group): array => array_column($inbox[$group], 'id');

    expect($ids('decide'))->toBe([$in->id])
        ->and($ids('sent'))->toBe([$out->id])
        ->and($ids('done'))->toEqualCanonicalizing([$accepted->id, $rejected->id, $cancelled->id])
        ->and($ids('decide'))->not->toContain($unrelated->id);
});

it('trae por acuerdo el salón, las horas, el grupo, los departamentos, la carrera, los lugares y las personas', function () {
    $loan = loanOf($this->ii, $this->sc, ['day' => 3, 'starts_at' => '09:00', 'ends_at' => '11:00'], 'SCC-1013');
    $share = shareOf($this->ii, $this->sc);
    $jefe = User::factory()->withRole(Role::JefeDepartamento, ['campus_id' => $this->campus->id, 'department_id' => $this->sc->id])->create();

    $rows = collect(inboxOf($jefe, $this->term, $this->campus)['decide'])->keyBy('id');

    expect($rows[$loan->id])->toMatchArray([
        'kind' => AgreementKind::RoomLoan, 'status' => AgreementStatus::Proposed, 'day' => 3, 'starts_at' => '09:00', 'ends_at' => '11:00',
        'requested_by' => $loan->requester->name,
    ])
        ->and($rows[$loan->id]['room'])->toBe($loan->room->code)
        ->and($rows[$loan->id]['group'])->toMatchArray(['subject_code' => 'SCC-1013', 'subject_name' => 'Redes', 'code' => '3A', 'label' => 'SCC-1013 3A'])
        ->and($rows[$loan->id]['from'])->toMatchArray(['code' => 'II', 'name' => 'Ingeniería Industrial'])
        ->and($rows[$loan->id]['to'])->toMatchArray(['code' => 'SC', 'name' => 'Sistemas y Computación'])
        ->and($rows[$share->id])->toMatchArray(['seats' => 6, 'career' => ['code' => 'ISC', 'name' => 'Sistemas Computacionales'], 'room' => null]);
});

it('los decididos traen quién decidió o canceló, cuándo y el motivo', function () {
    $decider = User::factory()->create(['name' => 'Jefe de CB']);
    $rejected = shareOf($this->sc, $this->cb, ['status' => AgreementStatus::Rejected, 'comment' => 'No hay lugares.', 'decided_by' => $decider->id, 'decided_at' => now()]);
    $jefe = User::factory()->withRole(Role::JefeDepartamento, ['campus_id' => $this->campus->id, 'department_id' => $this->sc->id])->create();

    expect(inboxOf($jefe, $this->term, $this->campus)['done'][0])->toMatchArray(['id' => $rejected->id, 'decided_by' => 'Jefe de CB', 'comment' => 'No hay lugares.'])
        ->and(inboxOf($jefe, $this->term, $this->campus)['done'][0]['decided_at'])->not->toBeNull();
});

it('la Subdirección ve todos del campus y los propuestos van a «Enviados»; el admin igual', function () {
    $a = loanOf($this->ii, $this->sc);
    $b = shareOf($this->cb, $this->ii, ['status' => AgreementStatus::Accepted, 'decided_at' => now()]);
    $subdireccion = User::factory()->withRole(Role::Subdireccion)->create();

    $inbox = inboxOf($subdireccion, $this->term, $this->campus);

    expect($inbox['decide'])->toBe([])
        ->and(array_column($inbox['sent'], 'id'))->toBe([$a->id])
        ->and(array_column($inbox['done'], 'id'))->toBe([$b->id]);
});

it('no ve acuerdos de otro campus ni de otro periodo; coordinador y docente no ven ninguno', function () {
    loanOf($this->ii, $this->sc, ['campus_id' => Campus::factory()->create()->id]);
    InterdepartmentalAgreement::factory()->create([
        'term_id' => Term::factory()->create()->id, 'campus_id' => $this->campus->id,
        'from_department_id' => $this->ii->id, 'to_department_id' => $this->sc->id,
    ]);
    $jefe = User::factory()->withRole(Role::JefeDepartamento, ['campus_id' => $this->campus->id, 'department_id' => $this->sc->id])->create();
    $coordinador = User::factory()->withRole(Role::Coordinador, ['campus_id' => $this->campus->id, 'career_id' => careerKey(1)])->create();
    $docente = User::factory()->withRole(Role::Docente)->create();

    expect(array_merge(...array_values(inboxOf($jefe, $this->term, $this->campus))))->toBe([])
        ->and(array_merge(...array_values(inboxOf($coordinador, $this->term, $this->campus))))->toBe([])
        ->and(array_merge(...array_values(inboxOf($docente, $this->term, $this->campus))))->toBe([]);
});

it('filtra por tipo y por texto: salón, grupo, materia, departamento y carrera', function () {
    $loan = loanOf($this->ii, $this->sc, [], 'SCC-1013');
    $share = shareOf($this->cb, $this->sc);
    $jefe = User::factory()->withRole(Role::JefeDepartamento, ['campus_id' => $this->campus->id, 'department_id' => $this->sc->id])->create();
    $ids = fn (?AgreementKind $kind, string $search): array => array_column(inboxOf($jefe, $this->term, $this->campus, $kind, $search)['decide'], 'id');

    expect($ids(AgreementKind::RoomLoan, ''))->toBe([$loan->id])
        ->and($ids(AgreementKind::SharedGroup, ''))->toBe([$share->id])
        ->and($ids(null, $loan->room->code))->toBe([$loan->id])
        ->and($ids(null, 'scc-1013'))->toBe([$loan->id])
        ->and($ids(null, 'Redes'))->toBe([$loan->id])
        ->and($ids(null, 'Industrial'))->toBe([$loan->id])
        ->and($ids(null, 'isc'))->toBe([$share->id])
        ->and($ids(null, 'no existe'))->toBe([]);
});

it('ordena del más reciente al más antiguo y no hace una consulta por acuerdo', function () {
    foreach (range(1, 5) as $i) {
        loanOf($this->ii, $this->sc, ['created_at' => now()->subMinutes($i)]);
    }
    $jefe = User::factory()->withRole(Role::JefeDepartamento, ['campus_id' => $this->campus->id, 'department_id' => $this->sc->id])->create();
    $actor = app(Actor::class)->forUser($jefe);
    $actor->allowsAny(AcademicLoadPermission::AgreementView);

    DB::enableQueryLog();
    $rows = app(AgreementsInboxQuery::class)->inbox($actor, $this->term, $this->campus)['decide'];
    $few = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($rows)->toHaveCount(5)
        ->and(array_column($rows, 'requested_at'))->toBe(collect(array_column($rows, 'requested_at'))->sortDesc()->values()->all())
        ->and($few)->toBeLessThan(25);
});

it('el detalle de un préstamo trae la semana del salón con lo ocupado y el bloque pedido sin choque', function () {
    $loan = loanOf($this->ii, $this->sc, ['day' => 2, 'starts_at' => '09:00', 'ends_at' => '11:00']);
    $other = Group::factory()->create(['term_id' => $this->term->id, 'campus_id' => $this->campus->id, 'subject_id' => Subject::factory()->create(['code' => 'ACF-0901'])->id, 'code' => '1B']);
    GroupSession::factory()->for($other)->on(2, '11:00', '13:00')->inRoom($loan->room)->create();
    GroupSession::factory()->for(Group::query()->find($loan->group_id))->on(2, '09:00', '11:00')->inRoom($loan->room)->create();

    $detail = app(AgreementsInboxQuery::class)->detail($loan);

    expect($detail['clash'])->toBeNull()
        ->and($detail['week']['requested'])->toBe(['day' => 2, 'starts_at' => '09:00', 'ends_at' => '11:00'])
        ->and($detail['week']['busy'])->toHaveCount(1)
        ->and($detail['week']['busy'][0])->toMatchArray(['day' => 2, 'starts_at' => '11:00', 'ends_at' => '13:00', 'label' => 'ACF-0901 1B'])
        ->and($detail['week']['days'])->toContain(1, 2, 5)
        ->and($detail['week']['hours'])->toContain(9, 12);
});

it('HC-02: el detalle marca el choque con otro grupo o con un bloqueo del salón', function () {
    $loan = loanOf($this->ii, $this->sc, ['day' => 2, 'starts_at' => '09:00', 'ends_at' => '11:00']);
    RoomBlock::factory()->create(['room_id' => $loan->room_id, 'term_id' => $this->term->id, 'day' => 2, 'starts_at' => '10:00', 'ends_at' => '12:00', 'reason' => 'Mantenimiento']);

    $detail = app(AgreementsInboxQuery::class)->detail($loan);

    expect($detail['clash'])->toMatchArray(['day' => 2, 'label' => 'Mantenimiento']);
});

it('el detalle de un grupo compartido no trae semana', function () {
    expect(app(AgreementsInboxQuery::class)->detail(shareOf($this->ii, $this->sc))['week'])->toBeNull();
});

it('findOrFail da el acuerdo visible y falla con el de otro departamento o campus', function () {
    $mine = loanOf($this->ii, $this->sc);
    $foreign = loanOf($this->ii, $this->cb);
    $jefe = User::factory()->withRole(Role::JefeDepartamento, ['campus_id' => $this->campus->id, 'department_id' => $this->sc->id])->create();
    $actor = app(Actor::class)->forUser($jefe);
    $query = app(AgreementsInboxQuery::class);

    expect($query->findOrFail($actor, $this->term, $this->campus, $mine->id)->is($mine))->toBeTrue()
        ->and(fn () => $query->findOrFail($actor, $this->term, $this->campus, $foreign->id))->toThrow(ModelNotFoundException::class)
        ->and(fn () => $query->findOrFail($actor, $this->term, $this->campus, 'no-es-uuid'))->toThrow(ModelNotFoundException::class);
});
