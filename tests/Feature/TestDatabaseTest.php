<?php

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

it('las pruebas corren contra PostgreSQL nexus_test', function () {
    // Con DB_DATABASE sobreescrito (worktrees en paralelo) el nombre cambia, pero nunca es la base de desarrollo.
    expect(DB::connection()->getDriverName())->toBe('pgsql')
        ->and(DB::connection()->getDatabaseName())->toStartWith('nexus_test');
});

it('guarda y lee un timestamptz sin desfase', function () {
    $at = CarbonImmutable::parse('2027-01-18 08:00:00', 'America/Ciudad_Juarez');

    DB::statement('create temporary table timestamptz_probe (at timestamptz not null)');
    DB::table('timestamptz_probe')->insert(['at' => $at]);

    $stored = CarbonImmutable::parse(DB::table('timestamptz_probe')->value('at'));

    expect($stored->equalTo($at))->toBeTrue()
        ->and($stored->getTimestamp())->toBe($at->getTimestamp());
});
