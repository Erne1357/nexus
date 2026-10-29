<?php

use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\User;
use App\Modules\Imports\Actions\ApplyImportBatch;
use App\Modules\Imports\Actions\BatchNotReady;
use App\Modules\Imports\Actions\MissingRecordsChanged;
use App\Modules\Imports\Actions\StoreImportBatch;
use App\Modules\Imports\Actions\ValidateImportBatch;
use App\Modules\Imports\Enums\BatchStatus;
use App\Modules\Imports\Events\BatchApplied;
use App\Modules\Imports\Models\ImportBatch;
use App\Modules\Imports\Models\ImportDeactivation;
use App\Modules\Imports\Templates\ImportContext;
use App\Modules\Imports\Templates\ImportTemplate;
use App\Modules\Imports\Templates\TemplateRegistry;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Spatie\Activitylog\Models\Activity;
use Tests\Fixtures\ImportTemplates\CampusesTemplate;

/*
| "Desactivar faltantes" al aplicar un lote (tarea 1.3, reglas-de-negocio.md, "Reglas de datos"): solo
| si el admin lo elige para ese lote, se marcan `is_active = false` los registros activos del catálogo
| cuya clave natural no viene en ninguna fila del archivo, ni siquiera en una con error (confirmado el
| 29 de octubre de 2026). Nunca toca inactivos ni borrados, en una plantilla de un campus solo mira ese
| campus, y nunca desactiva más de los que el admin confirmó. Cuáles desactivó queda en
| `import_deactivations`; la bitácora sigue con una entrada por lote.
*/

/**
 * Un catálogo de un campus para la prueba, como `rooms`: Core no tiene ninguno.
 */
class DeactivationRoom extends Model
{
    use HasUuids, SoftDeletes;

    protected $table = 'test_deactivation_rooms';

    protected $fillable = ['code', 'name', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }
}

class DeactivationRoomsTemplate extends ImportTemplate
{
    public function key(): string
    {
        return 'test-rooms';
    }

    public function label(): string
    {
        return 'Salones (prueba)';
    }

    public function fields(): array
    {
        return ['code' => 'clave', 'name' => 'nombre'];
    }

    public function naturalKey(): array
    {
        return ['code'];
    }

    public function rules(): array
    {
        return ['code' => ['required', 'string'], 'name' => ['required', 'string']];
    }

    public function model(): string
    {
        return DeactivationRoom::class;
    }

    public function requiresCampus(): bool
    {
        return true;
    }
}

/**
 * Plantilla de prueba cuyo afterApply() falla, para ver qué queda si la aplicación se corta después de
 * desactivar.
 */
class FailingAfterDeactivationTemplate extends CampusesTemplate
{
    public function afterApply(ImportContext $context): void
    {
        throw new RuntimeException('No se pudo despachar el recálculo.');
    }
}

beforeEach(function () {
    Storage::fake('imports');
    app()->instance(TemplateRegistry::class, new TemplateRegistry([CampusesTemplate::class, DeactivationRoomsTemplate::class]));
    $this->admin = User::factory()->admin()->create();
});

/**
 * Sube y valida un CSV de una plantilla de prueba.
 */
function validatedBatchFor(User $admin, string $template, string $csv, ?Campus $campus = null): ImportBatch
{
    $batch = app(StoreImportBatch::class)->handle($admin, $template, UploadedFile::fake()->createWithContent('catalogo.csv', $csv), $campus);

    return app(ValidateImportBatch::class)->handle($batch);
}

/**
 * Aplica con "desactivar faltantes", con la cifra que el admin vio y confirmó en el asistente.
 */
function applyDeactivatingMissing(ImportBatch $batch, User $admin, int $confirmed): ImportBatch
{
    return app(ApplyImportBatch::class)->handle($batch, $admin, confirmedMissing: $confirmed);
}

/**
 * La tabla del catálogo de salones de prueba; `code` puede ser de otro tipo para ver qué pasa cuando
 * la base guarda la clave distinto de como vino.
 */
