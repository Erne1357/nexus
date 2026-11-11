<?php

use App\Modules\AcademicLoad\Database\Factories\LoadSnapshotFactory;
use App\Modules\AcademicLoad\Enums\ExportState;
use App\Modules\AcademicLoad\Enums\LoadStatus;
use App\Modules\AcademicLoad\Exports\PublishedExports;
use App\Modules\AcademicLoad\Exports\Sii\SiiFilesExport;
use App\Modules\AcademicLoad\Exports\Sii\SiiLoadExport;
use App\Modules\AcademicLoad\Models\AcademicLoad;
use App\Modules\AcademicLoad\Models\LoadSnapshot;
use App\Modules\Core\Authorization\OrgTree;
use App\Modules\Core\Enums\OrgUnitKind;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\OrgUnit;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\User;
use Illuminate\Support\Facades\Storage;

/*
| Los archivos para el SII como exporte del personal (tarea 4.6): un zip con grupos.csv y horarios.csv de las versiones
| vigentes del campus, con una llave que cambia al publicar otra versión. Lo descargan Servicios Escolares, Subdirección y
| el admin, no los jefes de los departamentos académicos (supuesto por confirmar).
*/

beforeEach(function () {
    Storage::fake(PublishedExports::DISK);
    $this->term = Term::factory()->create(['code' => '20271']);
    $this->campus = Campus::factory()->create(['code' => 'C2']);
    $this->services = OrgUnit::factory()->create(['code' => OrgTree::SCHOOL_SERVICES, 'name' => 'Departamento de Servicios Escolares', 'kind' => OrgUnitKind::Departamento, 'parent_id' => OrgUnit::query()->where('code', OrgTree::ACADEMIC)->sole()->id]);
});

function siiVersion(?AcademicLoad $load = null, int $version = 1, string $code = 'A'): LoadSnapshot
{
    $load ??= AcademicLoad::factory()->inStatus(LoadStatus::Published)->create(['term_id' => test()->term->id, 'campus_id' => test()->campus->id, 'department_id' => departmentKey(10)]);

    return LoadSnapshot::factory()->for($load)->withGroups([LoadSnapshotFactory::group(['code' => $code])])->create(['version' => $version]);
}

it('sin nada publicado no hay exporte', function () {
    expect(SiiFilesExport::for($this->term, $this->campus))->toBeNull();
});

it('nombra el archivo y la llave con el periodo, el campus y las versiones que lee', function () {
    $first = siiVersion();
    $export = SiiFilesExport::for($this->term, $this->campus);

    expect($export->filename())->toBe('SII 20271 C2.zip')
        ->and($export->mimeType())->toBe('application/zip')
        ->and($export->key())->toStartWith('sii_20271_C2_')
        ->and($export->key())->toMatch('/^[A-Za-z0-9_-]+$/')
        ->and(SiiFilesExport::for($this->term, $this->campus)->key())->toBe($export->key());

    siiVersion($first->academicLoad, 2, 'B');

    expect(SiiFilesExport::for($this->term, $this->campus)->key())->not->toBe($export->key());
});

it('arma un zip con los dos archivos de las versiones que fijó al pedirse', function () {
    $first = siiVersion();
    $export = SiiFilesExport::for($this->term, $this->campus);
    $expected = app(SiiLoadExport::class)->files($this->term, $this->campus);
    siiVersion($first->academicLoad, 2, 'Z');

    $path = tempnam(sys_get_temp_dir(), 'test');
    file_put_contents($path, $export->build());
    $zip = new ZipArchive;
    $zip->open($path);

    expect($zip->numFiles)->toBe(2)
        ->and($zip->getFromName('grupos.csv'))->toBe($expected['grupos.csv'])
        ->and($zip->getFromName('horarios.csv'))->toBe($expected['horarios.csv'])
        ->and($zip->getFromName('grupos.csv'))->not->toContain("'Z  '");

    $zip->close();
    unlink($path);
});

it('se genera y descarga con PublishedExports', function () {
    siiVersion();
    $export = SiiFilesExport::for($this->term, $this->campus);

    expect(app(PublishedExports::class)->request($export))->toBe(ExportState::Ready);

    Storage::disk(PublishedExports::DISK)->assertExists('published/'.$export->key().'.zip');
});

it('lo descargan Servicios Escolares, Subdirección y el admin, no el jefe de un departamento académico ni el coordinador', function (Closure $user, bool $allowed) {
    siiVersion();
    $url = route('published.export', ['periodo' => '20271', 'campus' => 'C2', 'tipo' => 'sii']);

    $user = $user();

    expect($user->can('download', SiiFilesExport::for($this->term, $this->campus)))->toBe($allowed);

    $this->actingAs($user)->get($url)->assertStatus($allowed ? 200 : 403);
})->with([
    'admin' => [fn () => User::factory()->admin()->create(), true],
    'Servicios Escolares' => [fn () => User::factory()->withRole(Role::JefeDepartamento, ['org_unit_id' => test()->services->id])->create(), true],
    'subdirección' => [fn () => User::factory()->withRole(Role::Subdireccion)->create(), true],
    'jefe de un departamento académico' => [fn () => User::factory()->withRole(Role::JefeDepartamento, ['department_id' => departmentKey(10)])->create(), false],
    'coordinador' => [fn () => User::factory()->withRole(Role::Coordinador, ['campus_id' => test()->campus->id, 'career_id' => careerKey(20)])->create(), false],
]);

it('la consulta ofrece «Archivos para el SII» solo a quien puede descargarlos', function () {
    siiVersion();
    $index = route('published.index', ['periodo' => '20271', 'campus' => 'C2']);

    $this->actingAs(User::factory()->withRole(Role::JefeDepartamento, ['org_unit_id' => $this->services->id])->create())->get($index)
        ->assertSee('Archivos para el SII');
    $this->actingAs(User::factory()->withRole(Role::JefeDepartamento, ['department_id' => departmentKey(10)])->create())->get($index)
        ->assertDontSee('Archivos para el SII');
});
