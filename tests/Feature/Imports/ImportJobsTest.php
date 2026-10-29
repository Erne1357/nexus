<?php

use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\User;
use App\Modules\Imports\Actions\StoreImportBatch;
use App\Modules\Imports\Actions\ValidateImportBatch;
use App\Modules\Imports\Enums\BatchStatus;
use App\Modules\Imports\Enums\RowStatus;
use App\Modules\Imports\Events\BatchApplied;
use App\Modules\Imports\Events\BatchValidated;
use App\Modules\Imports\Jobs\ApplyQueuedBatch;
use App\Modules\Imports\Jobs\ValidateQueuedBatch;
use App\Modules\Imports\Models\ImportBatch;
use App\Modules\Imports\Models\ImportRow;
use App\Modules\Imports\Templates\ImportContext;
use App\Modules\Imports\Templates\TemplateRegistry;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Queue\MaxAttemptsExceededException;
use Illuminate\Queue\TimeoutExceededException;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Activity;
use Tests\Fixtures\ImportTemplates\CampusesTemplate;

/*
| Validar y aplicar corren en la cola (tarea 1.2), porque un kardex puede tener cientos de miles de
| filas. Cada job hace un solo intento: si el proceso muere o pasa del tiempo máximo, el lote queda
| fallido con el motivo, en lugar de quedarse "validando" o "aplicando" para siempre.
*/

beforeEach(function () {
    Storage::fake('imports');
    app()->instance(TemplateRegistry::class, new TemplateRegistry([CampusesTemplate::class]));
    $this->admin = User::factory()->admin()->create();
});

function uploadedCampusBatch(User $admin, string $csv): ImportBatch
{
    return app(StoreImportBatch::class)->handle($admin, 'test-campuses', UploadedFile::fake()->createWithContent('campus.csv', $csv));
}

it('valida en la cola el lote subido y deja listo el archivo de errores', function () {
    $batch = uploadedCampusBatch($this->admin, "code,name,modality\nC9,Campus Nueve,presencial\nC8,,presencial\n");

    ValidateQueuedBatch::dispatch($batch);

    expect($batch->fresh())
        ->status->toBe(BatchStatus::Validated)
        ->total_rows->toBe(2)
        ->and(Storage::disk('imports')->get($batch->errors_path))->toContain("\n3,C8,,");
});

it('aplica en la cola el lote validado, a nombre de quien lo aplica, y rehace el archivo de errores', function () {
    $batch = app(ValidateImportBatch::class)->handle(uploadedCampusBatch($this->admin, "code,name,modality\nC9,Campus Nueve,presencial\nC7,Campus Siete,presencial\n"));
    Campus::factory()->create(['code' => 'C7'])->delete();
    $applier = User::factory()->admin()->create();

    ApplyQueuedBatch::dispatch($batch, $applier);

    // C7 pasó a error al aplicar: el catálogo cambió desde la validación.
    expect($batch->fresh())
        ->status->toBe(BatchStatus::Applied)
        ->and($batch->fresh()->applier->is($applier))->toBeTrue()
        ->and(Campus::where('code', 'C9')->exists())->toBeTrue()
        ->and(Storage::disk('imports')->get($batch->errors_path))->toContain("\n3,C7,");
});

it('aplica en la cola con «desactivar faltantes» cuando el admin lo eligió', function () {
    $c2 = Campus::factory()->create(['code' => 'C2']);
    $batch = app(ValidateImportBatch::class)->handle(uploadedCampusBatch($this->admin, "code,name,modality\nC9,Campus Nueve,presencial\n"));

    ApplyQueuedBatch::dispatch($batch, $this->admin, confirmedMissing: 1);

    expect($batch->fresh())
        ->status->toBe(BatchStatus::Applied)
        ->deactivate_missing->toBeTrue()
        ->and($c2->fresh()->is_active)->toBeFalse();
});