function createDeactivationRoomsTable(string $codeType = 'string'): void
{
    Schema::create('test_deactivation_rooms', function (Blueprint $table) use ($codeType) {
        $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
        $table->foreignUuid('campus_id')->constrained('campuses');
        $table->{$codeType}('code');
        $table->string('name');
        $table->boolean('is_active')->default(true);
        $table->timestampsTz();
        $table->softDeletesTz();
    });
}

function deactivationRoom(Campus $campus, string $code): DeactivationRoom
{
    $room = (new DeactivationRoom)->forceFill(['campus_id' => $campus->id, 'code' => $code, 'name' => "Salón {$code}"]);
    $room->save();

    return $room;
}

it('sin la opción, aplicar no desactiva lo que no viene en el archivo', function () {
    $c1 = Campus::factory()->create(['code' => 'C1']);

    $batch = validatedBatchFor($this->admin, 'test-campuses', "code,name,modality\nC9,Campus Nueve,presencial\n");
    app(ApplyImportBatch::class)->handle($batch, $this->admin);

    expect($c1->fresh()->is_active)->toBeTrue()
        ->and($batch->fresh())
        ->deactivate_missing->toBeFalse()
        ->deactivated_rows->toBe(0)
        ->and(ImportDeactivation::count())->toBe(0);
});

it('con la opción, desactiva los activos que no vienen en el archivo y guarda cuáles fueron', function () {
    $c1 = Campus::factory()->create(['code' => 'C1']);
    $c2 = Campus::factory()->create(['code' => 'C2']);

    $batch = validatedBatchFor($this->admin, 'test-campuses', "code,name,modality\nC1,Campus Uno,presencial\nC9,Campus Nueve,presencial\n");
    applyDeactivatingMissing($batch, $this->admin, 1);

    expect($batch->fresh())
        ->status->toBe(BatchStatus::Applied)
        ->deactivate_missing->toBeTrue()
        ->deactivated_rows->toBe(1)
        ->and($c1->fresh()->is_active)->toBeTrue()
        ->and($c2->fresh()->is_active)->toBeFalse()
        ->and(Campus::where('code', 'C9')->sole()->is_active)->toBeTrue()
        ->and(ImportDeactivation::sole())
        ->batch_id->toBe($batch->id)
        ->and(ImportDeactivation::sole()->target->is($c2))->toBeTrue();
});

it('una fila con error protege su registro: su clave viene en el archivo', function () {
    $c2 = Campus::factory()->create(['code' => 'C2']);

    $batch = validatedBatchFor($this->admin, 'test-campuses', "code,name,modality\nC9,Campus Nueve,presencial\nc2,,presencial\n");
    applyDeactivatingMissing($batch, $this->admin, 0);

    expect($c2->fresh()->is_active)->toBeTrue()
        ->and($batch->fresh()->deactivated_rows)->toBe(0);
});

it('una fila sin clave no protege a nadie', function () {
    $c2 = Campus::factory()->create(['code' => 'C2']);

    $batch = validatedBatchFor($this->admin, 'test-campuses', "code,name,modality\nC9,Campus Nueve,presencial\n,Campus Dos,presencial\n");
    applyDeactivatingMissing($batch, $this->admin, 1);

    expect($c2->fresh()->is_active)->toBeFalse();
});

it('no toca los registros que ya estaban inactivos ni los borrados', function () {
    $inactive = Campus::factory()->inactive()->create(['code' => 'C3']);
    $deleted = Campus::factory()->create(['code' => 'C4']);
    $deleted->delete();

    $batch = validatedBatchFor($this->admin, 'test-campuses', "code,name,modality\nC9,Campus Nueve,presencial\n");
    applyDeactivatingMissing($batch, $this->admin, 0);

    expect($batch->fresh()->deactivated_rows)->toBe(0)
        ->and(ImportDeactivation::count())->toBe(0)
        ->and($inactive->fresh()->is_active)->toBeFalse()
        ->and(Campus::withTrashed()->find($deleted->id))
        ->is_active->toBeTrue()
        ->trashed()->toBeTrue();
});

