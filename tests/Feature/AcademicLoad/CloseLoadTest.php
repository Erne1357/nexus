<?php

use App\Modules\Academic\Models\Department;
use App\Modules\AcademicLoad\Actions\Transitions\CloseLoad;
use App\Modules\AcademicLoad\Enums\AgreementStatus;
use App\Modules\AcademicLoad\Enums\LoadStatus;
use App\Modules\AcademicLoad\Events\LoadClosing;
use App\Modules\AcademicLoad\Events\LoadTransitioned;
use App\Modules\AcademicLoad\Events\LoadTransitioning;
use App\Modules\AcademicLoad\Models\AcademicLoad;
use App\Modules\AcademicLoad\Models\InterdepartmentalAgreement;
use App\Modules\AcademicLoad\Models\LoadTransition;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\User;
use App\Modules\Scheduling\Models\Group;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;

/*
| CloseLoad cierra la carga de un departamento en un campus (`staffing` → `closed`, tarea 4.1). Su guarda de
| AcademicLoad son los acuerdos sin respuesta (P6, confirmado el 10 de noviembre de 2026, y los que esperan la
| respuesta del propio departamento, supuesto por confirmar); HC-15 la vetan Scheduling y Staffing con
| LoadTransitioning. Dentro de la misma transacción avisa con LoadClosing, para que Scheduling confirme los grupos
| (P1, confirmado el 10 de noviembre de 2026).
*/

beforeEach(function () {
    $this->user = User::factory()->admin()->create();
    $this->actingAs($this->user);
    $this->load = AcademicLoad::factory()->inStatus(LoadStatus::Staffing)->create();
});

function closeLoadAgreement(AcademicLoad $load, array $attributes = [], AgreementStatus $status = AgreementStatus::Proposed): InterdepartmentalAgreement
{
    return InterdepartmentalAgreement::factory()->inStatus($status)->create([
        'term_id' => $load->term_id,
        'campus_id' => loadCampus()->id,
        ...$attributes,
    ]);
}

function closeLoadSharedGroup(AcademicLoad $load, array $attributes = [], AgreementStatus $status = AgreementStatus::Proposed): InterdepartmentalAgreement
{
    return InterdepartmentalAgreement::factory()->sharedGroup()->inStatus($status)->create([
        'term_id' => $load->term_id,
        'modality_id' => $load->modality_id,
        ...$attributes,
    ]);
}

it('cierra la carga de asignación con quién y cuándo, su historial, bitácora y evento', function () {
    Event::fake([LoadTransitioned::class]);
    $this->freezeTime();

    app(CloseLoad::class)->handle($this->load, $this->user, 'Todo asignado');

    $load = $this->load->fresh();
    expect($load->status)->toBe(LoadStatus::Closed)
        ->and($load->closed_by)->toBe($this->user->id)
        ->and($load->closed_at->toDateTimeString())->toBe(now()->toDateTimeString());

    $row = LoadTransition::query()->firstOrFail();
    expect($row->from_status)->toBe(LoadStatus::Staffing)
        ->and($row->to_status)->toBe(LoadStatus::Closed)
        ->and($row->user_id)->toBe($this->user->id)
        ->and($row->comment)->toBe('Todo asignado');

    expect(Activity::query()->where('event', 'transitioned')->sole()->attribute_changes['attributes'])->toBe(['status' => 'closed']);

    Event::assertDispatched(LoadTransitioned::class, fn (LoadTransitioned $event): bool => $event->load->is($this->load)
        && $event->from === LoadStatus::Staffing && $event->to === LoadStatus::Closed);
});

it('avisa con LoadClosing dentro de la transacción, con la carga ya cerrada', function () {
    $seen = null;
    Event::listen(LoadClosing::class, function (LoadClosing $event) use (&$seen): void {
        $seen = [$event->load->id, $event->load->status, DB::transactionLevel()];
    });

    app(CloseLoad::class)->handle($this->load, $this->user);

    expect($seen[0])->toBe($this->load->id)
        ->and($seen[1])->toBe(LoadStatus::Closed)
        ->and($seen[2])->toBeGreaterThan(0);
});

it('si un listener de LoadClosing falla, la carga no se cierra', function () {
    Event::listen(LoadClosing::class, fn () => throw new RuntimeException('Sin conexión'));

    expect(fn () => app(CloseLoad::class)->handle($this->load, $this->user))->toThrow(RuntimeException::class);

    expect($this->load->fresh()->status)->toBe(LoadStatus::Staffing)
        ->and(LoadTransition::query()->count())->toBe(0);
});

