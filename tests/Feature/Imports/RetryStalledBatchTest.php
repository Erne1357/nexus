<?php

use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\User;
use App\Modules\Imports\Actions\BatchNotReady;
use App\Modules\Imports\Actions\RetryStalledBatch;
use App\Modules\Imports\Actions\StoreImportBatch;
use App\Modules\Imports\Actions\ValidateImportBatch;
use App\Modules\Imports\Enums\BatchStatus;
use App\Modules\Imports\Enums\RowStatus;
use App\Modules\Imports\Events\BatchApplied;
use App\Modules\Imports\Jobs\ApplyQueuedBatch;
use App\Modules\Imports\Jobs\ValidateQueuedBatch;
use App\Modules\Imports\Models\ImportBatch;
use App\Modules\Imports\Models\ImportRow;
use App\Modules\Imports\Queries\ImportBatchQuery;
use App\Modules\Imports\Templates\TemplateRegistry;
use Illuminate\Http\UploadedFile;
use Illuminate\Queue\MaxAttemptsExceededException;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Activity;
use Tests\Fixtures\ImportTemplates\CampusesTemplate;

/*
| E-03 (ensayo 8.11): un lote cuyo job murió sin que corriera su failed() (el proceso de la cola se quedó sin memoria,
| un despliegue lo detuvo) se quedaba «validando» o «aplicando» para siempre. Sin avance durante más que el tiempo
| máximo de su job más diez minutos, ya ningún job lo está trabajando: está atorado (supuesto por confirmar). Validar
| otra vez es seguro, porque la validación solo escribe staging. Aplicar se reanuda, porque cada bloque escribe y marca
| sus filas en una transacción y las ya aplicadas no se repiten; menos si el admin eligió desactivar faltantes, que
| pide confirmar una cifra que el lote no guarda: entonces queda fallido.
*/

beforeEach(function () {
    Storage::fake('imports');
    app()->instance(TemplateRegistry::class, new TemplateRegistry([CampusesTemplate::class]));
    $this->admin = User::factory()->admin()->create();
});

function storedCampusBatch(User $admin, string $csv = "code,name,modality\nC9,Campus Nueve,presencial\nC7,Campus Siete,presencial\n"): ImportBatch
{
    return app(StoreImportBatch::class)->handle($admin, 'test-campuses', UploadedFile::fake()->createWithContent('campus.csv', $csv));
}

/**
 * Un lote validado de dos filas cuya aplicación escribió la primera (C9) y se atoró: `$minutes` sin avanzar.
 */
function stalledApplication(User $admin, int $minutes = 41, bool $deactivateMissing = false): ImportBatch
{
    $batch = app(ValidateImportBatch::class)->handle(storedCampusBatch($admin));
    $campus = Campus::factory()->create(['code' => 'C9', 'name' => 'Campus Nueve']);

    ImportRow::query()->where('batch_id', $batch->id)->where('row_number', 2)
        ->update(['status' => RowStatus::Applied, 'target_type' => $campus->getMorphClass(), 'target_id' => $campus->id]);
    $batch->update([
        'status' => BatchStatus::Applying,
        'run_id' => (string) Str::uuid7(),
        'processed_rows' => 1,
        'created_rows' => 1,
        'deactivate_missing' => $deactivateMissing,
    ]);

    test()->travel($minutes)->minutes();

    return $batch;
}

it('un lote validando o aplicando está atorado cuando pasa el tiempo máximo de su job más diez minutos sin avanzar', function (BatchStatus $status) {
    $batch = ImportBatch::factory()->create(['status' => $status]);
    $queries = app(ImportBatchQuery::class);

    $this->travel(39)->minutes();
    expect($queries->stalled($batch->fresh()))->toBeFalse();

    $this->travel(2)->minutes();
    expect($queries->stalled($batch->fresh()))->toBeTrue();

    // Cada bloque mueve `updated_at`: el avance reinicia la cuenta.
    ImportBatch::query()->whereKey($batch->id)->increment('processed_rows', 500);
    expect($queries->stalled($batch->fresh()))->toBeFalse();
})->with([BatchStatus::Validating, BatchStatus::Applying]);

it('un lote que no está en la cola nunca está atorado', function (BatchStatus $status) {
    $batch = ImportBatch::factory()->create(['status' => $status]);

    $this->travel(1)->days();

    expect(app(ImportBatchQuery::class)->stalled($batch->fresh()))->toBeFalse();
})->with([BatchStatus::Uploaded, BatchStatus::Validated, BatchStatus::Applied, BatchStatus::Failed]);

