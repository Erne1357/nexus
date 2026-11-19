<?php

use App\Modules\Core\Authorization\Actor;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\RoleAssignment;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\User;
use App\Modules\Core\Queries\CredentialQuery;

/*
| CredentialQuery (tarea 1.23): la credencial de quien trabaja, que leen la banda y la respuesta del login en JSON.
*/

function credentialOf(User $user): array
{
    test()->actingAs($user);

    return app(CredentialQuery::class)->of(app(Actor::class));
}

beforeEach(function () {
    $this->c2 = Campus::factory()->create(['code' => 'C2', 'name' => 'Campus II']);
});

it('da el nombre, el usuario, las iniciales, el puesto activo y el periodo activo con su resumen', function () {
    Term::factory()->current()->create(['name' => 'Ene-Jun 2027']);
    $user = User::factory()
        ->withRole(Role::Coordinador, ['campus_id' => $this->c2->id, 'career_id' => careerKey(20)])
        ->create(['name' => 'María de la Luz Pérez', 'username' => '11042']);

    expect(credentialOf($user))->toBe([
        'name' => 'María de la Luz Pérez',
        'username' => '11042',
        'initials' => 'ML',
        'role' => 'Coordinador de carrera',
        'term' => 'Ene-Jun 2027',
        'summary' => 'Coordinador de carrera · Ene-Jun 2027',
    ]);
});

it('sin periodo actual el resumen es solo el puesto', function () {
    $user = User::factory()->withRole(Role::Coordinador, ['campus_id' => $this->c2->id, 'career_id' => careerKey(20)])->create();

    expect(credentialOf($user))
        ->term->toBeNull()
        ->summary->toBe('Coordinador de carrera');
});

it('sin un puesto vigente el resumen es solo el periodo, y sin mayúsculas las iniciales salen de las dos primeras palabras', function () {
    Term::factory()->current()->create(['name' => 'Ene-Jun 2027']);
    $user = User::factory()->withRole(Role::Coordinador, ['campus_id' => $this->c2->id, 'career_id' => careerKey(20)])->create(['name' => 'eva luna']);
    RoleAssignment::where('user_id', $user->id)->update(['valid_to' => today()->subDay()]);

    expect(credentialOf($user))
        ->role->toBeNull()
        ->initials->toBe('EL')
        ->summary->toBe('Ene-Jun 2027');
});
