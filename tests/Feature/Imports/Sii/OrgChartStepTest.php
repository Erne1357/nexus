<?php

use App\Modules\Academic\Models\Department;
use App\Modules\Core\Models\OrgUnit;
use App\Modules\Core\Models\User;
use App\Modules\Imports\Sii\SiiExport;
use App\Modules\Imports\Sii\SiiMigration;
use App\Modules\Imports\Sii\Steps\OrgChartStep;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

/*
| Paso `organigrama` de imports:migrate-sii (D14): los departamentos académicos que imparten (y los viejos,
| inactivos) con su clave del SII, las coordinaciones vacías como oficinas inactivas bajo su departamento, y el
| mapa de cada área del SII a su departamento. El archivo del SII de estas pruebas es sintético: se arma con las
| claves del catálogo curado real.
*/

/**
 * @param  list<string>  $without
 * @param  list<string>  $extra
 */
function writeSiiOrgChart(string $dir, array $without = [], array $extra = []): void
{
    $curated = (new SiiMigration(SiiExport::open($dir), base_path('app/Modules/Imports/Sii/Curated')))->curated('organigrama');
    $keys = array_values(array_diff([...array_column($curated, 'clave_sii'), ...$extra], $without));

    $lines = ['clave_area,descripcion_area,area_depende,nivel,tipo_area,p_sustantivos,pro_sus,p_sus,p_s'];
    foreach ($keys as $key) {
        $lines[] = "{$key},AREA {$key},100000,1,X,,,,";
    }
    File::put("{$dir}/organigrama.csv", implode("\n", $lines)."\n");
}

beforeEach(function () {
    Storage::fake('imports');
    app()->instance('sii.steps', [OrgChartStep::class]);

    $this->dir = sys_get_temp_dir().'/sii-'.bin2hex(random_bytes(6));
    File::copyDirectory(base_path('tests/Fixtures/imports/sii/minimo'), $this->dir);
    writeSiiOrgChart($this->dir);
    User::factory()->admin()->create(['username' => 'migrador']);

    $this->migrate = fn () => $this->artisan('imports:migrate-sii', ['dir' => $this->dir, '--user' => 'migrador']);
});

afterEach(function () {
    File::deleteDirectory($this->dir);
});

it('crea los siete departamentos activos y los viejos inactivos', function () {
    ($this->migrate)()->assertSuccessful();

    $active = Department::query()->where('is_active', true)->orderBy('code')->pluck('name', 'code')->all();
    $inactive = Department::query()->where('is_active', false)->orderBy('code')->pluck('code')->all();

    expect(array_map('strval', array_keys($active)))->toBe(['110100', '110200', '110300', '110500', '110600', '110700', '110900'])
        ->and($active['110100'])->toBe('Ciencias Básicas')
        ->and($active['110900'])->toBe('Estudios de Posgrado e Investigación')
        ->and(array_map('strval', $inactive))->toBe(['110303', '111200', '111300', '112300']);
});

it('Posgrado queda bajo la Subdirección Académica, como todo departamento', function () {
    ($this->migrate)()->assertSuccessful();

    $subdirection = OrgUnit::query()->where('code', 'SUB-ACADEMICA')->value('id');

    expect(OrgUnit::query()->where('code', '110900')->value('parent_id'))->toBe($subdirection);
});

it('las coordinaciones vacías quedan inactivas bajo su departamento', function () {
    ($this->migrate)()->assertSuccessful();

    $unit = OrgUnit::query()->where('code', '110201')->sole();

    expect($unit->kind->value)->toBe('oficina')
        ->and($unit->is_active)->toBeFalse()
        ->and($unit->parent->code)->toBe('110200')
        ->and(OrgUnit::query()->where('code', '110703')->sole()->parent->code)->toBe('110700');
});

it('mapea las subáreas a su departamento', function () {
    $migration = new SiiMigration(SiiExport::open($this->dir), base_path('app/Modules/Imports/Sii/Curated'));
    iterator_to_array((new OrgChartStep)->batches($migration));

    $map = $migration->remember('department_for_area', fn () => throw new LogicException('debía estar armado'));

    expect($map['110703'])->toBe('110700')
        ->and($map['110701'])->toBe('110700')
        ->and($map['110900'])->toBe('110900')
        ->and($map['100000'])->toBeNull()
        ->and($map['999999'])->toBeNull()
        ->and(array_key_exists('110102', $map))->toBeTrue();
});

it('una unidad omitida no se crea', function () {
    ($this->migrate)()->assertSuccessful();

    expect(OrgUnit::query()->whereIn('code', ['100000', '110102', '999999'])->exists())->toBeFalse()
        ->and(Department::query()->whereIn('code', ['100000', '110102'])->exists())->toBeFalse();
});

it('un departamento existente inactivo no se reactiva', function () {
    Department::factory()->create(['code' => '110100', 'name' => 'Otro nombre', 'is_active' => false]);

    ($this->migrate)()->assertSuccessful();

    expect(Department::query()->where('code', '110100')->sole())
        ->name->toBe('Ciencias Básicas')
        ->is_active->toBeFalse();
});

it('repetir el paso no cambia nada', function () {
    ($this->migrate)()->assertSuccessful();
    $units = OrgUnit::count();

    ($this->migrate)()->assertSuccessful();

    expect(OrgUnit::count())->toBe($units);
});

it('se detiene antes de escribir si al SII le falta o le sobra un área curada', function (array $without, array $extra, string $mentioned) {
    writeSiiOrgChart($this->dir, $without, $extra);
    $migration = new SiiMigration(SiiExport::open($this->dir), base_path('app/Modules/Imports/Sii/Curated'));

    expect(fn () => iterator_to_array((new OrgChartStep)->batches($migration)))
        ->toThrow(RuntimeException::class, $mentioned)
        ->and(Department::count())->toBe(0);
})->with([
    'falta una' => [['110201'], [], '110201'],
    'sobra una' => [[], ['777777'], '777777'],
]);

it('la consola muestra qué áreas no coinciden con el catálogo curado', function () {
    writeSiiOrgChart($this->dir, [], ['777777']);

    ($this->migrate)()
        ->expectsOutputToContain('777777')
        ->assertFailed();

    expect(Department::count())->toBe(0);
});
