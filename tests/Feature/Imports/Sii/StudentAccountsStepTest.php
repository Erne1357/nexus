<?php

use App\Modules\Core\Enums\PasswordOrigin;
use App\Modules\Core\Models\User;
use App\Modules\Imports\Models\ImportBatch;
use App\Modules\Imports\Sii\SiiFile;
use App\Modules\Students\Enums\StudentStatus;
use App\Modules\Students\Jobs\SyncStudentAccountsJob;
use App\Modules\Students\Models\Student;
use Illuminate\Bus\PendingBatch;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Spatie\Activitylog\Models\Activity;
use Tests\Unit\Imports\Sii\SiiFixtures;

/*
| Paso `cuentas_alumnos` de imports:migrate-sii (AC-10 a AC-14): encola por bloques las cuentas de los alumnos con su
| NIP del SII, sin pasar el NIP por el motor de importación, el reporte, las incidencias ni la consola.
*/

beforeEach(function () {
    Storage::fake('imports');
    config(['nexus.institutional_email_domain' => 'cdjuarez.tecnm.mx']);
    $this->migrator = User::factory()->admin()->create(['username' => 'migrador']);

    $this->dir = SiiFixtures::dir();
    File::copyDirectory(base_path('tests/Fixtures/imports/sii/minimo'), $this->dir);

    $this->writeStudents = function (array $nips): void {
        SiiFixtures::write("{$this->dir}/alumnos_todos.csv", SiiFile::Alumnos, array_map(
            fn (string $control, string $nip): string => SiiFixtures::line(SiiFile::Alumnos, ['no_de_control' => "'{$control}'", 'nip' => $nip === '' ? "''" : $nip]),
            array_keys($nips),
            $nips,
        ));
    };
    $this->migrate = fn (array $options = []): int => Artisan::call('imports:migrate-sii', ['dir' => $this->dir, '--user' => 'migrador', '--only' => ['cuentas_alumnos'], ...$options]);
});

afterEach(function () {
    File::deleteDirectory($this->dir);
});

it('AC-10: crea las cuentas de los alumnos migrados con su NIP, a nombre de quien corre la migración', function () {
    Student::factory()->create(['control_number' => '21110001']);
    Student::factory()->create(['control_number' => '21110002', 'status' => StudentStatus::Egresado]);
    ($this->writeStudents)(['21110001' => '4821', '21110002' => '123', '29999999' => '5555']);

    expect(($this->migrate)())->toBe(0);

    $active = Student::query()->where('control_number', '21110001')->sole()->user;
    $inactive = Student::query()->where('control_number', '21110002')->sole()->user;
    expect(Hash::check('4821', $active->password))->toBeTrue()
        ->and($active->password_origin)->toBe(PasswordOrigin::SiiNip)
        ->and($active->is_active)->toBeTrue()
        ->and(Hash::check('0123', $inactive->password))->toBeTrue()
        ->and($inactive->is_active)->toBeFalse()
        ->and(User::query()->where('username', '29999999')->exists())->toBeFalse()
        ->and(ImportBatch::query()->count())->toBe(0)
        ->and(Activity::query()->where('event', 'student-accounts-synced')->sole()->causer_id)->toBe($this->migrator->id);

    $this->post('/login', ['username' => '21110001', 'password' => '4821'])->assertRedirect('/');
    $this->assertAuthenticatedAs($active);
});

it('AC-10: los encola en un lote de la cola bulk por bloques de 250', function () {
    Bus::fake();
    $nips = [];
    foreach (range(1, SyncStudentAccountsJob::CHUNK + 1) as $i) {
        $nips[sprintf('2111%04d', $i)] = sprintf('%04d', $i);
    }
    ($this->writeStudents)($nips);

    ($this->migrate)();

    Bus::assertBatched(fn (PendingBatch $batch): bool => $batch->name === 'Cuentas de alumnos del SII'
        && $batch->queue() === 'bulk'
        && $batch->allowsFailures()
        && $batch->jobs->map(fn (SyncStudentAccountsJob $job): int => count($job->nips))->all() === [SyncStudentAccountsJob::CHUNK, 1]
        && $batch->jobs->first()->actor->is($this->migrator)
        && $batch->jobs->last()->nips === ['21110251' => '0251']);
});