it('una validación atorada se vuelve a validar desde el principio, con el mismo archivo y sin filas repetidas', function () {
    $batch = storedCampusBatch($this->admin);
    $deadJob = new ValidateQueuedBatch($batch);
    $batch->update(['status' => BatchStatus::Validating, 'run_id' => $deadJob->run, 'processed_rows' => 500]);
    ImportRow::factory()->for($batch, 'batch')->count(2)->create();
    $this->travel(41)->minutes();

    $status = app(RetryStalledBatch::class)->handle($batch, $this->admin);

    $entry = Activity::where('event', 'retried')->sole();

    expect($status)->toBe(BatchStatus::Uploaded)
        ->and($batch->fresh())
        ->status->toBe(BatchStatus::Uploaded)
        ->processed_rows->toBe(0)
        ->run_id->toBeNull()
        ->and(ImportRow::count())->toBe(0)
        ->and($entry->subject->is($batch))->toBeTrue()
        ->and($entry->causer->is($this->admin))->toBeTrue()
        ->and($entry->properties['processed_rows'])->toBe(500);

    // Si la cola llega a dar por fallido el job muerto, ya no es la corrida del lote: no lo toca.
    $deadJob->failed(new MaxAttemptsExceededException('El worker murió.'));
    expect($batch->fresh()->status)->toBe(BatchStatus::Uploaded);

    ValidateQueuedBatch::dispatch($batch);

    expect($batch->fresh())
        ->status->toBe(BatchStatus::Validated)
        ->total_rows->toBe(2)
        ->processed_rows->toBe(2)
        ->and(ImportRow::where('batch_id', $batch->id)->count())->toBe(2);
});

it('una aplicación atorada se reanuda: escribe solo las filas que faltan, sin repetir las escritas, y queda aplicada', function () {
    $batch = stalledApplication($this->admin);

    $status = app(RetryStalledBatch::class)->handle($batch, $this->admin);

    expect($status)->toBe(BatchStatus::Validated)
        ->and($batch->fresh())->status->toBe(BatchStatus::Validated)->run_id->toBeNull()
        ->and(Activity::where('event', 'retried')->sole()->causer->is($this->admin))->toBeTrue();

    ApplyQueuedBatch::dispatch($batch, $this->admin);

    expect($batch->fresh())
        ->status->toBe(BatchStatus::Applied)
        ->applied_at->not->toBeNull()
        ->created_rows->toBe(2)
        // El avance sigue desde lo que ya se había revisado.
        ->processed_rows->toBe(2)
        ->and(Campus::where('code', 'C9')->count())->toBe(1)
        ->and(Campus::where('code', 'C7')->exists())->toBeTrue()
        ->and(Activity::where('event', 'applied')->sole()->properties['created'])->toBe(2);
});

it('una aplicación atorada que desactivaba faltantes no se reanuda: queda fallida con el motivo y lo escrito se queda', function () {
    Event::fake([BatchApplied::class]);
    $batch = stalledApplication($this->admin, deactivateMissing: true);

    $status = app(RetryStalledBatch::class)->handle($batch, $this->admin);

    $entry = Activity::where('event', 'failed')->sole();

    expect($status)->toBe(BatchStatus::Failed)
        ->and($batch->fresh())
        ->status->toBe(BatchStatus::Failed)
        ->failure_reason->toBe('La aplicación se atoró sin avanzar: el proceso de la cola se detuvo antes de terminar. Lo que alcanzó a escribirse se queda. Como se eligió desactivar los que no vienen en el archivo, no se reanuda: vuelve a subir el archivo para revisar otra vez cuáles se desactivan.')
        ->and(Campus::where('code', 'C9')->exists())->toBeTrue()
        ->and($entry->causer->is($this->admin))->toBeTrue();

    Event::assertDispatched(BatchApplied::class, fn (BatchApplied $event): bool => $event->batch->is($batch) && $event->counts['created'] === 1);
});

it('no toca un lote que no está atorado', function (BatchStatus $status, int $minutes) {
    $batch = ImportBatch::factory()->create(['status' => $status, 'processed_rows' => 500]);
    ImportRow::factory()->for($batch, 'batch')->create();
    $this->travel($minutes)->minutes();

    expect(fn () => app(RetryStalledBatch::class)->handle($batch, $this->admin))->toThrow(BatchNotReady::class)
        ->and($batch->fresh())->status->toBe($status)->processed_rows->toBe(500)
        ->and(ImportRow::count())->toBe(1)
        ->and(Activity::whereIn('event', ['retried', 'failed'])->exists())->toBeFalse();
})->with([
    'validando con avance reciente' => [BatchStatus::Validating, 39],
    'aplicando con avance reciente' => [BatchStatus::Applying, 39],
    'subido' => [BatchStatus::Uploaded, 600],
    'validado' => [BatchStatus::Validated, 600],
    'aplicado' => [BatchStatus::Applied, 600],
    'fallido' => [BatchStatus::Failed, 600],
]);
