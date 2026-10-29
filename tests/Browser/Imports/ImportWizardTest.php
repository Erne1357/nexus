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
        ->withRole(Role::Coordinador, ['campus_id' => $this->c2->id, 'career_id' => careerKey(20)])
        ->create(['username' => '19110001', 'name' => 'Carlos Coordinador']);
});

function signInToImports(string $username)
{
    return visit('/login')
        ->fill('username', $username)
        ->fill('password', 'password')
        ->press('Entrar')
        ->assertPathIs('/')
        ->assertScript('window.Alpine?.$data(document.body).menuOpen === false');
}

it('el admin retoma un lote validado desde el menú, revisa sus errores y lo aplica', function () {
    $batch = app(StoreImportBatch::class)->handle(
        $this->admin,
        'test-campuses',
        UploadedFile::fake()->createWithContent('campus.csv', "clave,nombre,modalidad\nC9,Campus Nueve,presencial\nC8,,presencial\n"),
    );
    app(ValidateImportBatch::class)->handle($batch);
    app(ExportBatchErrors::class)->handle($batch);

    // Importaciones es del área Datos: se llega abriendo el menú completo con la liebre.
    $page = signInToImports('admin')
        ->click('@hare')
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

it('el admin aplica un lote desactivando lo que no viene en el archivo, con la cifra en la confirmación', function () {
    $c5 = Campus::factory()->create(['code' => 'C5', 'name' => 'Campus Cinco']);
    $batch = app(StoreImportBatch::class)->handle(
        $this->admin,
        'test-campuses',
        UploadedFile::fake()->createWithContent('campus.csv', "clave,nombre,modalidad\nC9,Campus Nueve,presencial\nC2,Campus II,presencial\n"),
    );
    app(ValidateImportBatch::class)->handle($batch);

    $page = signInToImports('admin')
        ->navigate("/imports?lote={$batch->id}")
        ->waitForText('1 de 2 registros activos de Campus (prueba) no viene en el archivo.')
        ->assertDontSee('Campus Cinco')
        ->click('Desactivar los que no vienen en el archivo')
        ->waitForText('Campus Cinco')
        ->assertSee('Aplicar 2 filas y desactivar 1');

    // Playwright descarta los diálogos: se guarda el texto del wire:confirm y se acepta.
    $page->script('window.confirm = (message) => { window.confirmed = message; return true; }');

    $page->press('Aplicar 2 filas y desactivar 1')
        ->waitForText('1 registro desactivado por no venir en el archivo')
        ->assertScript('window.confirmed.includes("Se desactivará 1 registro que no viene en el archivo")')
        ->assertSee('Campus Cinco')
        ->assertNoJavaScriptErrors();

    expect($c5->fresh()->is_active)->toBeFalse()
        ->and($this->c2->fresh()->is_active)->toBeTrue();
});

it('un coordinador no ve la entrada Importaciones en el menú', function () {
    signInToImports('19110001')
        ->assertSeeIn('[aria-label="Navegación principal"]', 'Inicio')
        ->click('@hare')
        ->assertVisible('[data-menu-area="academico"] [data-menu-flip="name"]')
        ->assertDontSeeIn('[aria-label="Navegación principal"]', 'Importaciones')
        ->assertNotPresent('[data-menu-area="datos"]')
        ->assertNoJavaScriptErrors();
});