it('un job de un lote que otra corrida ya tomó termina sin tocarlo', function (string $job, BatchStatus $status) {
    $batch = ImportBatch::factory()->create(['status' => $status]);

    $job === ValidateQueuedBatch::class ? ValidateQueuedBatch::dispatch($batch) : ApplyQueuedBatch::dispatch($batch, $this->admin);

    expect($batch->fresh())
        ->status->toBe($status)
        ->failure_reason->toBeNull()
        ->and(Activity::where('event', 'failed')->exists())->toBeFalse();
})->with([
    'validar un lote que ya se valida' => [ValidateQueuedBatch::class, BatchStatus::Validating],
    'validar un lote ya validado' => [ValidateQueuedBatch::class, BatchStatus::Validated],
    'aplicar un lote que ya se aplica' => [ApplyQueuedBatch::class, BatchStatus::Applying],
    'aplicar un lote ya aplicado' => [ApplyQueuedBatch::class, BatchStatus::Applied],
]);

it('deja en el lote la corrida que lo tomó', function () {
    $batch = uploadedCampusBatch($this->admin, "code,name,modality\nC9,Campus Nueve,presencial\n");
    $validation = new ValidateQueuedBatch($batch);

    dispatch($validation);
    $validatedRun = $batch->fresh()->run_id;

    $application = new ApplyQueuedBatch($batch->fresh(), $this->admin);
    dispatch($application);

    expect($validatedRun)->toBe($validation->run)
        ->and($batch->fresh()->run_id)->toBe($application->run)
        ->and($validation->run)->not->toBe($application->run);
});

it('si el proceso muere a media validación, el lote queda fallido, sin filas ni avance y con bitácora', function () {
    Event::fake([BatchValidated::class]);
    $batch = ImportBatch::factory()->create(['uploaded_by' => $this->admin->id]);
    $job = new ValidateQueuedBatch($batch);
    $batch->update(['status' => BatchStatus::Validating, 'run_id' => $job->run, 'processed_rows' => 500]);
    ImportRow::factory()->for($batch, 'batch')->count(2)->create();

    $job->failed(new MaxAttemptsExceededException('El worker murió.'));

    $entry = Activity::where('event', 'failed')->sole();

    expect($batch->fresh())
        ->status->toBe(BatchStatus::Failed)
        ->failure_reason->toBe('La validación se interrumpió antes de terminar: el proceso de la cola se detuvo o pasó del tiempo máximo. Vuelve a subir el archivo; si se repite, avisa al equipo de Nexus.')
        ->processed_rows->toBe(0)
        ->and(ImportRow::count())->toBe(0)
        ->and($entry->subject->is($batch))->toBeTrue()
        ->and($entry->causer->is($this->admin))->toBeTrue();

    Event::assertDispatched(BatchValidated::class, fn (BatchValidated $event): bool => $event->batch->is($batch));
});

it('si el proceso muere a media aplicación, el lote queda fallido con lo que alcanzó a escribir', function () {
    Event::fake([BatchApplied::class]);
    $batch = ImportBatch::factory()->create();
    $job = new ApplyQueuedBatch($batch, $this->admin);
    $batch->update(['status' => BatchStatus::Applying, 'run_id' => $job->run, 'created_rows' => 500, 'updated_rows' => 3, 'unchanged_rows' => 2]);
    ImportRow::factory()->for($batch, 'batch')->create(['status' => RowStatus::Applied]);
    ImportRow::factory()->for($batch, 'batch')->create(['status' => RowStatus::Error]);

    $job->failed(new TimeoutExceededException('Pasó del tiempo máximo.'));

    $entry = Activity::where('event', 'failed')->sole();
    $counts = ['created' => 500, 'updated' => 3, 'unchanged' => 2, 'skipped' => 1, 'deactivated' => 0];

    expect($batch->fresh())
        ->status->toBe(BatchStatus::Failed)
        ->failure_reason->toBe('La aplicación se interrumpió antes de terminar: el proceso de la cola se detuvo o pasó del tiempo máximo. Lo que alcanzó a escribirse se queda; vuelve a subir el archivo para terminar.')
        ->and(ImportRow::where('status', RowStatus::Applied)->count())->toBe(1)
        ->and($entry->causer->is($this->admin))->toBeTrue()
        ->and($entry->properties->only(array_keys($counts))->all())->toBe($counts);

    Event::assertDispatched(BatchApplied::class, fn (BatchApplied $event): bool => $event->batch->is($batch) && $event->counts === $counts);
});

