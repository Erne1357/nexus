<?php

use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\User;
use App\Modules\Imports\Actions\ExportBatchErrors;
use App\Modules\Imports\Actions\StoreImportBatch;
use App\Modules\Imports\Actions\ValidateImportBatch;
use App\Modules\Imports\Templates\TemplateRegistry;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Fixtures\ImportTemplates\CampusesTemplate;

/*
| Asistente de importación en el navegador, con un archivo pequeño (tarea 1.2): el admin entra desde el
| menú, retoma un lote ya validado, revisa sus errores y lo aplica; un coordinador no ve la entrada del
| menú.
|
| La subida no se prueba aquí: el servidor de pruebas de pest-plugin-browser deja plano el campo
| `files[]` con que Livewire sube un archivo (LaravelHttpServer::parseMultipartBody), así que la subida
| nunca llega. Subir y mapear las columnas lo cubre tests/Feature/Imports/ImportWizardPageTest.php.
*/

beforeEach(function () {
    Storage::fake('imports');
    app()->instance(TemplateRegistry::class, new TemplateRegistry([CampusesTemplate::class]));
    $this->c2 = Campus::factory()->create(['code' => 'C2', 'name' => 'Campus II']);

    $this->admin = User::factory()->admin()->create(['username' => 'admin', 'name' => 'Ana Admin']);
    User::factory()
        ->withRole(Role::Coordinador, ['campus_id' => $this->c2->id, 'career_id' => fakeKey(20)])
        ->create(['username' => '19110001', 'name' => 'Carlos Coordinador']);
});

function signInToImports(string $username)
{
    return visit('/login')
        ->fill('username', $username)
        ->fill('password', 'password')
        ->press('Entrar')
        ->assertPathIs('/');
}

it('el admin retoma un lote validado desde el menú, revisa sus errores y lo aplica', function () {
    $batch = app(StoreImportBatch::class)->handle(
        $this->admin,
        'test-campuses',
        UploadedFile::fake()->createWithContent('campus.csv', "clave,nombre,modalidad\nC9,Campus Nueve,presencial\nC8,,presencial\n"),
    );
    app(ValidateImportBatch::class)->handle($batch);
    app(ExportBatchErrors::class)->handle($batch);

    $page = signInToImports('admin')
        ->click('Importaciones')
        ->assertPathIs('/imports')
        ->assertSee('Lotes recientes')
        ->click("@open-batch-{$batch->id}")
        ->waitForText('Descargar errores')
        ->assertQueryStringHas('lote', $batch->id)
        ->assertSee('Fila 3')
        ->assertSee('nombre: El campo nombre es obligatorio.');

    // Playwright descarta los diálogos: se acepta el wire:confirm de "Aplicar" a mano.
    $page->script('window.confirm = () => true');

    $page->press('Aplicar 1 fila')
        ->waitForText('Con error, sin aplicar')
        ->assertSee('Aplicado')
        ->assertNoJavaScriptErrors();

    expect(Campus::where('code', 'C9')->exists())->toBeTrue()
        ->and(Campus::where('code', 'C8')->exists())->toBeFalse();
});

it('un coordinador no ve la entrada Importaciones en el menú', function () {
    signInToImports('19110001')
        ->assertSeeIn('[aria-label="Navegación principal"]', 'Inicio')
        ->assertDontSeeIn('[aria-label="Navegación principal"]', 'Importaciones')
        ->assertNoJavaScriptErrors();
});