it('con un veto de LoadTransitioning no cierra ni avisa con LoadClosing', function () {
    Event::fake([LoadClosing::class]);
    Event::listen(LoadTransitioning::class, fn (LoadTransitioning $event) => $event->block('HC-15: 3 grupos no tienen docente.'));

    expect(fn () => app(CloseLoad::class)->handle($this->load, $this->user))->toThrow(
        fn (ValidationException $e) => expect($e->errors()['status'])->toBe(['HC-15: 3 grupos no tienen docente.']),
    );

    expect($this->load->fresh()->status)->toBe(LoadStatus::Staffing);
    Event::assertNotDispatched(LoadClosing::class);
});

it('solo cierra desde asignación', function (LoadStatus $status) {
    $load = AcademicLoad::factory()->inStatus($status)->create();

    expect(fn () => app(CloseLoad::class)->handle($load, $this->user))->toThrow(
        fn (ValidationException $e) => expect($e->errors()['status'][0])->toStartWith("La carga está en {$status->label()}:"),
    );
})->with([LoadStatus::Draft, LoadStatus::Proposed, LoadStatus::Adjusting, LoadStatus::Closed, LoadStatus::Published]);

it('bloquea con un grupo compartido que el departamento propuso y sigue sin respuesta (P6)', function () {
    closeLoadSharedGroup($this->load, ['from_department_id' => $this->load->department_id]);
    closeLoadSharedGroup($this->load, ['from_department_id' => $this->load->department_id]);

    expect(fn () => app(CloseLoad::class)->handle($this->load, $this->user))->toThrow(
        fn (ValidationException $e) => expect($e->errors()['status'])->toBe([
            '2 grupos compartidos que propuso el departamento siguen sin respuesta: espera la respuesta o cancélalos en Acuerdos.',
        ]),
    );

    expect($this->load->fresh()->status)->toBe(LoadStatus::Staffing);
});

it('bloquea con acuerdos que esperan la respuesta del departamento', function () {
    closeLoadAgreement($this->load, ['to_department_id' => $this->load->department_id]);

    expect(fn () => app(CloseLoad::class)->handle($this->load, $this->user))->toThrow(
        fn (ValidationException $e) => expect($e->errors()['status'])->toBe([
            '1 acuerdo espera la respuesta del departamento: acéptalo o recházalo en Acuerdos.',
        ]),
    );
});

it('M1: bloquea con un grupo compartido sin respuesta de la modalidad de la carga, sea del campus que sea (el acuerdo no guarda campus)', function () {
    closeLoadSharedGroup($this->load, ['from_department_id' => $this->load->department_id, 'group_id' => Group::factory()->create(['campus_id' => Campus::factory()->create(['code' => 'C9'])->id])->id]);

    expect(fn () => app(CloseLoad::class)->handle($this->load, $this->user))->toThrow(ValidationException::class);
});

it('no bloquea con acuerdos decididos, cancelados, de otra modalidad o periodo, ni con préstamos que pidió', function () {
    foreach ([AgreementStatus::Accepted, AgreementStatus::Rejected, AgreementStatus::Cancelled] as $status) {
        closeLoadSharedGroup($this->load, ['from_department_id' => $this->load->department_id], $status);
        closeLoadAgreement($this->load, ['to_department_id' => $this->load->department_id], $status);
    }
    closeLoadSharedGroup($this->load, ['from_department_id' => $this->load->department_id, 'modality_id' => modalityOf('EAD')->id]);
    closeLoadAgreement($this->load, ['to_department_id' => $this->load->department_id, 'term_id' => AcademicLoad::factory()->create()->term_id]);
    closeLoadAgreement($this->load, ['from_department_id' => $this->load->department_id]);
    closeLoadSharedGroup($this->load, ['from_department_id' => Department::factory()->create()->id]);

    app(CloseLoad::class)->handle($this->load, $this->user);

    expect($this->load->fresh()->status)->toBe(LoadStatus::Closed);
});

it('la policy deja cerrar al admin, al jefe del departamento y a Subdirección, y niega a los demás', function (?Role $role, bool $sameDepartment, bool $expected) {
    $campus = Campus::factory()->create();
    $load = AcademicLoad::factory()->inStatus(LoadStatus::Staffing)->create(['modality_id' => modalityOf('PRES')->id, 'department_id' => departmentKey(10)]);
    $user = $role === null
        ? User::factory()->admin()->create()
        : User::factory()->withRole($role, [
            'modality_id' => modalityOf('PRES')->id,
            ...($role === Role::Coordinador ? ['career_id' => careerKey(20)] : ($role === Role::Subdireccion ? [] : ['department_id' => departmentKey($sameDepartment ? 10 : 11)])),
        ])->create();

    expect($user->can('close', $load))->toBe($expected);
})->with([
    'admin' => [null, true, true],
    'jefe del departamento' => [Role::JefeDepartamento, true, true],
    'jefe de otro departamento' => [Role::JefeDepartamento, false, false],
    'jefe de proyecto docente' => [Role::JefeProyectoDocente, true, false],
    'subdirección' => [Role::Subdireccion, true, true],
    'coordinador' => [Role::Coordinador, true, false],
]);
