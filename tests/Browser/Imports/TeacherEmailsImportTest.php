<?php

use App\Modules\Academic\Models\Department;
use App\Modules\CatalogValidation\Enums\Catalog;
use App\Modules\CatalogValidation\Models\CatalogReview;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\User;
use App\Modules\Faculty\Models\Teacher;
use App\Modules\Imports\Actions\StoreImportBatch;
use App\Modules\Imports\Actions\ValidateImportBatch;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

/*
| El admin importa los correos institucionales del archivo de TI y ve el resumen (tarea 1.21, D4): retoma el lote ya
| validado (la subida no se prueba en el navegador, ver ImportWizardTest), ve antes de aplicar los RFC que no están,
| los correos fuera del dominio, los repetidos y los que ya tenían otro correo, lo aplica y ve cuántas invitaciones
| salieron. A 390 px el resumen cabe sin desplazar la página a los lados.
*/

beforeEach(function () {
    Storage::fake('imports');
    Notification::fake();
    $term = Term::factory()->current()->create();
    $sc = Department::factory()->create(['code' => 'SC', 'name' => 'Sistemas y Computación']);
    $ii = Department::factory()->create(['code' => 'II', 'name' => 'Ingeniería Industrial']);
    CatalogReview::factory()->validated()->create(['term_id' => $term->id, 'department_id' => $sc->id, 'catalog' => Catalog::Teachers]);

    foreach ([
        ['PRUE090001AB1', $sc, null], ['PRUE090002AB1', $ii, null], ['PRUE090003AB1', $sc, 'docente.tres@cdjuarez.tecnm.mx'],
        ['PRUE090004AB1', $sc, 'docente.cuatro@cdjuarez.tecnm.mx'], ['PRUE090005AB1', $sc, null], ['PRUE090006AB1', $sc, null], ['PRUE090007AB1', $sc, null],
    ] as [$rfc, $department, $email]) {
        $user = User::factory()->withRole(Role::Docente)->create(['username' => $rfc, 'email' => null, 'password' => null, 'must_change_password' => true]);
        Teacher::factory()->adscribedTo($department)->create(['rfc' => $rfc, 'institutional_email' => $email, 'user_id' => $user->id]);
    }

    $this->admin = User::factory()->admin()->create(['username' => 'admin', 'name' => 'Ana Admin']);
    $this->batch = app(ValidateImportBatch::class)->handle(app(StoreImportBatch::class)->handle(
        $this->admin,
        'teacher_emails',
        UploadedFile::fake()->createWithContent('correos-ti.csv', (string) file_get_contents(base_path('tests/Fixtures/imports/teacher_emails.csv'))),
    ));
});

it('AC-19: el admin revisa el resumen de los correos, los aplica y ve cuántas invitaciones salieron', function () {
    $page = visit('/login')
        ->fill('username', 'admin')
        ->fill('password', 'password')
        ->press('Entrar')
        ->assertPathIs('/')
        ->navigate("/imports?lote={$this->batch->id}")
        ->waitForText('Resumen de correos institucionales de docentes')
        ->assertSeeIn('@batch-summary', 'RFC que no están en Nexus')
        ->assertSeeIn('@batch-summary', 'PRUE099999AB1')
        ->assertSeeIn('@batch-summary', 'Correos fuera del dominio')
        ->assertSeeIn('@batch-summary', 'Repetidos')
        ->assertSeeIn('@batch-summary', 'Ya tenían otro correo')
        ->assertDontSeeIn('@batch-summary', 'Invitaciones enviadas');

    $page->press('Aplicar 3 filas')
        ->assertSeeIn('#nexus-confirm', '¿Aplicar 3 filas?')
        ->click('@confirm-accept')
        ->waitForText('Invitaciones enviadas')
        ->assertSee('Aplicado')
        ->assertScript("[...document.querySelectorAll('[data-testid=batch-summary] dl > div')].find((group) => group.textContent.includes('Invitaciones enviadas'))?.textContent.replace(/\\s+/g, ' ').trim()", '1 Invitaciones enviadas PRUE090001AB1 A los docentes de un departamento que todavía no valida su catálogo de docentes les llega al validarlo.')
        ->assertNoJavaScriptErrors();

    $page->resize(390, 844)
        ->assertScript('document.documentElement.scrollWidth <= window.innerWidth', true)
        ->assertNoJavaScriptErrors();

    expect(Teacher::where('rfc', 'PRUE090001AB1')->sole()->institutional_email)->toBe('docente.uno@cdjuarez.tecnm.mx')
        ->and(Teacher::where('rfc', 'PRUE090004AB1')->sole()->institutional_email)->toBe('docente.cuatro@cdjuarez.tecnm.mx');
});
