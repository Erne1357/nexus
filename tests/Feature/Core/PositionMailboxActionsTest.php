<?php

use App\Modules\Core\Actions\DeactivatePositionMailbox;
use App\Modules\Core\Actions\SavePositionMailbox;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Events\PositionMailboxDeactivated;
use App\Modules\Core\Events\PositionMailboxSaved;
use App\Modules\Core\Jobs\ReleaseHeldPositionMailsJob;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Modality;
use App\Modules\Core\Models\OrgUnit;
use App\Modules\Core\Models\PositionMailbox;
use App\Modules\Core\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;

/*
| CE-02: el admin captura, cambia y desactiva el buzón institucional de un puesto (SavePositionMailbox y
| DeactivatePositionMailbox). El puesto es un rol con alcance y solo lleva las columnas que su rol admite; el correo
| tiene que ser institucional; un puesto tiene un solo buzón activo. Todo va a la bitácora y nunca se borra.
*/

beforeEach(function () {
    config(['nexus.institutional_email_domain' => 'cdjuarez.tecnm.mx', 'nexus.institutional_email_domains' => ['cdjuarez.tecnm.mx']]);
    $this->unit = OrgUnit::factory()->create();
    $this->admin = User::factory()->admin()->create();
    $this->actingAs($this->admin);
});

/**
 * Captura el buzón de la jefatura de la unidad de la prueba con los datos que cambien.
 *
 * @param  array<string, mixed>  $data
 */
function saveHeadMailbox(array $data = [], ?PositionMailbox $mailbox = null): PositionMailbox
{
    return app(SavePositionMailbox::class)->handle([
        'role' => 'jefe_departamento',
        'org_unit_id' => test()->unit->id,
        'email' => 'jefatura_syc@cdjuarez.tecnm.mx',
        ...$data,
    ], $mailbox, test()->admin);
}

it('CE-02: captura el buzón de un puesto en minúsculas, activo, con su bitácora y su aviso', function () {
    Event::fake([PositionMailboxSaved::class]);

    $mailbox = saveHeadMailbox(['email' => '  Jefatura_SYC@CDJuarez.TecNM.mx ']);
    $log = Activity::query()->sole();

    expect($mailbox->fresh())->role->toBe(Role::JefeDepartamento)->org_unit_id->toBe($this->unit->id)->email->toBe('jefatura_syc@cdjuarez.tecnm.mx')->is_active->toBeTrue()
        ->and($log->causer_id)->toBe($this->admin->id)
        ->and($log->event)->toBe('created')
        ->and($log->attribute_changes['attributes']['email'])->toBe('jefatura_syc@cdjuarez.tecnm.mx');

    Event::assertDispatched(PositionMailboxSaved::class);
});

it('CE-02: guardar un buzón manda a la cola liberar los correos que esperaban', function () {
    Queue::fake();

    saveHeadMailbox();

    Queue::assertPushed(ReleaseHeldPositionMailsJob::class);
});

it('CE-02: el correo del buzón tiene que ser institucional', function (string $email) {
    expect(fn () => saveHeadMailbox(['email' => $email]))->toThrow(ValidationException::class, 'El buzón tiene que ser un correo institucional (@cdjuarez.tecnm.mx).');

    expect(PositionMailbox::query()->count())->toBe(0);
})->with(['jefatura@gmail.com', 'jefatura@itcj.edu.mx', 'no-es-correo', '']);

it('CE-02: solo puestos con alcance tienen buzón', function (string $role) {
    expect(fn () => saveHeadMailbox(['role' => $role, 'org_unit_id' => null]))->toThrow(ValidationException::class, 'Elige un puesto que tenga alcance');
})->with(['admin', 'docente', 'alumno', 'no-existe']);

it('CE-02: el puesto solo lleva el alcance que su rol admite y que existe', function () {
    expect(fn () => saveHeadMailbox(['career_id' => careerKey(1)]))->toThrow(ValidationException::class, 'El puesto Jefe de departamento académico no lleva carrera.')
        ->and(fn () => saveHeadMailbox(['org_unit_id' => fakeKey(99)]))->toThrow(ValidationException::class, 'Elige una unidad de la lista.')
        ->and(fn () => saveHeadMailbox(['role' => 'coordinador', 'org_unit_id' => null, 'career_id' => 'no-uuid']))->toThrow(ValidationException::class, 'Elige una carrera de la lista.');
});