it('AC-10: en un ensayo no encola nada y dice cuántas encolaría', function () {
    Bus::fake();
    ($this->writeStudents)(['21110001' => '4821', '21110002' => '4822']);

    ($this->migrate)(['--dry-run' => true]);

    Bus::assertNothingBatched();
    expect(Artisan::output())->toContain('Encolaría las cuentas de 2 alumno(s) en 1 bloque(s)');
});

it('AC-10: el NIP no sale en la consola, las incidencias ni los archivos intermedios, y avisa de los vacíos y los cortos', function () {
    Bus::fake();
    $nips = ['21110001' => "'NIP-SECRETO'", '21110002' => '', '21110003' => '42'];
    foreach (range(4, 21) as $i) {
        $nips[sprintf('2111%04d', $i)] = sprintf('%04d', $i);
    }
    ($this->writeStudents)($nips);

    ($this->migrate)();

    $incidents = File::get("{$this->dir}/_incidencias.csv");
    $nexus = collect(File::exists("{$this->dir}/_nexus") ? File::allFiles("{$this->dir}/_nexus") : [])->map(fn ($file): string => File::get($file->getPathname()))->implode("\n");
    expect(Artisan::output())->toContain('student_accounts')->toContain('Encoló las cuentas de 21 alumno(s) en 1 bloque(s)')->not->toContain('NIP-SECRETO')
        ->and($incidents)->toContain('1 alumno(s) sin NIP en el SII')
        ->and($incidents)->toContain('1 alumno(s) con NIP de 1 a 3 dígitos')
        ->and($incidents)->not->toContain('NIP-SECRETO')
        ->and($nexus)->not->toContain('NIP-SECRETO');
});

it('AC-10: si más del 5 % de los alumnos vienen sin NIP, no encola nada y lo reporta como error', function () {
    Bus::fake();
    $nips = [];
    foreach (range(1, 20) as $i) {
        $nips[sprintf('2111%04d', $i)] = $i <= 2 ? '' : sprintf('%04d', $i);
    }
    ($this->writeStudents)($nips);

    ($this->migrate)();

    Bus::assertNothingBatched();
    expect(Artisan::output())->toContain('No se encoló ninguna cuenta de alumno')
        ->and(File::get("{$this->dir}/_incidencias.csv"))->toContain('2 de 20 alumnos sin NIP en el SII, más del 5 %');
});

it('AC-10: con hasta el 5 % sin NIP sí encola, y avisa de los vacíos', function () {
    Bus::fake();
    $nips = [];
    foreach (range(1, 20) as $i) {
        $nips[sprintf('2111%04d', $i)] = $i === 1 ? '' : sprintf('%04d', $i);
    }
    ($this->writeStudents)($nips);

    ($this->migrate)();

    Bus::assertBatchCount(1);
    expect(File::get("{$this->dir}/_incidencias.csv"))->toContain('1 alumno(s) sin NIP en el SII');
});

it('AC-10: un número de control repetido vale por su primer renglón, y se avisa', function () {
    Bus::fake();
    SiiFixtures::write("{$this->dir}/alumnos_todos.csv", SiiFile::Alumnos, [
        SiiFixtures::line(SiiFile::Alumnos, ['no_de_control' => "'21110001'", 'nip' => '1111']),
        SiiFixtures::line(SiiFile::Alumnos, ['no_de_control' => "'21110001'", 'nip' => '2222']),
    ]);

    ($this->migrate)();

    Bus::assertBatched(fn (PendingBatch $batch): bool => $batch->jobs->sole()->nips === ['21110001' => '1111']);
    expect(File::get("{$this->dir}/_incidencias.csv"))->toContain('1 renglón(es) con un número de control repetido: vale el primero');
});
