<?php

use App\Modules\Academic\Models\Department;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\PositionMailbox;
use App\Modules\Core\Models\User;
use Spatie\Activitylog\Models\Activity;

/*
| CE-02: el comando de una sola vez `academic:position-mailboxes-from-departments` crea el buzón de la jefatura de cada
| departamento activo desde el correo que ya trae (`departments.email`): solo institucionales, sin tocar una jefatura
| que ya tiene buzón activo, con bitácora y con `--dry-run`, que solo cuenta.
*/

beforeEach(function () {
    config(['nexus.institutional_email_domain' => 'cdjuarez.tecnm.mx', 'nexus.institutional_email_domains' => ['cdjuarez.tecnm.mx']]);
    $this->sis = Department::factory()->create(['code' => 'SIS', 'email' => 'Jefatura_SYC@cdjuarez.tecnm.mx']);
    $this->ind = Department::factory()->create(['code' => 'IND', 'email' => 'industrial@gmail.com']);
    $this->ele = Department::factory()->create(['code' => 'ELE', 'email' => null]);
    $this->old = Department::factory()->create(['code' => 'OLD', 'email' => 'vieja@cdjuarez.tecnm.mx', 'is_active' => false]);
});

it('CE-02: con --dry-run solo cuenta y no escribe', function () {
    $this->artisan('academic:position-mailboxes-from-departments', ['--dry-run' => true])
        ->expectsOutputToContain('Sin escribir (--dry-run). Por crear: 1 · Ya tenían ese buzón: 0 · Omitidos: 1.')
        ->assertSuccessful();

    expect(PositionMailbox::query()->count())->toBe(0);
});

it('CE-02: crea el buzón de la jefatura de cada departamento con correo institucional, con bitácora', function () {
    $admin = User::factory()->admin()->create(['username' => 'admin.prueba']);

    $this->artisan('academic:position-mailboxes-from-departments', ['--user' => 'admin.prueba'])
        ->expectsOutputToContain('Omitido: no es un correo institucional')
        ->assertSuccessful();

    $mailbox = PositionMailbox::query()->sole();

    expect($mailbox)->role->toBe(Role::JefeDepartamento)->org_unit_id->toBe($this->sis->id)->email->toBe('jefatura_syc@cdjuarez.tecnm.mx')->is_active->toBeTrue()
        ->and(Activity::query()->where('subject_type', $mailbox->getMorphClass())->sole()->causer_id)->toBe($admin->id);
});

it('CE-02: es idempotente y no pisa un buzón que capturó el admin', function () {
    PositionMailbox::factory()->create(['org_unit_id' => $this->ind->id, 'email' => 'jefatura_ind@cdjuarez.tecnm.mx']);
    $this->ind->update(['email' => 'otra_ind@cdjuarez.tecnm.mx']);

    $this->artisan('academic:position-mailboxes-from-departments')->assertSuccessful();
    $this->artisan('academic:position-mailboxes-from-departments')
        ->expectsOutputToContain('Por crear: 0 · Ya tenían ese buzón: 1 · Omitidos: 1.')
        ->assertSuccessful();

    expect(PositionMailbox::query()->count())->toBe(2)
        ->and(PositionMailbox::query()->where('org_unit_id', $this->ind->id)->sole()->email)->toBe('jefatura_ind@cdjuarez.tecnm.mx');
});

it('CE-02: un usuario que no existe detiene el comando sin escribir', function () {
    $this->artisan('academic:position-mailboxes-from-departments', ['--user' => 'nadie'])
        ->expectsOutputToContain('No existe el usuario nadie.')
        ->assertFailed();

    expect(PositionMailbox::query()->count())->toBe(0);
});
