<?php

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportFileUploads\FileUploadConfiguration;

it('las pruebas corren contra PostgreSQL nexus_test', function () {
    // Con DB_DATABASE sobreescrito (worktrees en paralelo) el nombre cambia, pero nunca es la base de desarrollo.
    expect(DB::connection()->getDriverName())->toBe('pgsql')
        ->and(DB::connection()->getDatabaseName())->toStartWith('nexus_test');
});

it('cada base de pruebas tiene sus propios discos falsos, también el de las copias temporales de Livewire', function () {
    // Dos corridas a la vez en el mismo worktree, cada una con su base, no se borran ni se cruzan los archivos.
    $database = DB::connection()->getDatabaseName();

    expect(Storage::fake('imports')->path(''))->toContain("framework/testing/disks/imports_test_{$database}")
        ->and(FileUploadConfiguration::storage()->path(''))->toContain("framework/testing/disks/tmp-for-tests_test_{$database}");
});

it('guarda y lee un timestamptz sin desfase', function () {
    $at = CarbonImmutable::parse('2027-01-18 08:00:00', 'America/Ciudad_Juarez');

    DB::statement('create temporary table timestamptz_probe (at timestamptz not null)');
    DB::table('timestamptz_probe')->insert(['at' => $at]);

    $stored = CarbonImmutable::parse(DB::table('timestamptz_probe')->value('at'));

    expect($stored->equalTo($at))->toBeTrue()
        ->and($stored->getTimestamp())->toBe($at->getTimestamp());
});
