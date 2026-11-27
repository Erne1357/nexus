<?php

use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\RoleAssignment;
use App\Modules\Core\Models\User;
use App\Modules\Core\Rules\UserAccountRules;
use Illuminate\Validation\ValidationException;

/*
| UserAccountRules: el correo institucional de una cuenta (CF-S3) y quién es alumno para el panel (CF-S4).
*/

beforeEach(function () {
    $this->travelTo('2026-11-14 10:00:00');
    $this->rules = app(UserAccountRules::class);
});

it('CF-S3: el correo va en minúsculas y vacío es ninguno', function () {
    expect($this->rules->institutionalEmail(' Ana.Perez@CDJUAREZ.TECNM.MX '))->toBe('ana.perez@cdjuarez.tecnm.mx')
        ->and($this->rules->institutionalEmail(''))->toBeNull()
        ->and($this->rules->institutionalEmail(null))->toBeNull();
});

it('CF-S3: el correo sigue el dominio configurado', function () {
    config(['nexus.institutional_email_domain' => 'itcj.edu.mx']);

    expect($this->rules->institutionalEmail('ana@itcj.edu.mx'))->toBe('ana@itcj.edu.mx')
        ->and(fn () => $this->rules->institutionalEmail('ana@cdjuarez.tecnm.mx'))->toThrow(ValidationException::class, 'El correo institucional debe terminar en @itcj.edu.mx.');
});

it('CF-S3: el correo de otro usuario está tomado sin importar mayúsculas, pero el propio no', function () {
    $ana = User::factory()->create(['email' => 'Ana@cdjuarez.tecnm.mx']);

    expect(fn () => $this->rules->ensureEmailIsFree('ana@cdjuarez.tecnm.mx'))->toThrow(ValidationException::class, 'Otro usuario ya tiene el correo ana@cdjuarez.tecnm.mx.');

    $this->rules->ensureEmailIsFree('ana@cdjuarez.tecnm.mx', $ana);
});

it('CF-S4: es alumno quien solo ha tenido el puesto de alumno; quien tuvo otro puesto, aunque ya terminara, no', function () {
    $student = User::factory()->withRole(Role::Alumno)->create();
    $former = User::factory()->withRole(Role::Alumno)->create();
    RoleAssignment::factory()->create(['user_id' => $former->id, 'role' => Role::Admin, 'valid_from' => '2025-01-01', 'valid_to' => '2025-12-31']);
    $nobody = User::factory()->create();

    expect($this->rules->isStudent($student))->toBeTrue()
        ->and($this->rules->isStudent($former))->toBeFalse()
        ->and($this->rules->isStudent($nobody))->toBeFalse();
});
