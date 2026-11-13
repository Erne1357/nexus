<?php

use App\Modules\Academic\Models\Career;
use App\Modules\Academic\Models\Department;
use App\Modules\Academic\Models\StudyPlan;
use App\Modules\Academic\Models\StudyPlanSubject;
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
use App\Modules\Scheduling\Actions\Agreements\RejectRoomLoan;
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

function agreementsInboxOf(User $user, Term $term, Campus $campus, ?AgreementKind $kind = null, string $search = ''): array
{
    return app(AgreementsInboxQuery::class)->inbox(app(Actor::class)->forUser($user), $term, $campus->catalogModality, $campus, $kind, $search);
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

    $inbox = agreementsInboxOf($jefe, $this->term, $this->campus);
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

    $rows = collect(agreementsInboxOf($jefe, $this->term, $this->campus)['decide'])->keyBy('id');

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

    expect(agreementsInboxOf($jefe, $this->term, $this->campus)['done'][0])->toMatchArray(['id' => $rejected->id, 'decided_by' => 'Jefe de CB', 'comment' => 'No hay lugares.'])
        ->and(agreementsInboxOf($jefe, $this->term, $this->campus)['done'][0]['decided_at'])->not->toBeNull();
});

it('la Subdirección ve todos del campus y los propuestos van a «Enviados»; el admin igual', function () {
    $a = loanOf($this->ii, $this->sc);
    $b = shareOf($this->cb, $this->ii, ['status' => AgreementStatus::Accepted, 'decided_at' => now()]);
    $subdireccion = User::factory()->withRole(Role::Subdireccion)->create();

    $inbox = agreementsInboxOf($subdireccion, $this->term, $this->campus);

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

    expect(array_merge(...array_values(agreementsInboxOf($jefe, $this->term, $this->campus))))->toBe([])
        ->and(array_merge(...array_values(agreementsInboxOf($coordinador, $this->term, $this->campus))))->toBe([])
        ->and(array_merge(...array_values(agreementsInboxOf($docente, $this->term, $this->campus))))->toBe([]);
});

it('filtra por tipo y por texto: salón, grupo, materia, departamento y carrera', function () {
    $loan = loanOf($this->ii, $this->sc, [], 'SCC-1013');
    $share = shareOf($this->cb, $this->sc);
    $jefe = User::factory()->withRole(Role::JefeDepartamento, ['campus_id' => $this->campus->id, 'department_id' => $this->sc->id])->create();
    $ids = fn (?AgreementKind $kind, string $search): array => array_column(agreementsInboxOf($jefe, $this->term, $this->campus, $kind, $search)['decide'], 'id');

    expect($ids(AgreementKind::RoomLoan, ''))->toBe([$loan->id])
        ->and($ids(AgreementKind::SharedGroup, ''))->toBe([$share->id])
        ->and($ids(null, $loan->room->code))->toBe([$loan->id])
        ->and($ids(null, 'scc-1013'))->toBe([$loan->id])
        ->and($ids(null, 'Redes'))->toBe([$loan->id])
        ->and($ids(null, 'Industrial'))->toBe([$loan->id])
        ->and($ids(null, 'isc'))->toBe([$share->id])
        ->and($ids(null, 'no existe'))->toBe([]);
});

