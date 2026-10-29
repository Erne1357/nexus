<?php

use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\User;
use App\Modules\Imports\Actions\StoreImportBatch;
use App\Modules\Imports\Actions\ValidateImportBatch;
use App\Modules\Imports\Jobs\ApplyQueuedBatch;
use App\Modules\Imports\Models\ImportBatch;
use App\Modules\Imports\Templates\TemplateRegistry;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Fixtures\ImportTemplates\CampusesTemplate;
use Tests\Fixtures\ImportTemplates\TestRoom;
use Tests\Fixtures\ImportTemplates\TestRoomsTemplate;

/*
| "Desactivar faltantes" en el asistente de importación (tarea 1.3): en el paso 3 el admin ve cuántos de
| los registros activos del catálogo no vienen en el archivo y, si elige desactivarlos, cuáles son; el
| botón y la confirmación repiten la cifra (sin umbral, confirmado el 29 de octubre de 2026). En el paso
| 4 ve cuántos desactivó y cuáles. Solo el admin aplica (fila "Importar catálogos").
*/

beforeEach(function () {
    Storage::fake('imports');
    app()->instance(TemplateRegistry::class, new TemplateRegistry([CampusesTemplate::class]));
    $this->admin = User::factory()->admin()->create();
    Campus::factory()->create(['code' => 'C1', 'name' => 'Campus Uno']);
    $this->c2 = Campus::factory()->create(['code' => 'C2', 'name' => 'Campus Dos']);
    Campus::factory()->create(['code' => 'C3', 'name' => 'Campus Tres']);

    $this->batch = validatedWizardBatch($this->admin, "code,name,modality\nC1,Campus Uno,presencial\nC9,Campus Nueve,presencial\n");
});

function validatedWizardBatch(User $admin, string $csv): ImportBatch
{
    return app(ValidateImportBatch::class)->handle(app(StoreImportBatch::class)->handle(
        $admin,
        'test-campuses',
        UploadedFile::fake()->createWithContent('campus.csv', $csv),
    ));
}

function deactivationWizard(User $user, ImportBatch $batch): Testable
{
    return Livewire::actingAs($user)->withQueryParams(['lote' => $batch->id])->test('imports::pages.import-wizard');
}

