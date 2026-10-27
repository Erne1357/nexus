<?php

use App\Modules\Core\Enums\Modality;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\User;
use App\Modules\Imports\Actions\ApplyImportBatch;
use App\Modules\Imports\Actions\StoreImportBatch;
use App\Modules\Imports\Actions\ValidateImportBatch;
use App\Modules\Imports\Enums\BatchStatus;
use App\Modules\Imports\Enums\RowStatus;
use App\Modules\Imports\Events\BatchApplied;
use App\Modules\Imports\Models\ImportBatch;
use App\Modules\Imports\Models\ImportRow;
use App\Modules\Imports\Templates\ImportContext;
use App\Modules\Imports\Templates\TemplateRegistry;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Spatie\Activitylog\Models\Activity;
use Tests\Fixtures\ImportTemplates\CampusesTemplate;

/*
| ApplyImportBatch escribe en el catálogo las filas válidas y con advertencia de un lote validado, por
| clave natural con ImportTemplate::apply(), y salta las que traen error (reglas-de-negocio.md,
| "Reglas de datos", confirmado el 27 de octubre). Deja una sola entrada de bitácora para el lote
| con sus cuentas; el detalle por registro queda en `import_rows`.
*/

/**
 * Plantilla de prueba que cuenta cuántas veces corre afterApply() y falla al escribir la clave BOOM.
 */
class ApplyProbeTemplate extends CampusesTemplate
{
    public static int $afterApplyCalls = 0;

    public function apply(array $row, ImportContext $context): Model
    {
        if ($row['code'] === 'BOOM') {
            throw new RuntimeException('El catálogo rechazó la fila.');
        }

        return parent::apply($row, $context);
    }

    public function afterApply(ImportContext $context): void
    {
        self::$afterApplyCalls++;
    }
}

beforeEach(function () {
    Storage::fake('imports');
    ApplyProbeTemplate::$afterApplyCalls = 0;
    app()->bind(CampusesTemplate::class, ApplyProbeTemplate::class);
    app()->instance(TemplateRegistry::class, new TemplateRegistry([CampusesTemplate::class]));
    $this->admin = User::factory()->admin()->create();
});

/**
 * Sube y valida un CSV de la plantilla de prueba, como lo harán la página y el job de la tarea 1.2.
 */
function validatedCampusBatch(User $admin, string $csv): ImportBatch
{
    $batch = app(StoreImportBatch::class)->handle($admin, 'test-campuses', UploadedFile::fake()->createWithContent('campus.csv', $csv));

    return app(ValidateImportBatch::class)->handle($batch);
}

function applyCampusBatch(ImportBatch $batch, User $admin): ImportBatch
{
    return app(ApplyImportBatch::class)->handle($batch, $admin);
}

it('sube, valida y aplica un CSV: escribe las válidas y con advertencia, y salta las de error', function () {
    $c1 = Campus::factory()->inactive()->create(['code' => 'C1', 'name' => 'Viejo']);

    $batch = validatedCampusBatch($this->admin, "code,name,modality\nC9,Campus Nueve,presencial\nC1,Campus Uno,presencial\nC8,,presencial\n");
    applyCampusBatch($batch, $this->admin);

    $rows = $batch->rows()->orderBy('row_number')->get();

    expect($batch->fresh())
        ->status->toBe(BatchStatus::Applied)
        ->applied_at->not->toBeNull()
        ->and($batch->fresh()->applier->is($this->admin))->toBeTrue()
        ->and(Campus::where('code', 'C9')->sole())
        ->name->toBe('Campus Nueve')
        ->modality->toBe(Modality::Presencial)
        ->and($c1->fresh())
        ->name->toBe('Campus Uno')
        ->is_active->toBeFalse()
        ->and(Campus::where('code', 'C8')->exists())->toBeFalse()
        ->and($rows->pluck('status')->all())->toBe([RowStatus::Applied, RowStatus::Applied, RowStatus::Error])
        ->and($rows[0]->target->code)->toBe('C9')
        ->and($rows[1]->target->is($c1))->toBeTrue()
        ->and($rows[2]->target_id)->toBeNull();
});