it('«Por decidir» y «Enviados» van del más antiguo al más reciente sin importar el tipo, y «Decididos» del más reciente', function () {
    $oldShare = shareOf($this->ii, $this->sc, ['created_at' => now()->subMinutes(30)]);
    $loan = loanOf($this->ii, $this->sc, ['created_at' => now()->subMinutes(20)]);
    $newShare = shareOf($this->ii, $this->sc, ['created_at' => now()->subMinutes(10)]);
    $oldSent = loanOf($this->sc, $this->cb, ['created_at' => now()->subMinutes(15)]);
    $newSent = shareOf($this->sc, $this->cb, ['created_at' => now()->subMinutes(5)]);
    $doneBefore = loanOf($this->ii, $this->sc, ['status' => AgreementStatus::Accepted, 'decided_at' => now()->subMinutes(8)]);
    $doneAfter = loanOf($this->ii, $this->sc, ['status' => AgreementStatus::Accepted, 'decided_at' => now()->subMinute()]);
    $jefe = User::factory()->withRole(Role::JefeDepartamento, ['campus_id' => $this->campus->id, 'department_id' => $this->sc->id])->create();

    $inbox = agreementsInboxOf($jefe, $this->term, $this->campus);

    expect(array_column($inbox['decide'], 'id'))->toBe([$oldShare->id, $loan->id, $newShare->id])
        ->and(array_column($inbox['sent'], 'id'))->toBe([$oldSent->id, $newSent->id])
        ->and(array_column($inbox['done'], 'id'))->toBe([$doneAfter->id, $doneBefore->id]);
});

it('cada renglón dice de qué lado está el actor: decide, pidió o solo ve', function () {
    $in = loanOf($this->ii, $this->sc);
    $out = shareOf($this->sc, $this->ii);
    $jefe = User::factory()->withRole(Role::JefeDepartamento, ['campus_id' => $this->campus->id, 'department_id' => $this->sc->id])->create();
    $subdireccion = User::factory()->withRole(Role::Subdireccion)->create();

    $mine = collect(array_merge(...array_values(agreementsInboxOf($jefe, $this->term, $this->campus))))->keyBy('id');
    $theirs = collect(array_merge(...array_values(agreementsInboxOf($subdireccion, $this->term, $this->campus))))->keyBy('id');

    expect($mine[$in->id]['side'])->toBe('to')
        ->and($mine[$out->id]['side'])->toBe('from')
        ->and($theirs[$in->id]['side'])->toBeNull();
});

it('HC-02 y HC-11: marca en dos consultas los préstamos que no se pueden aceptar porque su bloque empalma', function () {
    $free = loanOf($this->ii, $this->sc, ['day' => 2, 'starts_at' => '09:00', 'ends_at' => '11:00']);
    $taken = loanOf($this->ii, $this->sc, ['day' => 2, 'starts_at' => '09:00', 'ends_at' => '11:00']);
    $other = Group::factory()->create(['term_id' => $this->term->id, 'campus_id' => $this->campus->id, 'subject_id' => Subject::factory()->create(['code' => 'ACF-0901'])->id, 'code' => '1B']);
    GroupSession::factory()->for($other)->on(2, '10:00', '12:00')->inRoom($taken->room)->create();
    GroupSession::factory()->for(Group::query()->find($free->group_id))->on(2, '09:00', '11:00')->inRoom($free->room)->create();
    $locked = loanOf($this->ii, $this->sc, ['day' => 3, 'starts_at' => '07:00', 'ends_at' => '08:00']);
    RoomBlock::factory()->create(['room_id' => $locked->room_id, 'term_id' => $this->term->id, 'day' => 3, 'starts_at' => '07:00', 'ends_at' => '09:00', 'reason' => 'Mantenimiento']);
    $share = shareOf($this->ii, $this->sc);
    $jefe = User::factory()->withRole(Role::JefeDepartamento, ['campus_id' => $this->campus->id, 'department_id' => $this->sc->id])->create();
    $rows = agreementsInboxOf($jefe, $this->term, $this->campus)['decide'];

    DB::flushQueryLog();
    DB::enableQueryLog();
    $blocked = app(AgreementsInboxQuery::class)->blocked($rows);
    $queries = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect(array_keys($blocked))->toEqualCanonicalizing([$taken->id, $locked->id])
        ->and($blocked[$taken->id])->toMatchArray(['label' => 'ACF-0901 1B', 'rule' => 'HC-02', 'starts_at' => '10:00', 'ends_at' => '12:00'])
        ->and($blocked[$locked->id])->toMatchArray(['label' => 'Mantenimiento', 'rule' => 'HC-11'])
        ->and($blocked)->not->toHaveKey($share->id)
        ->and($queries)->toBe(2);
});