it('el fallo de un job duplicado no toca el lote que otra corrida sigue procesando', function (BatchStatus $status, string $job) {
    $batch = ImportBatch::factory()->create(['status' => $status, 'run_id' => (string) Str::uuid7()]);

    $job === ValidateQueuedBatch::class
        ? (new ValidateQueuedBatch($batch))->failed(new MaxAttemptsExceededException('El worker murió.'))
        : (new ApplyQueuedBatch($batch, $this->admin))->failed(new MaxAttemptsExceededException('El worker murió.'));

    expect($batch->fresh()->status)->toBe($status)
        ->and(Activity::where('event', 'failed')->exists())->toBeFalse();
})->with([
    'validación' => [BatchStatus::Validating, ValidateQueuedBatch::class],
    'aplicación' => [BatchStatus::Applying, ApplyQueuedBatch::class],
]);

it('la interrupción no toca un lote que su corrida ya terminó', function (BatchStatus $status) {
    $batch = ImportBatch::factory()->create();
    $job = new ApplyQueuedBatch($batch, $this->admin);
    $batch->update(['status' => $status, 'run_id' => $job->run]);

    $job->failed(new MaxAttemptsExceededException('El worker murió.'));

    expect($batch->fresh()->status)->toBe($status)
        ->and(Activity::where('event', 'failed')->exists())->toBeFalse();
})->with([BatchStatus::Validated, BatchStatus::Applied, BatchStatus::Failed]);

it('si la aplicación falla, igual rehace el archivo de errores', function () {
    app()->bind(CampusesTemplate::class, fn () => new class extends CampusesTemplate
    {
        public function apply(array $row, ImportContext $context): Model
        {
            throw new RuntimeException('El catálogo rechazó la fila.');
        }
    });
    $batch = app(ValidateImportBatch::class)->handle(uploadedCampusBatch($this->admin, "code,name,modality\nC9,Campus Nueve,presencial\n"));

    expect(fn () => ApplyQueuedBatch::dispatch($batch, $this->admin))->toThrow(RuntimeException::class)
        ->and(Storage::disk('imports')->exists($batch->errors_path))->toBeTrue();
});

it('si la aplicación falla con su propio motivo, la interrupción no lo pisa', function () {
    app()->bind(CampusesTemplate::class, fn () => new class extends CampusesTemplate
    {
        public function apply(array $row, ImportContext $context): Model
        {
            throw new RuntimeException('El catálogo rechazó la fila.');
        }
    });
    $batch = app(ValidateImportBatch::class)->handle(uploadedCampusBatch($this->admin, "code,name,modality\nC9,Campus Nueve,presencial\n"));

    expect(fn () => ApplyQueuedBatch::dispatch($batch, $this->admin))->toThrow(RuntimeException::class)
        ->and($batch->fresh()->failure_reason)->toBe('La aplicación se interrumpió en la fila 2: El catálogo rechazó la fila.')
        ->and(Activity::where('event', 'failed')->count())->toBe(1);
});

it('cada job hace un solo intento y su tiempo máximo cabe en el retry_after de la cola', function (string $job) {
    $instance = $job === ValidateQueuedBatch::class
        ? new ValidateQueuedBatch(ImportBatch::factory()->make())
        : new ApplyQueuedBatch(ImportBatch::factory()->make(), $this->admin);

    // Si un job dura más que retry_after, la cola lo entrega otra vez y ese segundo intento lo marca
    // fallido mientras el primero sigue corriendo.
    expect($instance->tries)->toBe(1)
        ->and($instance->failOnTimeout)->toBeTrue()
        ->and($instance->timeout)->toBeLessThan(config('queue.connections.redis.retry_after'));
})->with([ValidateQueuedBatch::class, ApplyQueuedBatch::class]);
