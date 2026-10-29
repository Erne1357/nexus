<?php

use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\User;
use App\Modules\Core\Navigation\Navigation;
use App\Modules\Core\Navigation\NavigationItem;
use App\Modules\Core\Queries\ScopedContext;
use App\Modules\Imports\Actions\StoreImportBatch;
use App\Modules\Imports\Enums\BatchStatus;
use App\Modules\Imports\Jobs\ValidateQueuedBatch;
use App\Modules\Imports\Models\ImportBatch;
use App\Modules\Imports\Models\ImportMapping;
use App\Modules\Imports\Templates\TemplateRegistry;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportFileUploads\FileUploadConfiguration;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Fixtures\ImportTemplates\CampusesTemplate;
use Tests\Fixtures\ImportTemplates\CampusScopedTemplate;

/*
| Asistente de importación (`/imports`, imports::pages.import-wizard), pasos 1 y 2: el admin elige la
| plantilla y sube el archivo; el asistente lee su encabezado y propone qué columna va a cada campo,
| por nombre o con un mapeo guardado; si el admin cambia la propuesta, la guarda como mapeo nuevo. Al
| confirmar, sube el lote y lo valida en la cola. Como todo lo de importación, solo para el admin
| (reglas-de-negocio.md, "Aprobaciones y permisos", fila "Importar catálogos").
*/

beforeEach(function () {
    Storage::fake('imports');
    app()->instance(TemplateRegistry::class, new TemplateRegistry([CampusesTemplate::class, CampusScopedTemplate::class]));
    $this->c2 = Campus::factory()->create(['code' => 'C2', 'name' => 'Campus II']);
    $this->admin = User::factory()->admin()->create();
});

function campusCsv(string $contents = "clave,nombre,modalidad,extra\nC9,Campus Nueve,presencial,x\n"): UploadedFile
{
    return UploadedFile::fake()->createWithContent('campus.csv', $contents);
}

/**
 * El asistente en el paso 2, con el archivo leído.
 */
function wizardWithFile(User $user, string $template = 'test-campuses', ?UploadedFile $file = null): Testable
{
    return Livewire::actingAs($user)
        ->test('imports::pages.import-wizard')
        ->set('template', $template)
        ->set('file', $file ?? campusCsv())
        ->call('continue');
}