it('en una plantilla de un campus solo desactiva los faltantes de ese campus', function () {
    createDeactivationRoomsTable();
    $c1 = Campus::factory()->create(['code' => 'C1']);
    $c2 = Campus::factory()->create(['code' => 'C2']);
    $kept = deactivationRoom($c2, 'A1');
    $missing = deactivationRoom($c2, 'A2');
    $otherCampus = deactivationRoom($c1, 'A2');

    $batch = validatedBatchFor($this->admin, 'test-rooms', "code,name\nA1,Salón A1\n", $c2);
    applyDeactivatingMissing($batch, $this->admin, 1);

    expect($kept->fresh()->is_active)->toBeTrue()
        ->and($missing->fresh()->is_active)->toBeFalse()
        ->and($otherCampus->fresh()->is_active)->toBeTrue()
        ->and(ImportDeactivation::sole()->target_id)->toBe($missing->id);
});

it('una fila aplicada protege el registro que escribió, aunque la base guarde su clave distinto', function () {
    createDeactivationRoomsTable('integer');
    $c2 = Campus::factory()->create(['code' => 'C2']);
    $room = deactivationRoom($c2, '7');

    // "007" encuentra y actualiza el salón 7: la base convierte la clave a número.
    $batch = validatedBatchFor($this->admin, 'test-rooms', "code,name\n007,Salón siete\n", $c2);
    applyDeactivatingMissing($batch, $this->admin, 1);

    expect($room->fresh())
        ->name->toBe('Salón siete')
        ->is_active->toBeTrue()
        ->and($batch->fresh()->deactivated_rows)->toBe(0);
});

it('nunca desactiva más de los que confirmó el admin: si al aplicar hay más, no desactiva ninguno y el lote falla', function () {
    $c2 = Campus::factory()->create(['code' => 'C2']);
    $batch = validatedBatchFor($this->admin, 'test-campuses', "code,name,modality\nC9,Campus Nueve,presencial\n");
    // Entre la confirmación y la cola, alguien da de alta un campus que no viene en el archivo.
    $c3 = Campus::factory()->create(['code' => 'C3']);

    expect(fn () => applyDeactivatingMissing($batch, $this->admin, 1))->toThrow(MissingRecordsChanged::class)
        ->and($batch->fresh())
        ->status->toBe(BatchStatus::Failed)
        ->failure_reason->toBe('La aplicación se interrumpió: se confirmó desactivar 1 registro que no viene en el archivo, pero al aplicar eran 2 porque el catálogo cambió. No se desactivó ninguno; las filas del archivo sí se escribieron. Vuelve a subir el archivo para revisarlo.')
        ->deactivated_rows->toBe(0)
        ->and(Campus::where('code', 'C9')->exists())->toBeTrue()
        ->and($c2->fresh()->is_active)->toBeTrue()
        ->and($c3->fresh()->is_active)->toBeTrue()
        ->and(ImportDeactivation::count())->toBe(0);
});

it('si al aplicar hay menos faltantes que los confirmados, desactiva los que hay', function () {
    $c2 = Campus::factory()->create(['code' => 'C2']);
    $c3 = Campus::factory()->create(['code' => 'C3']);
    $batch = validatedBatchFor($this->admin, 'test-campuses', "code,name,modality\nC9,Campus Nueve,presencial\n");
    $c3->delete();

    applyDeactivatingMissing($batch, $this->admin, 2);

    expect($batch->fresh()->deactivated_rows)->toBe(1)
        ->and($c2->fresh()->is_active)->toBeFalse();
});

it('la entrada de bitácora del lote dice cuántos desactivó; los registros no reciben entrada propia', function () {
    Campus::factory()->create(['code' => 'C1']);
    Campus::factory()->create(['code' => 'C2']);

    $batch = validatedBatchFor($this->admin, 'test-campuses', "code,name,modality\nC9,Campus Nueve,presencial\n");
    applyDeactivatingMissing($batch, $this->admin, 2);

    $entry = Activity::where('event', 'applied')->sole();

    expect($entry)
        ->description->toBe('Aplicó catalogo.csv en Campus (prueba): 1 altas, 0 cambios, 0 sin cambios, 0 filas con error sin aplicar y 2 desactivados por no venir en el archivo')
        ->and($entry->properties->only(['deactivate_missing', 'deactivated'])->all())->toBe(['deactivate_missing' => true, 'deactivated' => 2])
        ->and(Activity::where('subject_type', (new Campus)->getMorphClass())->count())->toBe(0);
});