it('CE-02: un coordinador puede tener buzón por carrera, modalidad y campus; vacío es «todos»', function () {
    $campus = Campus::factory()->create();
    $modality = Modality::factory()->create();

    $mailbox = saveHeadMailbox(['role' => 'coordinador', 'org_unit_id' => null, 'career_id' => careerKey(1), 'modality_id' => $modality->id, 'campus_id' => $campus->id, 'email' => 'coord_isc@cdjuarez.tecnm.mx']);
    $wildcard = saveHeadMailbox(['role' => 'coordinador', 'org_unit_id' => null, 'email' => 'coordinaciones@cdjuarez.tecnm.mx']);

    expect($mailbox->only(['career_id', 'modality_id', 'campus_id']))->toBe(['career_id' => careerKey(1), 'modality_id' => $modality->id, 'campus_id' => $campus->id])
        ->and($wildcard->only(['org_unit_id', 'career_id', 'modality_id', 'campus_id']))->toBe(['org_unit_id' => null, 'career_id' => null, 'modality_id' => null, 'campus_id' => null]);
});

it('CE-02: un puesto tiene un solo buzón activo, aunque otra sesión gane la carrera', function () {
    saveHeadMailbox();

    expect(fn () => saveHeadMailbox(['email' => 'otra@cdjuarez.tecnm.mx']))->toThrow(ValidationException::class, 'Ese puesto ya tiene un buzón activo (jefatura_syc@cdjuarez.tecnm.mx): edítalo o desactívalo primero.');

    $other = OrgUnit::factory()->create();
    PositionMailbox::creating(function () use ($other): void {
        PositionMailbox::withoutEvents(fn () => DB::table('position_mailboxes')->insert(['role' => 'jefe_departamento', 'org_unit_id' => $other->id, 'email' => 'gano@cdjuarez.tecnm.mx', 'is_active' => true]));
    });

    expect(fn () => saveHeadMailbox(['org_unit_id' => $other->id, 'email' => 'tarde@cdjuarez.tecnm.mx']))->toThrow(ValidationException::class, 'Ese puesto ya tiene un buzón activo');
});

it('CE-02: cambia el correo con el valor anterior en la bitácora; sin cambios no registra nada', function () {
    $mailbox = saveHeadMailbox();

    saveHeadMailbox(['email' => 'jefatura.syc@cdjuarez.tecnm.mx'], $mailbox);
    saveHeadMailbox(['email' => 'jefatura.syc@cdjuarez.tecnm.mx'], $mailbox);

    $updated = Activity::query()->where('event', 'updated')->sole();

    expect($mailbox->fresh()->email)->toBe('jefatura.syc@cdjuarez.tecnm.mx')
        ->and($updated->attribute_changes['old'])->toBe(['email' => 'jefatura_syc@cdjuarez.tecnm.mx'])
        ->and($updated->attribute_changes['attributes'])->toBe(['email' => 'jefatura.syc@cdjuarez.tecnm.mx']);
});

it('CE-02: desactivar no borra; editar uno inactivo lo reactiva si el puesto no tiene otro activo', function () {
    Event::fake([PositionMailboxDeactivated::class]);
    $mailbox = saveHeadMailbox();

    app(DeactivatePositionMailbox::class)->handle($mailbox, $this->admin);
    app(DeactivatePositionMailbox::class)->handle($mailbox->fresh(), $this->admin);

    expect($mailbox->fresh())->is_active->toBeFalse()->trashed()->toBeFalse()
        ->and(Activity::query()->where('event', 'updated')->count())->toBe(1);
    Event::assertDispatchedTimes(PositionMailboxDeactivated::class, 1);

    $replacement = saveHeadMailbox(['email' => 'nueva@cdjuarez.tecnm.mx']);

    expect(fn () => saveHeadMailbox([], $mailbox->fresh()))->toThrow(ValidationException::class, 'Ese puesto ya tiene un buzón activo (nueva@cdjuarez.tecnm.mx)');

    app(DeactivatePositionMailbox::class)->handle($replacement, $this->admin);

    expect(saveHeadMailbox([], $mailbox->fresh())->is_active)->toBeTrue();
});