it('no hace una consulta por acuerdo: las mismas consultas con 1 que con 8 acuerdos', function () {
    $jefe = User::factory()->withRole(Role::JefeDepartamento, ['campus_id' => $this->campus->id, 'department_id' => $this->sc->id])->create();
    $count = function () use ($jefe): int {
        $actor = app(Actor::class)->forUser($jefe);
        $actor->allowsAny(AcademicLoadPermission::AgreementView);
        DB::flushQueryLog();
        DB::enableQueryLog();
        app(AgreementsInboxQuery::class)->inbox($actor, $this->term, $this->campus->catalogModality, $this->campus);
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $queries;
    };

    loanOf($this->ii, $this->sc, ['status' => AgreementStatus::Rejected]);
    loanOf($this->ii, $this->sc);
    shareOf($this->cb, $this->sc);
    loanOf($this->ii, $this->sc, ['status' => AgreementStatus::Cancelled]);
    $count();
    $one = $count();
    foreach (range(1, 4) as $i) {
        loanOf($this->ii, $this->sc);
        shareOf($this->cb, $this->sc);
    }
    loanOf($this->ii, $this->sc, ['status' => AgreementStatus::Cancelled]);

    expect($count())->toBe($one);
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

it('HC-11: el detalle marca el choque con un bloqueo del salón', function () {
    $loan = loanOf($this->ii, $this->sc, ['day' => 2, 'starts_at' => '09:00', 'ends_at' => '11:00']);
    RoomBlock::factory()->create(['room_id' => $loan->room_id, 'term_id' => $this->term->id, 'day' => 2, 'starts_at' => '10:00', 'ends_at' => '12:00', 'reason' => 'Mantenimiento']);

    $detail = app(AgreementsInboxQuery::class)->detail($loan);

    expect($detail['clash'])->toMatchArray(['day' => 2, 'label' => 'Mantenimiento', 'rule' => 'HC-11']);
});

it('el detalle de un grupo compartido trae sus sesiones, su semestre y plan, y dónde lleva la materia la otra carrera en sus planes activos', function () {
    $share = shareOf($this->ii, $this->sc);
    $group = Group::factory()->create(['term_id' => $this->term->id, 'campus_id' => $this->campus->id, 'semester' => 3, 'code' => 'H12']);
    $share->update(['group_id' => $group->id]);
    GroupSession::factory()->for($group)->on(1, '07:00', '09:00')->inRoom(Room::factory()->create(['building_id' => $this->building->id, 'code' => 'NII1']))->create();
    GroupSession::factory()->for($group)->on(3, '07:00', '08:00')->create();
    $plan = StudyPlan::factory()->create(['career_id' => $share->career_id, 'code' => 'ISC-2010-224']);
    StudyPlanSubject::factory()->create(['study_plan_id' => $plan->id, 'subject_id' => $group->subject_id, 'semester' => 4]);
    $retired = StudyPlan::factory()->create(['career_id' => $share->career_id, 'code' => 'ISC-2004-100', 'is_active' => false]);
    StudyPlanSubject::factory()->create(['study_plan_id' => $retired->id, 'subject_id' => $group->subject_id, 'semester' => 2]);

    $detail = app(AgreementsInboxQuery::class)->detail($share->fresh());

    expect($detail['week'])->toBeNull()
        ->and($detail['schedule']['sessions'])->toBe([
            ['day' => 1, 'starts_at' => '07:00', 'ends_at' => '09:00', 'hours' => '7:00–9:00', 'room' => 'NII1'],
            ['day' => 3, 'starts_at' => '07:00', 'ends_at' => '08:00', 'hours' => '7:00–8:00', 'room' => null],
        ])
        ->and($detail['schedule']['days'])->toContain(1, 3, 5)
        ->and($detail['schedule']['hours'])->toContain(7, 8)
        ->and($detail['placement'])->toMatchArray([
            'semester' => 3,
            'plan' => $group->studyPlan->code,
            'owner' => $group->ownerCareer->code,
            'career' => [['plan' => 'ISC-2010-224', 'semester' => 4]],
        ]);
});

it('findOrFail da el acuerdo visible y falla con el de otro departamento o campus', function () {
    $mine = loanOf($this->ii, $this->sc);
    $foreign = loanOf($this->ii, $this->cb);
    $jefe = User::factory()->withRole(Role::JefeDepartamento, ['campus_id' => $this->campus->id, 'department_id' => $this->sc->id])->create();
    $actor = app(Actor::class)->forUser($jefe);
    $query = app(AgreementsInboxQuery::class);

    expect($query->findOrFail($actor, $this->term, $this->campus->catalogModality, $this->campus, $mine->id)->is($mine))->toBeTrue()
        ->and(fn () => $query->findOrFail($actor, $this->term, $this->campus->catalogModality, $this->campus, $foreign->id))->toThrow(ModelNotFoundException::class)
        ->and(fn () => $query->findOrFail($actor, $this->term, $this->campus->catalogModality, $this->campus, 'no-es-uuid'))->toThrow(ModelNotFoundException::class);
});

it('la semana incluye las horas de lo ocupado fuera de la ventana del campus', function () {
    $loan = loanOf($this->ii, $this->sc, ['day' => 2, 'starts_at' => '09:00', 'ends_at' => '11:00']);
    RoomBlock::factory()->create(['room_id' => $loan->room_id, 'term_id' => $this->term->id, 'day' => 2, 'starts_at' => '05:00', 'ends_at' => '06:30', 'reason' => 'Aseo']);
    RoomBlock::factory()->create(['room_id' => $loan->room_id, 'term_id' => $this->term->id, 'day' => 6, 'starts_at' => '21:00', 'ends_at' => '23:00', 'reason' => 'Evento']);

    $week = app(AgreementsInboxQuery::class)->detail($loan)['week'];

    expect($week['hours'])->toContain(5, 22)
        ->and($week['days'])->toContain(6)
        ->and($week['day_labels'][6])->toBe('Sáb');
});

it('marca si un préstamo rechazado o cancelado dejó una sesión sin salón, por la bitácora', function () {
    $loan = loanOf($this->ii, $this->sc);
    GroupSession::factory()->for(Group::query()->find($loan->group_id))->on(1, '09:00', '10:00')->inRoom($loan->room)->create();
    $jefe = User::factory()->withRole(Role::JefeDepartamento, ['campus_id' => $this->campus->id, 'department_id' => $this->sc->id])->create();

    expect(app(AgreementsInboxQuery::class)->row($loan)['room_cleared'])->toBeFalse();

    app(RejectRoomLoan::class)->handle($loan, 'No.', $jefe);

    expect(app(AgreementsInboxQuery::class)->row($loan->fresh())['room_cleared'])->toBeTrue();
});

it('la búsqueda por grupo no trae acuerdos de grupos de otro periodo con la misma clave', function () {
    $mine = loanOf($this->ii, $this->sc, [], 'SCC-1013');
    $jefe = User::factory()->withRole(Role::JefeDepartamento, ['campus_id' => $this->campus->id, 'department_id' => $this->sc->id])->create();

    expect(array_column(agreementsInboxOf($jefe, $this->term, $this->campus, null, '3A')['decide'], 'id'))->toBe([$mine->id]);
});

it('HC-02: el detalle marca el choque con una sesión de otro grupo', function () {
    $loan = loanOf($this->ii, $this->sc, ['day' => 2, 'starts_at' => '09:00', 'ends_at' => '11:00']);
    $other = Group::factory()->create(['term_id' => $this->term->id, 'campus_id' => $this->campus->id, 'subject_id' => Subject::factory()->create(['code' => 'ACF-0901'])->id, 'code' => '1B']);
    GroupSession::factory()->for($other)->on(2, '10:00', '12:00')->inRoom($loan->room)->create();

    expect(app(AgreementsInboxQuery::class)->detail($loan)['clash'])->toMatchArray(['day' => 2, 'label' => 'ACF-0901 1B', 'rule' => 'HC-02']);
});
