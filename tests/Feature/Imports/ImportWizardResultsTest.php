<?php

use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\User;
use App\Modules\Imports\Actions\ExportBatchErrors;
use App\Modules\Imports\Actions\RetryStalledBatch;
use App\Modules\Imports\Actions\StoreImportBatch;
use App\Modules\Imports\Actions\ValidateImportBatch;
use App\Modules\Imports\Enums\BatchStatus;
use App\Modules\Imports\Enums\RowStatus;
use App\Modules\Imports\Jobs\ApplyQueuedBatch;
use App\Modules\Imports\Models\ImportBatch;
use App\Modules\Imports\Models\ImportRow;
use App\Modules\Imports\Templates\TemplateRegistry;
use Dom\Element;
use Dom\HTMLDocument;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Fixtures\ImportTemplates\CampusesTemplate;

/*
| Asistente de importación (`/imports`), pasos 3 y 4: el admin revisa la validación del lote (cuentas,
| filas con error o con advertencia y sus mensajes), descarga los errores y aplica; la aplicación
| corre en la cola y la página muestra su avance y lo que escribió. Se aplican las filas válidas y las
| que traen advertencia; las de error se saltan (reglas-de-negocio.md, "Reglas de datos"). Solo el
| admin aplica (fila "Importar catálogos").
*/

beforeEach(function () {
    Storage::fake('imports');
    app()->instance(TemplateRegistry::class, new TemplateRegistry([CampusesTemplate::class]));
    $this->admin = User::factory()->admin()->create();
    Campus::factory()->inactive()->create(['code' => 'C1', 'name' => 'Viejo']);

    $this->batch = app(ValidateImportBatch::class)->handle(app(StoreImportBatch::class)->handle(
        $this->admin,
        'test-campuses',
        UploadedFile::fake()->createWithContent('campus.csv', "code,name,modality\nC9,Campus Nueve,presencial\nC1,Campus Uno,presencial\nC8,,presencial\n"),
    ));
    app(ExportBatchErrors::class)->handle($this->batch);
});

function wizardForBatch(User $user, ImportBatch $batch): Testable
{
    return Livewire::actingAs($user)->withQueryParams(['lote' => $batch->id])->test('imports::pages.import-wizard');
}

