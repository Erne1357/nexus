<?php

use App\Modules\AcademicLoad\Database\Factories\LoadSnapshotFactory;
use App\Modules\AcademicLoad\Enums\ExportState;
use App\Modules\AcademicLoad\Enums\LoadStatus;
use App\Modules\AcademicLoad\Exports\PublishedExports;
use App\Modules\AcademicLoad\Exports\Sii\SiiExport;
use App\Modules\AcademicLoad\Exports\Sii\SiiLoadExport;
use App\Modules\AcademicLoad\Models\AcademicLoad;
use App\Modules\AcademicLoad\Models\LoadSnapshot;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\User;
use Illuminate\Support\Facades\Storage;

/*
| El exporte al SII como exporte de lo publicado (tarea 4.6): un zip con grupos.csv y horarios.csv de las versiones
| vigentes del campus, con una llave que cambia al publicar otra versión. Lo descargan admin y Subdirección (supuesto
| por confirmar).
*/

beforeEach(function () {
    Storage::fake(PublishedExports::DISK);
    $this->term = Term::factory()->create(['code' => '20271']);
    $this->campus = Campus::factory()->create(['code' => 'C2']);
});

function siiVersion(?AcademicLoad $load = null, int $version = 1, string $code = 'A'): LoadSnapshot
{
    $load ??= AcademicLoad::factory()->inStatus(LoadStatus::Published)->create(['term_id' => test()->term->id, 'campus_id' => test()->campus->id]);

    return LoadSnapshot::factory()->for($load)->withGroups([LoadSnapshotFactory::group(['code' => $code])])->create(['version' => $version]);
}

it('nombra el archivo y la llave con el periodo, el campus y las versiones que lee', function () {
    $first = siiVersion();
    $export = SiiExport::for($this->term, $this->campus);

    expect($export->filename())->toBe('SII 20271 C2.zip')
        ->and($export->mimeType())->toBe('application/zip')
        ->and($export->key())->toStartWith('sii_20271_C2_')
        ->and($export->key())->toMatch('/^[A-Za-z0-9_-]+$/')
        ->and(SiiExport::for($this->term, $this->campus)->key())->toBe($export->key());

    siiVersion($first->academicLoad, 2, 'B');

    expect(SiiExport::for($this->term, $this->campus)->key())->not->toBe($export->key());
});

it('arma un zip con los dos archivos de las versiones que fijó al pedirse', function () {
    $first = siiVersion();
    $export = SiiExport::for($this->term, $this->campus);
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
    $export = SiiExport::for($this->term, $this->campus);

    expect(app(PublishedExports::class)->request($export))->toBe(ExportState::Ready);

    Storage::disk(PublishedExports::DISK)->assertExists('published/'.$export->key().'.zip');
});

it('lo descargan admin y Subdirección, no el jefe ni el coordinador', function (Closure $user, bool $allowed) {
    siiVersion();

    expect($user()->can('download', SiiExport::for($this->term, $this->campus)))->toBe($allowed);
})->with([
    'admin' => [fn () => User::factory()->admin()->create(), true],
    'subdirección' => [fn () => User::factory()->withRole(Role::Subdireccion)->create(), true],
    'jefe de departamento' => [fn () => User::factory()->withRole(Role::JefeDepartamento, ['department_id' => departmentKey(10)])->create(), false],
    'coordinador' => [fn () => User::factory()->withRole(Role::Coordinador, ['campus_id' => test()->campus->id, 'career_id' => careerKey(20)])->create(), false],
]);
