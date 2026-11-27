<?php

use App\Modules\Core\Authorization\Actor;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\User;
use App\Modules\Core\Queries\TermQuery;
use Carbon\CarbonImmutable;

/*
| TermQuery::forTable: la tabla de periodos del más reciente al más antiguo, con búsqueda; vacía para
| quien TermPolicy no deja ver.
*/

beforeEach(function () {
    Term::factory()->create(['code' => '20263', 'name' => 'Ago-Dic 2026', 'starts_on' => '2026-08-10', 'ends_on' => '2026-12-11']);
    Term::factory()->create(['code' => '20271', 'name' => 'Ene-Jun 2027', 'starts_on' => '2027-01-18', 'ends_on' => '2027-06-11']);
});

function termCodesFor(User $user, string $search = '', int $perPage = 15): array
{
    test()->actingAs($user);

    return app(TermQuery::class)->forTable(app(Actor::class), $search, $perPage)->pluck('code')->all();
}

it('lista al admin y a la subdirección los periodos del más reciente al más antiguo', function () {
    $campus = Campus::factory()->create();

    expect(termCodesFor(User::factory()->admin()->create()))->toBe(['20271', '20263'])
        ->and(termCodesFor(User::factory()->withRole(Role::Subdireccion, ['campus_id' => $campus->id])->create()))->toBe(['20271', '20263']);
});

it('busca por código o nombre, literal y sin distinguir mayúsculas', function () {
    $admin = User::factory()->admin()->create();

    expect(termCodesFor($admin, '20263'))->toBe(['20263'])
        ->and(termCodesFor($admin, 'ene-jun'))->toBe(['20271'])
        ->and(termCodesFor($admin, '%'))->toBe([]);
});

it('pagina', function () {
    expect(termCodesFor(User::factory()->admin()->create(), '', 1))->toBe(['20271']);
});

it('no lista nada a quien la policy no deja ver', function () {
    $campus = Campus::factory()->create();
    $docente = User::factory()->withRole(Role::Docente, ['campus_id' => $campus->id])->create();

    expect(termCodesFor($docente))->toBe([]);
});

describe('lastEnded (1.85)', function () {
    it('da el periodo de mayor fin anterior al día, y el siguiente que aún no empieza no cuenta', function () {
        $query = app(TermQuery::class);

        expect($query->lastEnded(CarbonImmutable::parse('2026-12-15'))?->code)->toBe('20263')
            ->and($query->lastEnded(CarbonImmutable::parse('2027-06-12'))?->code)->toBe('20271')
            ->and($query->lastEnded(CarbonImmutable::parse('2026-12-11'))?->code)->toBeNull();
    });

    it('es nulo si ningún periodo ha terminado', function () {
        expect(app(TermQuery::class)->lastEnded(CarbonImmutable::parse('2026-01-01')))->toBeNull();
    });
});
