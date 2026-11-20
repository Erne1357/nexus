<?php

use App\Modules\Core\Enums\StaffType;
use App\Modules\Core\Models\User;
use App\Modules\Imports\Sii\SiiExport;
use App\Modules\Imports\Sii\SiiFile;
use App\Modules\Imports\Sii\SiiMigration;
use App\Modules\Imports\Sii\SiiStepOutOfOrder;
use App\Modules\Imports\Sii\Steps\StaffStep;
use Illuminate\Support\Facades\File;
use Spatie\Activitylog\Models\Activity;
use Tests\Unit\Imports\Sii\SiiFixtures;

/*
| Paso `personal` de imports:migrate-sii (R-V2-3, confirmado el 19 de noviembre de 2026): la plantilla administrativa
| vigente (cada RFC de docentes2 y los docentes activos) con su tipo de personal. Crea al que falta sin acceso ni puesto
| y pone el tipo; nunca crea un usuario por una cuenta de área ni nombra personas en sus avisos. Datos sintéticos.
*/

beforeEach(function () {
    $this->dir = SiiFixtures::dir();
    File::copyDirectory(base_path('tests/Fixtures/imports/sii/minimo'), $this->dir);

    $this->staffRow = fn (string $rfc, array $overrides = []): array => [
        'rfc' => $rfc, 'clave_area' => '110100', 'nombramiento' => 'D', 'status_empleado' => '01',
        'nombre_empleado' => 'JUAN', 'apellidos_empleado' => 'PEREZ DE LA CRUZ', 'correo_electronico' => '', 'inicio_plantel' => '000000',
        ...$overrides,
    ];
    $this->staff2Row = fn (string $rfc, array $overrides = []): array => [
        'rfc' => $rfc, 'apellidop' => 'GOMEZ', 'apellidom' => 'LUNA', 'nombre_empleado' => 'MARIA', 'id_unidad_organica' => '110100',
        'fecha_ingreso_institucion' => '', ...$overrides,
    ];
    $this->run = function (bool $dryRun = false, array $active = []): array {
        $migration = new SiiMigration(SiiExport::open($this->dir), '/nonexistent', $dryRun);
        $migration->remember('active_teacher_rfcs', fn (): array => $active);

        return iterator_to_array((new StaffStep)->batches($migration), false);
    };
});

afterEach(function () {
    File::deleteDirectory($this->dir);
});

it('crea sin acceso al administrativo de la plantilla y le pone su tipo', function () {
    SiiFixtures::writeStaff($this->dir, [($this->staffRow)('ADMI800101AA1', ['nombramiento' => 'A', 'correo_electronico' => 'ANA@cdjuarez.tecnm.mx'])], [($this->staff2Row)('ADMI800101AA1')]);

    $reports = ($this->run)();

    $user = User::where('username', 'ADMI800101AA1')->sole();
    expect($user->staff_type)->toBe(StaffType::Administrativo)
        ->and($user->name)->toBe('Maria Gomez Luna')
        ->and($user->email)->toBe('ana@cdjuarez.tecnm.mx')
        ->and($user->password)->toBeNull()
        ->and($user->roleAssignments()->count())->toBe(0)
        ->and($reports)->toHaveCount(1)
        ->and($reports[0]->template)->toBe('staff')
        ->and($reports[0]->created)->toBe(1);
});

it('al docente que ya tiene usuario solo le pone su tipo', function () {
    $user = User::factory()->create(['username' => 'DOCE800101AA1', 'name' => 'Nombre Propio']);
    SiiFixtures::writeStaff($this->dir, [($this->staffRow)('DOCE800101AA1')], [($this->staff2Row)('DOCE800101AA1')]);

    $reports = ($this->run)(active: ['DOCE800101AA1']);

    expect($user->fresh()->staff_type)->toBe(StaffType::Docente)
        ->and($user->fresh()->name)->toBe('Nombre Propio')
        ->and(User::query()->count())->toBe(1)
        ->and($reports[0]->changed)->toBe(1)
        ->and($reports[0]->created)->toBe(0);
});

it('un administrativo que da clases queda con tipo administrativo', function () {
    SiiFixtures::writeStaff($this->dir, [($this->staffRow)('MIXT800101AA1', ['nombramiento' => 'A'])], []);

    ($this->run)(active: ['MIXT800101AA1']);

    expect(User::where('username', 'MIXT800101AA1')->sole()->staff_type)->toBe(StaffType::Administrativo);
});

it('una segunda corrida no cambia nada ni escribe bitácora', function () {
    SiiFixtures::writeStaff($this->dir, [($this->staffRow)('ADMI800101AA1', ['nombramiento' => 'A'])], [($this->staff2Row)('ADMI800101AA1')]);
    ($this->run)();
    $logs = Activity::query()->count();

    $reports = ($this->run)();

    expect(Activity::query()->count())->toBe($logs)
        ->and($reports[0]->created)->toBe(0)
        ->and($reports[0]->changed)->toBe(0);
});

it('nunca crea un usuario por una cuenta de área del SII', function () {
    SiiFixtures::write("{$this->dir}/acceso.csv", SiiFile::Acceso, [SiiFixtures::line(SiiFile::Acceso, ['usuario' => "'cuentaarea1'"])]);
    SiiFixtures::writeStaff($this->dir, [], []);

    ($this->run)();

    expect(User::query()->where('username', 'cuentaarea1')->exists())->toBeFalse()
        ->and(User::query()->count())->toBe(0);
});

it('un nombramiento distinto de D y A queda sin tipo, con un aviso que solo cuenta', function () {
    SiiFixtures::writeStaff($this->dir, [($this->staffRow)('RARO800101AA1', ['nombramiento' => 'X'])], [($this->staff2Row)('RARO800101AA1'), ($this->staff2Row)('SOLO800202BB2')]);

    $reports = ($this->run)();
    $messages = collect($reports[0]->incidents)->pluck('message')->implode("\n");

    expect(User::where('username', 'RARO800101AA1')->sole()->staff_type)->toBeNull()
        ->and(User::where('username', 'SOLO800202BB2')->sole()->staff_type)->toBeNull()
        ->and($messages)->toContain('2 persona(s) sin tipo')
        ->and($messages)->not->toContain('RARO800101AA1')
        ->and($messages)->not->toContain('MARIA');
});

it('en un ensayo no escribe nada y reporta lo que haría', function () {
    SiiFixtures::writeStaff($this->dir, [($this->staffRow)('ADMI800101AA1', ['nombramiento' => 'A'])], [($this->staff2Row)('ADMI800101AA1')]);

    $reports = ($this->run)(dryRun: true);

    expect(User::query()->count())->toBe(0)
        ->and(Activity::query()->count())->toBe(0)
        ->and($reports[0]->created)->toBe(1);
});

it('se detiene si no corrió docentes antes', function () {
    $migration = new SiiMigration(SiiExport::open($this->dir), '/nonexistent');

    iterator_to_array((new StaffStep)->batches($migration));
})->throws(SiiStepOutOfOrder::class);