describe('admin', function () {
    it('muestra las cuentas del lote validado y sus filas con error, con la etiqueta de cada campo', function () {
        wizardForBatch($this->admin, $this->batch)
            ->assertSet('step', 3)
            ->assertSeeInOrder(['Filas', '3', 'Válidas', '2', 'Con advertencia', '1', 'Con error', '1'])
            ->assertSee('Fila 4')
            ->assertSee('nombre: El campo nombre es obligatorio.')
            ->assertDontSee('Fila 2')
            ->assertSee(route('imports.batch-errors', $this->batch->id));
    });

    it('mientras la cola prepara el archivo de errores lo dice y se sigue refrescando', function () {
        Storage::disk('imports')->delete($this->batch->errors_path);

        wizardForBatch($this->admin, $this->batch)
            ->assertSee('Preparando el archivo de errores…')
            ->assertSeeHtml('wire:poll')
            ->assertDontSee(route('imports.batch-errors', $this->batch->id));
    });

    it('cambia a las filas que se aplican con advertencia', function () {
        wizardForBatch($this->admin, $this->batch)
            ->set('issues', 'warnings')
            ->assertSee('Fila 3')
            ->assertSee('clave: El registro está inactivo: se actualizan sus datos, pero sigue inactivo.')
            ->assertDontSee('Fila 4');
    });

    it('pinta el estado del lote y el tipo de cada mensaje con la insignia de estado de DESIGN.md', function () {
        $badgeClasses = fn (Testable $page, string $text): array => collect(HTMLDocument::createFromString('<!DOCTYPE html><body>'.$page->html().'</body>', LIBXML_NOERROR)->querySelectorAll('.badge'))
            ->filter(fn (Element $badge): bool => trim($badge->textContent) === $text)
            ->map(fn (Element $badge): array => iterator_to_array($badge->classList, false))
            ->values()
            ->all();

        $page = wizardForBatch($this->admin, $this->batch);

        expect($badgeClasses($page, 'Validado'))->toHaveCount(1)->each->toContain('badge-success', 'badge-soft')
            ->and($badgeClasses($page, 'Error'))->not->toBeEmpty()->each->toContain('badge-error', 'badge-soft');

        expect($badgeClasses($page->set('issues', 'warnings'), 'Advertencia'))->not->toBeEmpty()->each->toContain('badge-warning', 'badge-soft');
    });

    it('sin filas con error no ofrece descargar errores', function () {
        ImportRow::where('status', RowStatus::Error)->delete();
        $this->batch->update(['error_rows' => 0, 'valid_rows' => 2, 'total_rows' => 2]);

        wizardForBatch($this->admin, $this->batch)
            ->assertSee('Ninguna fila trae error.')
            ->assertDontSee(route('imports.batch-errors', $this->batch->id));
    });

    it('aplica en la cola las filas válidas y muestra lo que escribió', function () {
        $page = wizardForBatch($this->admin, $this->batch)->call('apply')->assertHasNoErrors();

        expect($this->batch->fresh())
            ->status->toBe(BatchStatus::Applied)
            ->and($this->batch->fresh()->applier->is($this->admin))->toBeTrue()
            ->and(Campus::where('code', 'C9')->exists())->toBeTrue();

        $page->assertSet('step', 4)
            ->assertSeeInOrder(['Altas', '1', 'Cambios', '1', 'Sin cambios', '0', 'Con error, sin aplicar', '1']);
    });

    it('después de mandar a aplicar dice que está en la cola y se refresca hasta que la cola lo toma', function () {
        Queue::fake();

        wizardForBatch($this->admin, $this->batch)
            ->call('apply')
            ->assertSee('En la cola: la aplicación empieza en cuanto se libere el proceso.')
            ->assertSeeHtml('wire:poll')
            ->assertDontSee('Aplicar 2 filas');

        Queue::assertPushed(ApplyQueuedBatch::class, fn (ApplyQueuedBatch $job): bool => $job->batch->is($this->batch) && $job->user->is($this->admin));
    });

    it('si la cola no responde al aplicar lo dice y deja intentarlo otra vez', function () {
        Bus::shouldReceive('dispatch')->once()->andThrow(new RuntimeException('Valkey no responde.'));

        $page = wizardForBatch($this->admin, $this->batch)->call('apply');

        expect(lastToastOf($page))->type->toBe('error')->title->toBe('La cola no respondió');
        $page->assertSee('Aplicar 2 filas');
    });

    it('no ofrece el archivo de errores mientras la cola no lo rehace', function () {
        // Al empezar a aplicar, el archivo de la validación deja de valer hasta que la cola lo rehace.
        $this->batch->update(['errors_exported_at' => null]);

        wizardForBatch($this->admin, $this->batch)
            ->assertSee('Preparando el archivo de errores…')
            ->assertDontSee(route('imports.batch-errors', $this->batch->id));
    });

    it('si el archivo de errores no llega en diez minutos deja de esperarlo y lo dice', function () {
        Storage::disk('imports')->delete($this->batch->errors_path);
        $this->travel(11)->minutes();

        wizardForBatch($this->admin, $this->batch)
            ->assertSee('No se pudo preparar el archivo de errores.')
            ->assertDontSeeHtml('wire:poll');
    });

    it('no aplica un lote que ya no está validado ni uno sin filas válidas', function (array $attributes, string $message) {
        Queue::fake();
        $this->batch->update($attributes);

        $page = wizardForBatch($this->admin, $this->batch)->call('apply');

        expect(lastToastOf($page))->type->toBe('error')->description->toBe($message);
        Queue::assertNothingPushed();
    })->with([
        'ya se aplica' => [['status' => BatchStatus::Applying], 'El lote ya no está validado: está Aplicando.'],
        'sin filas válidas' => [['valid_rows' => 0], 'El lote no tiene filas válidas que aplicar.'],
    ]);

    it('muestra el avance mientras aplica y lo escrito si la aplicación se interrumpe', function () {
        $this->batch->update(['status' => BatchStatus::Applying, 'processed_rows' => 1]);

        wizardForBatch($this->admin, $this->batch)
            ->assertSet('step', 4)
            ->assertSee('Aplicando… 1 de 2 filas.');

        $this->batch->update(['status' => BatchStatus::Failed, 'failure_reason' => 'La aplicación se interrumpió en la fila 3: Falló.', 'created_rows' => 1]);

        wizardForBatch($this->admin, $this->batch)
            ->assertSet('step', 4)
            ->assertSee('La aplicación se interrumpió en la fila 3: Falló.')
            ->assertSeeInOrder(['Altas', '1', 'Cambios', '0']);
    });

    it('una aplicación atorada se reanuda con confirmación y termina sin repetir lo escrito', function () {
        stallAfterFirstRow($this->batch);
        $since = $this->batch->fresh()->updated_at->format('d/m/Y H:i');
        $this->travel(41)->minutes();

        $page = wizardForBatch($this->admin, $this->batch)
            ->assertSee('Atorado')
            ->assertSee("La aplicación se atoró: no avanza desde el {$since}, con 1 de 2 filas revisadas.")
            ->assertDontSee('Aplicando…')
            ->assertDontSeeHtml('wire:poll')
            ->assertSeeHtml('x-confirm="Se escriben en Campus (prueba) las filas que faltan; las ya escritas no se repiten. El lote sigue siendo de quien lo empezó a aplicar, y tú quedas en la bitácora como quien lo reanudó."')
            ->call('retryStalled')
            ->assertHasNoErrors();

        expect($this->batch->fresh())
            ->status->toBe(BatchStatus::Applied)
            ->processed_rows->toBe(2)
            ->and($this->batch->fresh()->applier->is($this->admin))->toBeTrue()
            ->and(Campus::where('code', 'C9')->count())->toBe(1);

        $page->assertSet('step', 4)->assertSeeInOrder(['Altas', '1', 'Cambios', '1', 'Sin cambios', '0']);
    });

    it('una aplicación atorada que desactivaba faltantes no se reanuda: se marca fallida, dice por qué y rehace el archivo de errores', function () {
        stallAfterFirstRow($this->batch, deactivateMissing: true);
        $this->travel(41)->minutes();

        wizardForBatch($this->admin, $this->batch)
            ->assertSee('no se reanuda: márcalo como fallido y vuelve a subir el archivo.')
            ->assertSeeHtml('data-confirm-title="¿Marcar como fallido?"')
            ->call('retryStalled')
            ->assertSee(RetryStalledBatch::DEACTIVATING_REASON)
            // La cola rehace el archivo de errores: la página no se queda esperándolo.
            ->assertSee(route('imports.batch-errors', $this->batch->id))
            ->assertDontSee('Preparando el archivo de errores…');

        // No siguió aplicando: la fila de C1 se habría escrito como cambio.
        expect($this->batch->fresh())->status->toBe(BatchStatus::Failed)->updated_rows->toBe(0);
    });
});