describe('admin', function () {
    it('muestra las plantillas registradas y los lotes recientes para retomarlos', function () {
        ImportBatch::factory()->create(['original_name' => 'kardex-octubre.csv']);

        Livewire::actingAs($this->admin)
            ->test('imports::pages.import-wizard')
            ->assertOk()
            ->assertSee('Campus (prueba)')
            ->assertSee('kardex-octubre.csv')
            ->assertSet('step', 1);
    });

    it('sin plantillas registradas avisa que todavía no hay', function () {
        app()->instance(TemplateRegistry::class, new TemplateRegistry([]));

        Livewire::actingAs($this->admin)
            ->test('imports::pages.import-wizard')
            ->assertSee('Todavía no hay plantillas de importación');
    });

    it('lee el encabezado del archivo y propone las columnas por nombre', function () {
        wizardWithFile($this->admin)
            ->assertHasNoErrors()
            ->assertSet('step', 2)
            ->assertSee('Columna del archivo')
            ->assertSet('header', ['clave', 'nombre', 'modalidad', 'extra'])
            ->assertSet('assignments', ['code' => 'clave', 'name' => 'nombre', 'modality' => 'modalidad']);
    });

    it('pide plantilla y archivo, y rechaza un archivo que no es .xlsx, .csv ni .txt', function () {
        Livewire::actingAs($this->admin)
            ->test('imports::pages.import-wizard')
            ->call('continue')
            ->assertHasErrors(['template' => 'required', 'file' => 'required'])
            ->set('template', 'test-campuses')
            ->set('file', UploadedFile::fake()->createWithContent('campus.xls', 'x'))
            ->call('continue')
            ->assertHasErrors('file')
            ->assertSee('El archivo debe ser .xlsx, .csv o .txt.')
            ->assertSet('step', 1);
    });

    it('un archivo que no se puede leer se queda en el paso 1 con el motivo', function () {
        wizardWithFile($this->admin, file: UploadedFile::fake()->createWithContent('vacio.csv', ''))
            ->assertHasErrors('file')
            ->assertSee('El archivo está vacío: no tiene encabezado.')
            ->assertSet('step', 1);
    });

    it('una plantilla de campus pide elegir el campus en la barra superior si no hay uno activo', function () {
        Campus::factory()->create(['code' => 'C1']);

        wizardWithFile($this->admin, 'test-campus-scoped')
            ->assertHasErrors('template')
            ->assertSee('Elige en la barra superior el campus al que pertenece el archivo.')
            ->assertSet('step', 1);
    });

    it('una plantilla de campus usa el campus de la barra superior', function () {
        // La plantilla de prueba por campus no tiene catálogo real que validar: basta ver que se encola.
        Queue::fake();
        Campus::factory()->create(['code' => 'C1']);
        session([ScopedContext::ACTIVE_CAMPUS => $this->c2->id]);

        wizardWithFile($this->admin, 'test-campus-scoped')
            ->assertSet('step', 2)
            ->assertSee('C2 · Campus II')
            ->call('upload')
            ->assertHasNoErrors();

        $batch = ImportBatch::sole();

        expect($batch->campus_id)->toBe($this->c2->id);
        Queue::assertPushed(ValidateQueuedBatch::class, fn (ValidateQueuedBatch $job): bool => $job->batch->is($batch));
    });

    it('sin cambios a la propuesta por nombre sube el lote sin mapeo y lo valida en la cola', function () {
        $component = wizardWithFile($this->admin)->call('upload')->assertHasNoErrors();

        $batch = ImportBatch::sole();

        expect($batch)
            ->mapping_id->toBeNull()
            ->status->toBe(BatchStatus::Validated)
            ->total_rows->toBe(1)
            ->and($batch->uploader->is($this->admin))->toBeTrue();

        $component->assertSet('batchId', $batch->id)->assertSet('step', 3);

        // La copia temporal de Livewire se borra: el archivo ya vive en el disco `imports` con su lote.
        expect(livewireTemporaryUploads())->toBe([])
            ->and(Storage::disk('imports')->exists($batch->file_path))->toBeTrue();
    });

    it('al elegir un mapeo guardado propone sus columnas y avisa las que no vienen en el archivo', function () {
        $mapping = ImportMapping::factory()->create(['template' => 'test-campuses', 'name' => 'SII 2026', 'columns' => ['clave' => 'code', 'extra' => 'name', 'MOD' => 'modality']]);

        wizardWithFile($this->admin)
            ->set('mappingId', $mapping->id)
            ->assertSet('assignments', ['code' => 'clave', 'name' => 'extra', 'modality' => ''])
            ->assertSee('El mapeo «SII 2026» espera columnas que el archivo no trae: MOD.')
            ->call('upload')
            ->assertHasNoErrors();

        expect(ImportBatch::sole()->mapping_id)->toBe($mapping->id);
    });

    it('no carga un mapeo de otra plantilla o desactivado', function () {
        $other = ImportMapping::factory()->create(['template' => 'test-campus-scoped']);

        wizardWithFile($this->admin)
            ->set('mappingId', $other->id)
            ->assertSet('mappingId', '')
            ->assertHasErrors('mappingId');
    });

    it('si el admin cambia la propuesta, pide un nombre y guarda el mapeo nuevo con el lote', function () {
        $component = wizardWithFile($this->admin)
            ->set('assignments.name', 'extra')
            ->call('upload')
            ->assertHasErrors('mappingName')
            ->assertSee('Ponle un nombre al mapeo para guardarlo: las columnas ya no son las de la propuesta.');

        expect(ImportBatch::count())->toBe(0);

        $component->set('mappingName', 'Exportación de octubre')->call('upload')->assertHasNoErrors();

        $mapping = ImportMapping::sole();

        expect($mapping)
            ->name->toBe('Exportación de octubre')
            ->columns->toBe(['clave' => 'code', 'extra' => 'name', 'modalidad' => 'modality'])
            ->and(ImportBatch::sole()->mapping_id)->toBe($mapping->id);
    });

    it('un nombre de mapeo que ya existe se muestra junto al nombre y no sube el lote', function () {
        ImportMapping::factory()->create(['template' => 'test-campuses', 'name' => 'Octubre']);

        wizardWithFile($this->admin)
            ->set('assignments.name', 'extra')
            ->set('mappingName', 'Octubre')
            ->call('upload')
            ->assertHasErrors('mappingName')
            ->assertSee('Ya hay un mapeo con ese nombre para esta plantilla.');

        expect(ImportBatch::count())->toBe(0);
    });

    it('sin la columna de la clave no sube nada', function () {
        wizardWithFile($this->admin)
            ->set('assignments.code', '')
            ->set('mappingName', 'Sin clave')
            ->call('upload')
            ->assertHasErrors('assignments')
            ->assertSee('Falta la columna de clave, que identifica cada registro.');

        expect(ImportBatch::count())->toBe(0)
            ->and(ImportMapping::count())->toBe(0);
    });

    it('rechaza una columna en dos campos y una columna que el archivo no tiene', function () {
        wizardWithFile($this->admin)
            ->set('assignments.name', 'clave')
            ->call('upload')
            ->assertHasErrors('assignments')
            ->assertSee('La columna clave está en dos campos: clave y nombre.')
            ->set('assignments.name', 'INVENTADA')
            ->call('upload')
            ->assertHasErrors('assignments.name');

        expect(ImportBatch::count())->toBe(0);
    });

    it('cambiar de archivo o empezar de nuevo borra la copia temporal y su json', function () {
        $page = Livewire::actingAs($this->admin)
            ->test('imports::pages.import-wizard')
            ->set('file', campusCsv())
            ->set('file', UploadedFile::fake()->createWithContent('otro.csv', "clave\nC1\n"));

        // Solo queda el segundo archivo, con su json.
        expect(livewireTemporaryUploads())->toHaveCount(2);

        $page->call('startOver');

        expect(livewireTemporaryUploads())->toBe([]);
    });

    it('no sube un archivo distinto del que se revisó', function () {
        wizardWithFile($this->admin)
            ->set('file', UploadedFile::fake()->createWithContent('otro.csv', "x,y\n1,2\n"))
            ->call('upload')
            ->assertHasErrors('file')
            ->assertSee('El archivo cambió después de revisar sus columnas: vuelve al paso 1 y revísalas otra vez.');

        expect(ImportBatch::count())->toBe(0);
    });

    it('si la cola no responde al subir, el lote queda subido y abierto en la página', function () {
        Bus::shouldReceive('dispatch')->once()->andThrow(new RuntimeException('Valkey no responde.'));

        $page = wizardWithFile($this->admin)->call('upload');

        $batch = ImportBatch::sole();

        expect($batch->status)->toBe(BatchStatus::Uploaded)
            ->and(lastToastOf($page))->type->toBe('error')->title->toBe('La cola no respondió');

        $page->assertSet('batchId', $batch->id)->assertSee('Validar de nuevo');
    });

    it('vuelve a encolar la validación de un lote que se quedó subido, y de ninguno más', function () {
        $uploaded = app(StoreImportBatch::class)->handle($this->admin, 'test-campuses', campusCsv());

        wizardForBatchId($this->admin, $uploaded->id)->call('requeue');

        expect($uploaded->fresh()->status)->toBe(BatchStatus::Validated);

        Queue::fake();
        wizardForBatchId($this->admin, $uploaded->id)->call('requeue');
        Queue::assertNothingPushed();
    });

    it('volver al paso 1 deja elegir otro archivo', function () {
        wizardWithFile($this->admin)->call('back')->assertSet('step', 1)->assertSet('header', []);
    });

    it('muestra el avance mientras valida y el motivo si falla', function () {
        $validating = ImportBatch::factory()->create(['status' => BatchStatus::Validating, 'processed_rows' => 1500]);
        $failed = ImportBatch::factory()->create(['status' => BatchStatus::Failed, 'failure_reason' => 'El archivo está vacío: no tiene encabezado.']);

        Livewire::actingAs($this->admin)
            ->withQueryParams(['lote' => $validating->id])
            ->test('imports::pages.import-wizard')
            ->assertSet('step', 3)
            ->assertSee('1,500 filas leídas');

        Livewire::actingAs($this->admin)
            ->withQueryParams(['lote' => $failed->id])
            ->test('imports::pages.import-wizard')
            ->assertSee('El archivo está vacío: no tiene encabezado.');
    });

    it('retoma un lote reciente y empieza otro desde cero', function () {
        $batch = ImportBatch::factory()->create(['original_name' => 'kardex-octubre.csv', 'status' => BatchStatus::Validating]);

        Livewire::actingAs($this->admin)
            ->test('imports::pages.import-wizard')
            ->call('open', $batch->id)
            ->assertSet('batchId', $batch->id)
            ->assertSet('step', 3)
            ->call('startOver')
            ->assertSet('batchId', null)
            ->assertSet('step', 1);
    });

    it('un lote que no existe o un id que no es uuid es un 404', function (string $id) {
        Livewire::actingAs($this->admin)
            ->test('imports::pages.import-wizard')
            ->call('open', $id)
            ->assertNotFound();
    })->with(['otro uuid' => '01900000-0000-7000-8000-000000000000', 'no es uuid' => '1 OR 1=1']);

    it('ve la entrada Importaciones en el menú', function () {
        expect(importWizardMenuVisibleTo($this->admin))->toBeTrue();
    });
});