describe('admin', function () {
    it('en el paso 3 dice cuántos de los activos no vienen en el archivo, sin listarlos hasta que elige desactivarlos', function () {
        deactivationWizard($this->admin, $this->batch)
            ->assertSet('step', 3)
            ->assertSee('2 de 3 registros activos de Campus (prueba) no vienen en el archivo.')
            ->assertSee('Desactivar los que no vienen en el archivo')
            ->assertDontSee('Campus Dos')
            ->assertSee('Aplicar 2 filas');
    });

    it('al elegir desactivarlos los lista, y el botón y la confirmación dicen cuántos', function () {
        deactivationWizard($this->admin, $this->batch)
            ->set('deactivateMissing', true)
            ->assertSeeInOrder(['C2', 'Campus Dos', 'C3', 'Campus Tres'])
            ->assertSee('Aplicar 2 filas y desactivar 2')
            ->assertSeeHtml('wire:click="apply(2)"')
            ->assertSeeHtml('Se desactivarán 2 registros que no vienen en el archivo; no se borran y se reactivan a mano en el catálogo.');
    });

    it('manda a aplicar con la cifra que confirmó el admin solo si eligió desactivar', function (bool $chosen, ?int $confirmed) {
        Queue::fake();

        deactivationWizard($this->admin, $this->batch)->set('deactivateMissing', $chosen)->call('apply', 2)->assertHasNoErrors();

        Queue::assertPushed(ApplyQueuedBatch::class, fn (ApplyQueuedBatch $job): bool => $job->batch->is($this->batch) && $job->confirmedMissing === $confirmed);
    })->with(['elegido' => [true, 2], 'sin elegir' => [false, null]]);

    it('al terminar muestra cuántos desactivó y cuáles', function () {
        $page = deactivationWizard($this->admin, $this->batch)->set('deactivateMissing', true)->call('apply', 2);

        expect($this->c2->fresh()->is_active)->toBeFalse();

        $page->assertSet('step', 4)
            ->assertSeeInOrder(['Altas', '1', '2 registros desactivados por no venir en el archivo', 'C2', 'Campus Dos', 'C3', 'Campus Tres']);
    });

    it('sin la opción, el paso 4 no habla de desactivados', function () {
        deactivationWizard($this->admin, $this->batch)
            ->call('apply')
            ->assertSet('step', 4)
            ->assertDontSee('desactivad');
    });

    it('si eligió desactivar pero no se desactivó ninguno, lo dice', function () {
        $this->batch->update(['status' => 'applied', 'deactivate_missing' => true, 'deactivated_rows' => 0]);

        deactivationWizard($this->admin, $this->batch)
            ->assertSet('step', 4)
            ->assertSee('No se desactivó ningún registro.');
    });

    it('si todos los activos vienen en el archivo no ofrece desactivar', function () {
        $batch = validatedWizardBatch($this->admin, "code,name,modality\nC1,Campus Uno,presencial\nC2,Campus Dos,presencial\nC3,Campus Tres,presencial\n");

        deactivationWizard($this->admin, $batch)
            ->assertSee('Todos los registros activos de Campus (prueba) vienen en el archivo.')
            ->assertDontSee('Desactivar los que no vienen en el archivo');
    });

    it('con una plantilla que no lo ofrece no aparece, y pedirlo igual no desactiva', function () {
        Queue::fake();
        app()->bind(CampusesTemplate::class, fn () => new class extends CampusesTemplate
        {
            public function deactivatesMissing(): bool
            {
                return false;
            }
        });

        deactivationWizard($this->admin, $this->batch)
            ->assertDontSee('no vienen en el archivo')
            ->set('deactivateMissing', true)
            ->call('apply', 2);

        Queue::assertPushed(ApplyQueuedBatch::class, fn (ApplyQueuedBatch $job): bool => $job->confirmedMissing === null);
    });

    it('no manda a aplicar si la página no trae la cifra que vio el admin, y lo dice', function () {
        Queue::fake();

        // Encendió la opción y apretó «Aplicar» antes de que la página mostrara cuántos se desactivan.
        $page = deactivationWizard($this->admin, $this->batch)->set('deactivateMissing', true)->call('apply');

        expect(lastToastOf($page))->type->toBe('error')->title->toBe('Revisa los que se desactivan')
            ->description->toBe('Ahora son 2 registros que no vienen en el archivo: revisa la lista y vuelve a aplicar.');
        Queue::assertNothingPushed();
    });

    it('si al aplicar hay más faltantes de los que vio el admin, no lo manda y muestra la cifra nueva', function () {
        Queue::fake();
        $page = deactivationWizard($this->admin, $this->batch)->set('deactivateMissing', true);
        Campus::factory()->create(['code' => 'C4', 'name' => 'Campus Cuatro']);

        $page->call('apply', 2);

        expect(lastToastOf($page))->description->toBe('Ahora son 3 registros que no vienen en el archivo: revisa la lista y vuelve a aplicar.');
        Queue::assertNothingPushed();
        $page->assertSee('Aplicar 2 filas y desactivar 3');
    });

    it('en una plantilla de un campus solo cuenta y lista los del campus del lote', function () {
        TestRoom::createTable();
        app()->instance(TemplateRegistry::class, new TemplateRegistry([CampusesTemplate::class, TestRoomsTemplate::class]));
        TestRoom::in($this->c2, 'A1');
        TestRoom::in($this->c2, 'A2');
        TestRoom::in(Campus::where('code', 'C1')->sole(), 'B7');
        $batch = app(ValidateImportBatch::class)->handle(app(StoreImportBatch::class)->handle(
            $this->admin,
            'test-rooms',
            UploadedFile::fake()->createWithContent('salones.csv', "code,name\nA1,Salón A1\n"),
            $this->c2,
        ));

        deactivationWizard($this->admin, $batch)
            ->assertSee('1 de 2 registros activos de Salones (prueba) en C2 no viene en el archivo.')
            ->set('deactivateMissing', true)
            ->assertSee('A2')
            ->assertDontSee('B7');
    });

    it('un lote de un campus que después se retiró se sigue revisando', function () {
        TestRoom::createTable();
        app()->instance(TemplateRegistry::class, new TemplateRegistry([CampusesTemplate::class, TestRoomsTemplate::class]));
        TestRoom::in($this->c2, 'A2');
        $batch = app(ValidateImportBatch::class)->handle(app(StoreImportBatch::class)->handle(
            $this->admin,
            'test-rooms',
            UploadedFile::fake()->createWithContent('salones.csv', "code,name\nA1,Salón A1\n"),
            $this->c2,
        ));
        $this->c2->delete();

        deactivationWizard($this->admin, $batch)
            ->assertOk()
            ->assertSee('1 de 1 registros activos de Salones (prueba) en C2 no viene en el archivo.');
    });

    it('retomar otro lote o empezar de nuevo vuelve a la primera página de las listas', function (string $method) {
        $other = validatedWizardBatch($this->admin, "code,name,modality\nC1,Campus Uno,presencial\n");
        $page = deactivationWizard($this->admin, $this->batch)
            ->call('setPage', 3, 'faltantes')
            ->call('setPage', 2, 'desactivados');

        $method === 'open' ? $page->call('open', $other->id) : $page->call('startOver');

        $page->assertSet('paginators.faltantes', 1)->assertSet('paginators.desactivados', 1);
    })->with(['startOver', 'open']);

    it('empezar de nuevo o retomar otro lote apaga la opción', function (string $method) {
        $other = validatedWizardBatch($this->admin, "code,name,modality\nC1,Campus Uno,presencial\n");
        $page = deactivationWizard($this->admin, $this->batch)->set('deactivateMissing', true);

        $method === 'open' ? $page->call('open', $other->id) : $page->call('startOver');

        $page->assertSet('deactivateMissing', false);
    })->with(['startOver', 'open']);
});

describe('otros roles', function () {
    it('un coordinador no puede aplicar desactivando faltantes aunque la página la haya abierto el admin', function () {
        Queue::fake();
        $coordinador = User::factory()->withRole(Role::Coordinador, ['campus_id' => $this->c2->id, 'career_id' => fakeKey(20)])->create();
        $page = deactivationWizard($this->admin, $this->batch)->set('deactivateMissing', true);

        $this->actingAs($coordinador);

        $page->call('apply', 2)->assertForbidden();
        Queue::assertNotPushed(ApplyQueuedBatch::class);
        expect($this->c2->fresh()->is_active)->toBeTrue();
    });
});
