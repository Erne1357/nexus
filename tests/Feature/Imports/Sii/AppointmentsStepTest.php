<?php

use App\Modules\Core\Enums\OrgUnitKind;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\OrgUnit;
use App\Modules\Core\Models\User;
use App\Modules\Faculty\Models\Teacher;
use App\Modules\Imports\Sii\SiiExport;
use App\Modules\Imports\Sii\SiiFile;
use App\Modules\Imports\Sii\SiiMigration;
use App\Modules\Imports\Sii\Steps\AppointmentsStep;
use Illuminate\Support\Facades\File;
use Spatie\Activitylog\Models\Activity;
use Tests\Unit\Imports\Sii\SiiFixtures;

/*
| Paso `nombramientos` de imports:migrate-sii (R-V2-6): nombra a los titulares de titulares.csv por el RFC que se
| encuentra por nombre en el personal del SII. Datos sintéticos.
*/

beforeEach(function () {
    $this->dir = SiiFixtures::dir();
    File::copyDirectory(base_path('tests/Fixtures/imports/sii/minimo'), $this->dir);

    $this->direction = OrgUnit::query()->where('code', 'DIRECCION')->firstOrFail();
    $this->office = OrgUnit::factory()->create(['code' => 'OF-X', 'name' => 'Oficina X', 'kind' => OrgUnitKind::Oficina]);

    $this->staff = function (array $people): void {
        $staff = $staff2 = [];
        foreach ($people as $rfc => [$first, $last1, $last2]) {
            $staff[] = [
                'rfc' => $rfc, 'clave_area' => '110100', 'nombramiento' => 'A', 'status_empleado' => '01', 'nombre_empleado' => $first,
                'apellidos_empleado' => "{$last1} {$last2}", 'correo_electronico' => '', 'inicio_plantel' => '000000',
            ];
            $staff2[] = ['rfc' => $rfc, 'apellidop' => $last1, 'apellidom' => $last2, 'nombre_empleado' => $first, 'id_unidad_organica' => '110100', 'fecha_ingreso_institucion' => ''];
        }
        SiiFixtures::writeStaff($this->dir, $staff, $staff2);
    };
    $this->titulares = function (array $rows): void {
        SiiFixtures::write($this->dir.'/titulares.csv', SiiFile::Titulares, array_map(
            fn (array $r): string => SiiFixtures::line(SiiFile::Titulares, ['unidad' => "'{$r[0]}'", 'nombre' => "'{$r[1]}'", 'desde' => "'{$r[2]}'"]),
            $rows,
        ));
    };
    $this->run = function (bool $dryRun = false): array {
        $migration = new SiiMigration(SiiExport::open($this->dir), '/nonexistent', $dryRun);

        return iterator_to_array((new AppointmentsStep)->batches($migration), false);
    };
    ($this->staff)(['GOLM800101AA1' => ['MARÍA', 'GÓMEZ', 'LUNA'], 'RUPA800101AA1' => ['PAULA', 'RUIZ', 'PRIETO'], 'RUPB800101AA1' => ['PAULA', 'RUIZ', 'PEREZ']]);
});

afterEach(function () {
    File::deleteDirectory($this->dir);
});

it('nombra al titular encontrado por nombre sin acentos ni puntuación', function () {
    ($this->titulares)([['DIRECCION', 'Gómez, Luna. Maria', '2026-03-04'], ['SUB-ACADEMICA', 'Paula Ruiz Prieto', '2026-03-04'], ['OF-X', 'MARIA GOMEZ', '2026-03-04']]);

    $reports = ($this->run)();

    $maria = User::where('username', 'GOLM800101AA1')->sole();
    expect($maria->roleAssignments()->where('role', Role::Directivo)->where('org_unit_id', $this->direction->id)->count())->toBe(1)
        ->and($maria->roleAssignments()->where('role', Role::JefeDepartamento)->where('org_unit_id', $this->office->id)->count())->toBe(1)
        ->and(User::where('username', 'RUPA800101AA1')->sole()->roleAssignments()->where('role', Role::Subdireccion)->count())->toBe(1)
        ->and($maria->roleAssignments()->first()->valid_from->toDateString())->toBe('2026-03-04')
        ->and($reports)->toHaveCount(1)
        ->and($reports[0]->template)->toBe('appointments')
        ->and($reports[0]->created)->toBe(3);
});