it('avisa con BatchApplied cuántos desactivó', function () {
    Event::fake([BatchApplied::class]);
    Campus::factory()->create(['code' => 'C1']);

    $batch = validatedBatchFor($this->admin, 'test-campuses', "code,name,modality\nC9,Campus Nueve,presencial\n");
    applyDeactivatingMissing($batch, $this->admin, 1);

    Event::assertDispatched(BatchApplied::class, fn (BatchApplied $event): bool => $event->counts['deactivated'] === 1);
});

it('si la aplicación falla antes de terminar de escribir el archivo, no desactiva nada', function () {
    app()->bind(CampusesTemplate::class, fn () => new class extends CampusesTemplate
    {
        public function apply(array $row, ImportContext $context): Model
        {
            return $row['code'] === 'BOOM' ? throw new RuntimeException('El catálogo rechazó la fila.') : parent::apply($row, $context);
        }
    });
    $c2 = Campus::factory()->create(['code' => 'C2']);

    $batch = validatedBatchFor($this->admin, 'test-campuses', "code,name,modality\nC9,Campus Nueve,presencial\nBOOM,Explota,presencial\n");

    expect(fn () => applyDeactivatingMissing($batch, $this->admin, 1))->toThrow(RuntimeException::class, 'El catálogo rechazó la fila.')
        ->and($batch->fresh())
        ->status->toBe(BatchStatus::Failed)
        ->deactivated_rows->toBe(0)
        ->and($c2->fresh()->is_active)->toBeTrue()
        ->and(ImportDeactivation::count())->toBe(0);
});

it('si falla después de desactivar, lo desactivado se queda y la bitácora del fallo lo cuenta', function () {
    app()->bind(CampusesTemplate::class, FailingAfterDeactivationTemplate::class);
    $c2 = Campus::factory()->create(['code' => 'C2']);

    $batch = validatedBatchFor($this->admin, 'test-campuses', "code,name,modality\nC9,Campus Nueve,presencial\n");

    expect(fn () => applyDeactivatingMissing($batch, $this->admin, 1))->toThrow(RuntimeException::class, 'No se pudo despachar el recálculo.')
        ->and($batch->fresh())
        ->status->toBe(BatchStatus::Failed)
        ->deactivated_rows->toBe(1)
        ->and($c2->fresh()->is_active)->toBeFalse()
        ->and(Activity::where('event', 'failed')->sole()->properties['deactivated'])->toBe(1);
});

it('no desactiva con una plantilla cuyo catálogo no lo ofrece, y el lote sigue validado', function () {
    app()->bind(CampusesTemplate::class, fn () => new class extends CampusesTemplate
    {
        public function deactivatesMissing(): bool
        {
            return false;
        }
    });
    $c2 = Campus::factory()->create(['code' => 'C2']);
    $batch = validatedBatchFor($this->admin, 'test-campuses', "code,name,modality\nC9,Campus Nueve,presencial\n");

    expect(fn () => applyDeactivatingMissing($batch, $this->admin, 1))->toThrow(LogicException::class, 'no desactiva faltantes')
        ->and($batch->fresh()->status)->toBe(BatchStatus::Validated)
        ->and($c2->fresh()->is_active)->toBeTrue();
});

it('un lote que ya no está validado no se aplica aunque la plantilla no desactive faltantes', function () {
    app()->bind(CampusesTemplate::class, fn () => new class extends CampusesTemplate
    {
        public function deactivatesMissing(): bool
        {
            return false;
        }
    });
    $batch = ImportBatch::factory()->create(['status' => BatchStatus::Applied]);

    expect(fn () => applyDeactivatingMissing($batch, $this->admin, 1))->toThrow(BatchNotReady::class);
});
