<?php

use App\Modules\Core\Authorization\Actor;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\RoleAssignment;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\User;
use App\Modules\Core\Queries\CredentialQuery;
use Carbon\CarbonImmutable;

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

it('TP-04: da el nombre, el usuario, las iniciales, el puesto, el periodo de consulta y el de carga con su resumen', function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-23 10:00', 'America/Ciudad_Juarez'));
    Term::factory()->create(['code' => '20263', 'name' => 'Ago-Dic 2026', 'starts_on' => '2026-08-17', 'ends_on' => '2026-12-11']);
    Term::factory()->current()->create(['code' => '20271', 'name' => 'Ene-Jun 2027', 'starts_on' => '2027-01-25', 'ends_on' => '2027-05-28']);
    $user = User::factory()
        ->withRole(Role::Coordinador, ['campus_id' => $this->c2->id, 'career_id' => careerKey(20)])
        ->create(['name' => 'María de la Luz Pérez', 'username' => '11042']);

    expect(credentialOf($user))->toBe([
        'name' => 'María de la Luz Pérez',
        'username' => '11042',
        'initials' => 'ML',
        'role' => 'Coordinador de carrera',
        'term' => 'Ago-Dic 2026',
        'loadTerm' => 'Ene-Jun 2027',
        'summary' => 'Coordinador de carrera · Ago-Dic 2026',
    ]);
});

it('TP-04: quien no trabaja la carga no lleva periodo de carga', function () {
    Term::factory()->current()->create(['name' => 'Ene-Jun 2027']);

    expect(credentialOf(User::factory()->withRole(Role::Docente)->create()))->loadTerm->toBeNull();
});

it('sin periodo actual el resumen es solo el puesto', function () {
    $user = User::factory()->withRole(Role::Coordinador, ['campus_id' => $this->c2->id, 'career_id' => careerKey(20)])->create();

    expect(credentialOf($user))
        ->term->toBeNull()
        ->summary->toBe('Coordinador de carrera');
});

it('sin un puesto vigente el resumen es solo el periodo, y sin mayúsculas las iniciales salen de las dos primeras palabras', function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-23 10:00', 'America/Ciudad_Juarez'));
    Term::factory()->current()->create(['code' => '20263', 'name' => 'Ago-Dic 2026', 'starts_on' => '2026-08-17', 'ends_on' => '2026-12-11']);
    $user = User::factory()->withRole(Role::Coordinador, ['campus_id' => $this->c2->id, 'career_id' => careerKey(20)])->create(['name' => 'eva luna']);
    RoleAssignment::where('user_id', $user->id)->update(['valid_to' => today()->subDay()]);

    expect(credentialOf($user))
        ->role->toBeNull()
        ->initials->toBe('EL')
        ->summary->toBe('Ago-Dic 2026');
});