it('un nombre que no está o que es ambiguo se avisa y no se nombra', function () {
    ($this->titulares)([['DIRECCION', 'Pedro Inexistente', '2026-03-04'], ['SUB-ACADEMICA', 'Paula Ruiz', '2026-03-04']]);

    $reports = ($this->run)();

    expect(User::where('username', 'like', 'RUP%')->get()->sum(fn (User $u) => $u->roleAssignments()->count()))->toBe(0)
        ->and($reports[0]->created)->toBe(0)
        ->and($reports[0]->warnings)->toBe(2)
        ->and($reports[0]->incidents[0]['ref'])->toBe('titulares.csv:2 (unidad DIRECCION)')
        ->and(json_encode($reports[0]->incidents))->not->toContain('Inexistente')->not->toContain('RUPA');
});

it('el titular que ya ocupa el puesto no se vuelve a nombrar', function () {
    ($this->titulares)([['DIRECCION', 'Maria Gomez Luna', '2026-03-04']]);
    ($this->run)();
    $logs = Activity::query()->count();

    $reports = ($this->run)();

    expect(Activity::query()->count())->toBe($logs)
        ->and(User::where('username', 'GOLM800101AA1')->sole()->roleAssignments()->count())->toBe(1)
        ->and($reports[0]->created)->toBe(0)
        ->and($reports[0]->valid)->toBe(1)
        ->and($reports[0]->warnings)->toBe(0);
});

it('si otra persona ocupa el puesto, avisa y no lo cambia', function () {
    ($this->titulares)([['DIRECCION', 'Maria Gomez Luna', '2026-03-04']]);
    ($this->run)();
    ($this->titulares)([['DIRECCION', 'Paula Ruiz Prieto', '2026-05-01']]);

    $reports = ($this->run)();

    expect(User::where('username', 'RUPA800101AA1')->exists())->toBeFalse()
        ->and($reports[0]->created)->toBe(0)
        ->and($reports[0]->warnings)->toBe(1);
});

it('un docente nombrado directivo conserva su puesto de docente y cambia de puesto en la credencial', function () {
    $teacher = Teacher::factory()->create(['rfc' => 'GOLM800101AA1', 'name' => 'Maria Gomez Luna']);
    ($this->titulares)([['DIRECCION', 'Maria Gomez Luna', '2026-03-04']]);

    ($this->run)();

    $user = User::where('username', 'GOLM800101AA1')->sole();
    expect($teacher->fresh()->user_id)->toBe($user->id)
        ->and($user->roleAssignments()->pluck('role')->all())->toContain(Role::Docente, Role::Directivo);
});

it('sin titulares.csv el paso avisa y no hace nada', function () {
    $reports = ($this->run)();

    expect($reports)->toHaveCount(1)
        ->and($reports[0]->rows)->toBe(0)
        ->and($reports[0]->warnings)->toBe(1)
        ->and(User::query()->count())->toBe(0);
});

it('una unidad desconocida o inactiva y una fecha inválida avisan y se omiten', function () {
    OrgUnit::factory()->inactive()->create(['code' => 'OLD', 'kind' => OrgUnitKind::Oficina]);
    ($this->titulares)([['NOPE', 'Maria Gomez Luna', '2026-03-04'], ['OLD', 'Maria Gomez Luna', '2026-03-04'], ['DIRECCION', 'Maria Gomez Luna', '04/03/2026']]);

    $reports = ($this->run)();

    expect($reports[0]->warnings)->toBe(3)
        ->and($reports[0]->created)->toBe(0)
        ->and(User::query()->count())->toBe(0);
});

it('un ensayo no escribe nada y reporta lo que haría', function () {
    ($this->titulares)([['DIRECCION', 'Maria Gomez Luna', '2026-03-04']]);

    $reports = ($this->run)(dryRun: true);

    expect($reports[0]->created)->toBe(1)
        ->and(User::query()->count())->toBe(0);
});