it('deja una sola entrada de bitácora por lote aplicado, con altas, cambios y filas saltadas', function () {
    Campus::factory()->create(['code' => 'C1', 'name' => 'Viejo']);
    Campus::factory()->create(['code' => 'C2', 'name' => 'Campus Dos', 'modality' => Modality::Presencial]);

    $batch = validatedCampusBatch($this->admin, "code,name,modality\nC9,Campus Nueve,presencial\nC1,Campus Uno,presencial\nC2,Campus Dos,presencial\nC8,,presencial\n");
    applyCampusBatch($batch, $this->admin);

    $entry = Activity::where('event', 'applied')->sole();

    expect($entry)
        ->description->toBe('Aplicó campus.csv en Campus (prueba): 1 altas, 1 cambios, 1 sin cambios y 1 filas con error sin aplicar')
        ->and($entry->subject->is($batch))->toBeTrue()
        ->and($entry->causer->is($this->admin))->toBeTrue()
        ->and($entry->properties->only(['created', 'updated', 'unchanged', 'skipped'])->all())
        ->toBe(['created' => 1, 'updated' => 1, 'unchanged' => 1, 'skipped' => 1])
        ->and(Activity::where('subject_type', (new Campus)->getMorphClass())->count())->toBe(0);
});

it('avisa con BatchApplied después de aplicar, con sus cuentas', function () {
    Event::fake([BatchApplied::class]);

    $batch = validatedCampusBatch($this->admin, "code,name,modality\nC9,Campus Nueve,presencial\n");
    applyCampusBatch($batch, $this->admin);

    Event::assertDispatched(BatchApplied::class, fn (BatchApplied $event): bool => $event->batch->is($batch)
        && $event->counts === ['created' => 1, 'updated' => 0, 'unchanged' => 0, 'skipped' => 0]);
});

it('volver a importar el mismo archivo no duplica ni cambia nada', function () {
    $csv = "code,name,modality\nC9,Campus Nueve,presencial\nC8,Campus Ocho,mixta\n";
    applyCampusBatch(validatedCampusBatch($this->admin, $csv), $this->admin);

    $again = applyCampusBatch(validatedCampusBatch($this->admin, $csv), $this->admin);

    expect(Campus::count())->toBe(2)
        ->and(Activity::where('event', 'applied')->where('subject_id', $again->id)->sole()->properties->only(['created', 'updated', 'unchanged'])->all())
        ->toBe(['created' => 0, 'updated' => 0, 'unchanged' => 2]);
});

it('corre afterApply de la plantilla una sola vez, al terminar el lote', function () {
    $lines = collect(range(1, 700))->map(fn (int $n): string => "K{$n},Campus {$n},presencial")->implode("\n");

    applyCampusBatch(validatedCampusBatch($this->admin, "code,name,modality\n{$lines}\n"), $this->admin);

    expect(ApplyProbeTemplate::$afterApplyCalls)->toBe(1)
        ->and(Campus::count())->toBe(700);
});

it('solo aplica un lote validado', function (BatchStatus $status) {
    $batch = ImportBatch::factory()->create(['status' => $status]);

    expect(fn () => applyCampusBatch($batch, $this->admin))->toThrow(LogicException::class, 'no está validado')
        ->and($batch->fresh()->status)->toBe($status);
})->with([BatchStatus::Uploaded, BatchStatus::Applying, BatchStatus::Applied, BatchStatus::Failed]);

it('si una fila no se puede escribir, el lote queda fallido con la fila y su bloque no se escribe', function () {
    $batch = validatedCampusBatch($this->admin, "code,name,modality\nC9,Campus Nueve,presencial\nBOOM,Explota,presencial\n");

    expect(fn () => applyCampusBatch($batch, $this->admin))->toThrow(RuntimeException::class, 'El catálogo rechazó la fila.')
        ->and($batch->fresh())
        ->status->toBe(BatchStatus::Failed)
        ->failure_reason->toBe('La aplicación se interrumpió en la fila 3: El catálogo rechazó la fila.')
        ->and(Campus::count())->toBe(0)
        ->and(ImportRow::where('status', RowStatus::Applied)->count())->toBe(0)
        ->and(ApplyProbeTemplate::$afterApplyCalls)->toBe(0);
});