/**
 * Las copias temporales de Livewire: en pruebas, en su propio disco (FileUploadConfiguration::disk()).
 *
 * @return list<string>
 */
function livewireTemporaryUploads(): array
{
    return Storage::disk(FileUploadConfiguration::disk())->allFiles(FileUploadConfiguration::directory());
}

function wizardForBatchId(User $user, string $batchId): Testable
{
    return Livewire::actingAs($user)->withQueryParams(['lote' => $batchId])->test('imports::pages.import-wizard');
}

function importWizardMenuVisibleTo(User $user): bool
{
    test()->actingAs($user);

    return collect(app(Navigation::class)->visibleTo(app(ScopedContext::class)))
        ->contains(fn (NavigationItem $item): bool => $item->route === 'imports.wizard');
}

describe('otros roles', function () {
    it('no entran a la página ni ven la entrada del menú', function (Role $role, array $scope) {
        $user = User::factory()->withRole($role, ['campus_id' => $this->c2->id, ...$scope])->create();

        $this->actingAs($user)->get(route('imports.wizard'))->assertForbidden();

        expect(importWizardMenuVisibleTo($user))->toBeFalse();
    })->with([
        'subdirección' => [Role::Subdireccion, []],
        'coordinador' => [Role::Coordinador, fn () => ['career_id' => careerKey(20)]],
        'jefe de departamento' => [Role::JefeDepartamento, fn () => ['department_id' => departmentKey(10)]],
        'docente' => [Role::Docente, []],
    ]);

    it('no pueden llamar las acciones del asistente aunque la página la haya abierto el admin', function (string $method, array $arguments) {
        $coordinador = User::factory()->withRole(Role::Coordinador, ['campus_id' => $this->c2->id, 'career_id' => careerKey(20)])->create();
        $batch = ImportBatch::factory()->create();
        $component = wizardWithFile($this->admin);

        $this->actingAs($coordinador);

        $component->call($method, ...($arguments === [] ? [] : [$batch->id]))->assertForbidden();

        expect(ImportBatch::whereKeyNot($batch->id)->count())->toBe(0);
    })->with([
        'continuar' => ['continue', []],
        'subir' => ['upload', []],
        'abrir un lote' => ['open', ['lote']],
        'volver a encolar' => ['requeue', []],
    ]);

    it('a quien no tiene sesión lo manda al login', function () {
        $this->get(route('imports.wizard'))->assertRedirect(route('login'));
    });
});
