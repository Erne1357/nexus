<?php

use App\Modules\AcademicLoad\Queries\ProposalHeader;
use Carbon\CarbonImmutable;

/*
| Lo que dice el encabezado de la propuesta por modalidad y lo que avisa el envío (maqueta U1, opción A): dónde se
| ofrece la carrera (MS5), quiénes la coordinan y qué columnas captura cada uno (M5, M7), el aviso de alcance a quien
| solo corrige su campus (M7), quién la envió y cuándo, y a quién avisa el envío (M6, MS18). Puro: no lee la base.
*/

/**
 * @param  list<array{id: string, name: string, keys: list<string>}>  $coordinators
 * @param  list<array{key: string, code: ?string, name: ?string}>|null  $columns
 */
function proposalHeader(array $coordinators = [], ?array $sent = null, ?array $returned = null, array $pending = [], ?array $columns = null): ProposalHeader
{
    return new ProposalHeader(
        careerCode: 'ISIC',
        columns: $columns ?? [['key' => 'c1', 'code' => 'C1', 'name' => 'Campus I'], ['key' => 'c2', 'code' => 'C2', 'name' => 'Campus II']],
        coordinators: $coordinators,
        sent: $sent,
        returned: $returned,
        pendingDepartments: $pending,
        timezone: 'America/Ciudad_Juarez',
    );
}

it('MS5: dice dónde se ofrece la carrera; sin campus, nada', function () {
    expect(proposalHeader()->offer())->toBe('Se ofrece en C1 y C2')
        ->and(proposalHeader(columns: [['key' => 'c1', 'code' => 'C1', 'name' => 'Campus I']])->offer())->toBe('Se ofrece solo en C1')
        ->and(proposalHeader(columns: [['key' => '', 'code' => null, 'name' => null]])->offer())->toBeNull();
});

it('M5, M7: cada coordinador con su campus, o con los campus que cubre si no tiene uno, y quién eres tú', function () {
    $header = proposalHeader([
        ['id' => 'u1', 'name' => 'Héctor Villalobos', 'keys' => ['c1']],
        ['id' => 'u2', 'name' => 'Daniela Quintero', 'keys' => ['c2']],
        ['id' => 'u3', 'name' => 'Rocío Barraza', 'keys' => ['c1', 'c2']],
    ]);

    expect($header->coordinatorLine('u2'))->toBe([
        ['name' => 'Héctor Villalobos', 'campusKey' => 'c1', 'covers' => null, 'me' => false],
        ['name' => 'Daniela Quintero', 'campusKey' => 'c2', 'covers' => null, 'me' => true],
        ['name' => 'Rocío Barraza', 'campusKey' => null, 'covers' => 'C1 y C2', 'me' => false],
    ])
        ->and(proposalHeader([['id' => 'u3', 'name' => 'Rocío Barraza', 'keys' => ['c1']]], columns: [['key' => 'c1', 'code' => 'C1', 'name' => 'Campus I']])->coordinatorLine('u3'))
        ->toBe([['name' => 'Rocío Barraza', 'campusKey' => null, 'covers' => 'C1', 'me' => true]]);
});

it('M7: dice quién captura una columna, o que nadie la captura', function () {
    $header = proposalHeader([['id' => 'u1', 'name' => 'Héctor Villalobos', 'keys' => ['c1']], ['id' => 'u3', 'name' => 'Rocío Barraza', 'keys' => ['c1', 'c2']]]);

    expect($header->capturedBy('c1'))->toBe('C1 lo captura Héctor Villalobos y Rocío Barraza.')
        ->and(proposalHeader()->capturedBy('c2'))->toBe('C2 no tiene coordinador que la capture.');
});

it('M7: el aviso de alcance solo va a quien corrige unas columnas y no otras', function () {
    $header = proposalHeader([['id' => 'u1', 'name' => 'Héctor Villalobos', 'keys' => ['c1']], ['id' => 'u2', 'name' => 'Daniela Quintero', 'keys' => ['c2']]]);

    expect($header->scopeNote(['c2']))->toBe([
        'career' => 'ISIC',
        'campuses' => 'Campus II',
        'codes' => 'C2',
        'others' => 'C1 lo captura Héctor Villalobos.',
    ])
        ->and($header->scopeNote(['c1', 'c2']))->toBeNull()
        ->and($header->scopeNote([]))->toBeNull();
});

it('dice quién la envió, con su campus si coordina solo uno, cuándo, y la última parte regresada', function () {
    $header = proposalHeader(
        [['id' => 'u1', 'name' => 'Héctor Villalobos', 'keys' => ['c1']]],
        sent: ['id' => 'u1', 'name' => 'Héctor Villalobos', 'at' => CarbonImmutable::parse('2026-11-18 17:32:00', 'UTC')],
        returned: ['department' => 'CB', 'at' => CarbonImmutable::parse('2026-11-19 18:00:00', 'UTC')],
    );

    expect($header->sentLine())->toBe(['name' => 'Héctor Villalobos', 'campus' => 'C1', 'at' => '18/11/2026 a las 10:32', 'returned' => 'Regresada por CB el 19/11/2026'])
        ->and(proposalHeader()->sentLine())->toBeNull()
        ->and(proposalHeader(sent: ['id' => null, 'name' => null, 'at' => CarbonImmutable::parse('2026-11-18 17:32:00', 'UTC')])->sentLine()['name'])->toBe('alguien que ya no está');
});

it('MS18: el envío avisa a los demás coordinadores, con su campus, y a los jefes con su parte por decidir', function () {
    $header = proposalHeader([
        ['id' => 'u1', 'name' => 'Héctor Villalobos', 'keys' => ['c1']],
        ['id' => 'u2', 'name' => 'Daniela Quintero', 'keys' => ['c2']],
        ['id' => 'u3', 'name' => 'Rocío Barraza', 'keys' => ['c1', 'c2']],
    ], pending: ['CB', 'SC', 'CEA']);

    expect($header->submitNotice('u2'))->toBe('Se avisa a Héctor Villalobos (C1) y Rocío Barraza (C1 y C2), que coordinan contigo, y a los jefes de CB, SC y CEA.')
        ->and(proposalHeader([['id' => 'u2', 'name' => 'Daniela Quintero', 'keys' => ['c2']]], pending: ['CB'])->submitNotice('u2'))->toBe('Se avisa a los jefes de CB.')
        ->and(proposalHeader([['id' => 'u1', 'name' => 'Héctor Villalobos', 'keys' => ['c1']]])->submitNotice('u2'))->toBe('Se avisa a Héctor Villalobos (C1), que coordina contigo.')
        ->and(proposalHeader()->submitNotice('u2'))->toBe('');
});