/**
 * La aplicación del lote escribió su primera fila (C9) y el proceso de la cola murió sin dejarlo fallido.
 */
function stallAfterFirstRow(ImportBatch $batch, bool $deactivateMissing = false): void
{
    $campus = Campus::factory()->create(['code' => 'C9', 'name' => 'Campus Nueve']);

    ImportRow::query()->where('batch_id', $batch->id)->where('row_number', 2)
        ->update(['status' => RowStatus::Applied, 'target_type' => $campus->getMorphClass(), 'target_id' => $campus->id]);
    $batch->update([
        'status' => BatchStatus::Applying,
        'run_id' => (string) Str::uuid7(),
        'processed_rows' => 1,
        'created_rows' => 1,
        'errors_exported_at' => null,
        'deactivate_missing' => $deactivateMissing,
        'applied_by' => $batch->uploaded_by,
    ]);
}

describe('otros roles', function () {
    it('no pueden aplicar aunque la página la haya abierto el admin', function () {
        Queue::fake();
        $coordinador = User::factory()->withRole(Role::Coordinador, ['campus_id' => Campus::factory()->create()->id, 'career_id' => careerKey(20)])->create();
        $page = wizardForBatch($this->admin, $this->batch);

        $this->actingAs($coordinador);

        $page->call('apply')->assertForbidden();
        Queue::assertNotPushed(ApplyQueuedBatch::class);
    });
});
