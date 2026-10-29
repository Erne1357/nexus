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
 * Plantilla de prueba que cuenta cuántas veces corre afterApply(), falla al escribir la clave BOOM y
 * anota las cuentas del lote al llegar a la clave PROBE.
 */
class ApplyProbeTemplate extends CampusesTemplate
{
    public static int $afterApplyCalls = 0;

    /** @var array<string, int>|null */
    public static ?array $countsAtProbe = null;

    public function apply(array $row, ImportContext $context): Model
    {
        if ($row['code'] === 'BOOM') {
            throw new RuntimeException('El catálogo rechazó la fila.');
        }

        if ($row['code'] === 'PROBE') {
            self::$countsAtProbe = ImportBatch::query()->sole()->only(['processed_rows', 'created_rows', 'updated_rows', 'unchanged_rows']);
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
    ApplyProbeTemplate::$countsAtProbe = null;
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
    $batch = app(ValidateImportBatch::class)->handle($batch);
    $batch->update(['errors_exported_at' => now()]);

    return $batch;
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
        ->processed_rows->toBe(2)
        // El archivo de errores de la validación deja de valer: lo rehace la cola al terminar.
        ->errors_exported_at->toBeNull()
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
        && $event->counts === ['created' => 1, 'updated' => 0, 'unchanged' => 0, 'skipped' => 0, 'deactivated' => 0]);
});

it('vuelve a revisar cada fila al aplicar: lo que cambió desde la validación se salta como error', function () {
    $batch = validatedCampusBatch($this->admin, "code,name,modality\nC9,Campus Nueve,presencial\nC7,Campus Siete,presencial\n");
    Campus::factory()->create(['code' => 'C7'])->delete();

    applyCampusBatch($batch, $this->admin);

    $c7 = ImportRow::where('row_number', 3)->sole();

    expect($batch->fresh())
        ->status->toBe(BatchStatus::Applied)
        ->processed_rows->toBe(2)
        ->created_rows->toBe(1)
        ->and(Campus::where('code', 'C9')->exists())->toBeTrue()
        ->and($c7->status)->toBe(RowStatus::Error)
        ->and($c7->errors)->toBe(['code' => ['Hay un registro borrado con esta clave; una importación no lo restaura.']])
        ->and(Activity::where('event', 'applied')->sole()->properties['skipped'])->toBe(1);
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

it('lleva las cuentas de lo aplicado, bloque por bloque, para mostrar el avance', function () {
    Campus::factory()->create(['code' => 'K1', 'name' => 'Viejo']);
    $lines = collect(range(1, 700))->map(fn (int $n): string => $n === 650 ? 'PROBE,Campus,presencial' : "K{$n},Campus {$n},presencial")->implode("\n");
    $batch = validatedCampusBatch($this->admin, "code,name,modality\n{$lines}\n");

    $applied = applyCampusBatch($batch, $this->admin);

    // El lote que devuelve ya trae las cuentas, aunque cada bloque las sumó en la base.
    expect(ApplyProbeTemplate::$countsAtProbe)->toBe(['processed_rows' => 500, 'created_rows' => 499, 'updated_rows' => 1, 'unchanged_rows' => 0])
        ->and($applied->only(['processed_rows', 'created_rows', 'updated_rows', 'unchanged_rows']))
        ->toBe(['processed_rows' => 700, 'created_rows' => 699, 'updated_rows' => 1, 'unchanged_rows' => 0]);
});

it('si falla el cierre del lote, queda fallido con el motivo y no se queda aplicando', function () {
    Activity::creating(function (Activity $activity): void {
        if ($activity->event === 'applied') {
            throw new RuntimeException('No se pudo escribir la bitácora.');
        }
    });
    $batch = validatedCampusBatch($this->admin, "code,name,modality\nC9,Campus Nueve,presencial\n");

    expect(fn () => applyCampusBatch($batch, $this->admin))->toThrow(RuntimeException::class, 'No se pudo escribir la bitácora.')
        ->and($batch->fresh())
        ->status->toBe(BatchStatus::Failed)
        ->failure_reason->toBe('La aplicación se interrumpió: No se pudo escribir la bitácora.')
        ->created_rows->toBe(1);
});

it('solo aplica un lote validado', function (BatchStatus $status) {
    $batch = ImportBatch::factory()->create(['status' => $status]);

    expect(fn () => applyCampusBatch($batch, $this->admin))->toThrow(LogicException::class, 'no está validado')
        ->and($batch->fresh()->status)->toBe($status);
})->with([BatchStatus::Uploaded, BatchStatus::Applying, BatchStatus::Applied, BatchStatus::Failed]);

it('si falla un bloque posterior, los anteriores se quedan escritos y la bitácora dice cuánto se escribió', function () {
    Event::fake([BatchApplied::class]);
    $lines = collect(range(1, 700))->map(fn (int $n): string => $n === 600 ? 'BOOM,x,presencial' : "K{$n},Campus {$n},presencial")->implode("\n");
    $batch = validatedCampusBatch($this->admin, "code,name,modality\n{$lines}\n");

    expect(fn () => applyCampusBatch($batch, $this->admin))->toThrow(RuntimeException::class);

    $entry = Activity::where('event', 'failed')->sole();

    expect(Campus::count())->toBe(500)
        ->and(ImportRow::where('status', RowStatus::Applied)->count())->toBe(500)
        ->and($batch->fresh()->only(['processed_rows', 'created_rows']))->toBe(['processed_rows' => 500, 'created_rows' => 500])
        ->and($batch->fresh()->failure_reason)->toBe('La aplicación se interrumpió en la fila 601: El catálogo rechazó la fila.')
        ->and($entry->causer->is($this->admin))->toBeTrue()
        ->and($entry->properties->only(['created', 'updated', 'unchanged'])->all())->toBe(['created' => 500, 'updated' => 0, 'unchanged' => 0])
        // Lo escrito se queda, así que lo que corre al terminar (el recálculo de progreso) también corre.
        ->and(ApplyProbeTemplate::$afterApplyCalls)->toBe(1);

    Event::assertDispatched(BatchApplied::class, fn (BatchApplied $event): bool => $event->counts['created'] === 500);
});

it('si falla afterApply, el motivo no culpa a ninguna fila', function () {
    app()->bind(CampusesTemplate::class, fn () => new class extends ApplyProbeTemplate
    {
        public function afterApply(ImportContext $context): void
        {
            throw new RuntimeException('No se pudo despachar el recálculo.');
        }
    });
    $batch = validatedCampusBatch($this->admin, "code,name,modality\nC9,Campus Nueve,presencial\n");

    expect(fn () => applyCampusBatch($batch, $this->admin))->toThrow(RuntimeException::class)
        ->and($batch->fresh()->failure_reason)->toBe('La aplicación se interrumpió: No se pudo despachar el recálculo.');
});

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
