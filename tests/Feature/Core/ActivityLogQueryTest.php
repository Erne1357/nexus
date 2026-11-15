<?php

use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\User;
use App\Modules\Core\Queries\ActivityLogQuery;
use App\Modules\Core\Support\ActivityRecordTypes;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Models\Activity;

/*
| ActivityLogQuery (CF-S5): la bitácora de la más nueva a la más vieja, con filtros por quién, tipo de registro, evento,
| texto y fechas, paginación simple y el detalle de una entrada. La tabla es inmutable (HC-18), así que las entradas de
| fechas conocidas se insertan directo.
*/

/**
 * Inserta una entrada de bitácora con fecha propia y devuelve su id.
 *
 * @param  array<string, mixed>  $values
 */
function logEntry(array $values = []): int
{
    $values += ['description' => 'Algo pasó', 'created_at' => '2026-11-14 10:00:00', 'updated_at' => '2026-11-14 10:00:00'];

    foreach (['properties', 'attribute_changes'] as $json) {
        if (array_key_exists($json, $values)) {
            $values[$json] = json_encode($values[$json]);
        }
    }

    return DB::table('activity_log')->insertGetId($values);
}

beforeEach(function () {
    $this->ana = User::factory()->create(['username' => 'ana01', 'name' => 'Ana Pérez']);
    $this->beto = User::factory()->create(['username' => 'beto02', 'name' => 'Beto Ruiz']);
    $this->campus = Campus::factory()->create(['code' => 'C9', 'name' => 'Campus Nueve']);

    $this->first = logEntry(['description' => 'Creó el campus C9', 'event' => 'created', 'subject_type' => Campus::class, 'subject_id' => $this->campus->id, 'causer_type' => User::class, 'causer_id' => $this->ana->id, 'created_at' => '2026-11-10 09:00:00']);
    $this->second = logEntry(['description' => 'Invitó a beto02', 'event' => 'access-invited', 'subject_type' => User::class, 'subject_id' => $this->beto->id, 'causer_type' => User::class, 'causer_id' => $this->ana->id, 'created_at' => '2026-11-12 09:00:00']);
    $this->third = logEntry(['description' => 'Editó el campus C9', 'event' => 'updated', 'subject_type' => Campus::class, 'subject_id' => $this->campus->id, 'causer_type' => User::class, 'causer_id' => $this->beto->id, 'created_at' => '2026-11-14 09:00:00']);
});

it('CF-S5: lista de la más nueva a la más vieja', function () {
    expect(app(ActivityLogQuery::class)->forTable()->pluck('id')->all())->toBe([$this->third, $this->second, $this->first]);
});

it('CF-S5: filtra por quién, buscando por nombre o por usuario', function () {
    $query = app(ActivityLogQuery::class);

    expect($query->forTable(who: 'beto')->pluck('id')->all())->toBe([$this->third])
        ->and($query->forTable(who: 'Pérez')->pluck('id')->all())->toBe([$this->second, $this->first]);
});

it('CF-S5: filtra por tipo de registro', function () {
    expect(app(ActivityLogQuery::class)->forTable(subjectType: User::class)->pluck('id')->all())->toBe([$this->second]);
});

it('CF-S5: filtra por evento', function () {
    expect(app(ActivityLogQuery::class)->forTable(event: 'updated')->pluck('id')->all())->toBe([$this->third]);
});

it('CF-S5: filtra por texto de la descripción, sin distinguir mayúsculas', function () {
    expect(app(ActivityLogQuery::class)->forTable(search: 'CAMPUS c9')->pluck('id')->all())->toBe([$this->third, $this->first]);
});

it('CF-S5: filtra por rango de fechas, con el último día completo', function () {
    $query = app(ActivityLogQuery::class);

    expect($query->forTable(from: CarbonImmutable::parse('2026-11-11'))->pluck('id')->all())->toBe([$this->third, $this->second])
        ->and($query->forTable(to: CarbonImmutable::parse('2026-11-12'))->pluck('id')->all())->toBe([$this->second, $this->first])
        ->and($query->forTable(from: CarbonImmutable::parse('2026-11-12'), to: CarbonImmutable::parse('2026-11-12'))->pluck('id')->all())->toBe([$this->second]);
});

it('CF-S5: pagina sin contar el total', function () {
    $page = app(ActivityLogQuery::class)->forTable(perPage: 2);

    expect($page->count())->toBe(2)
        ->and($page->hasMorePages())->toBeTrue()
        ->and($page->pluck('id')->all())->toBe([$this->third, $this->second])
        ->and(method_exists($page, 'total'))->toBeFalse();
});

it('da los tipos de registro con su nombre en español y los eventos que hay', function () {
    $query = app(ActivityLogQuery::class);

    expect($query->subjectTypes())->toBe([['id' => Campus::class, 'name' => 'Campus'], ['id' => User::class, 'name' => 'Usuario']])
        ->and($query->events())->toBe([['id' => 'access-invited', 'name' => 'access-invited'], ['id' => 'created', 'name' => 'created'], ['id' => 'updated', 'name' => 'updated']]);
});

it('nombra el tipo de registro: el mapa de Core y, si no, el nombre corto de la clase', function () {
    expect(ActivityRecordTypes::label(Campus::class))->toBe('Campus')
        ->and(ActivityRecordTypes::label(User::class))->toBe('Usuario')
        ->and(ActivityRecordTypes::label('App\\Modules\\Academic\\Models\\Career'))->toBe('Carrera')
        ->and(ActivityRecordTypes::label('App\\Modules\\Otro\\Models\\Cosa'))->toBe('Cosa')
        ->and(ActivityRecordTypes::label(null))->toBe('Sin registro');
});

it('CF-S5: el detalle trae el antes y el después por campo, el puesto con que actuó y el registro afectado', function () {
    $id = logEntry([
        'description' => 'Editó el campus C9', 'event' => 'updated', 'subject_type' => Campus::class, 'subject_id' => $this->campus->id,
        'causer_type' => User::class, 'causer_id' => $this->ana->id,
        'attribute_changes' => ['attributes' => ['name' => 'Campus Diez', 'is_active' => false], 'old' => ['name' => 'Campus Nueve', 'is_active' => true]],
        'properties' => ['acting_as' => 'admin', 'email' => 'x@itcj.test'],
    ]);

    $detail = app(ActivityLogQuery::class)->detail($id);

    expect($detail->changes)->toBe([
        ['field' => 'name', 'old' => 'Campus Nueve', 'new' => 'Campus Diez'],
        ['field' => 'is_active', 'old' => 'sí', 'new' => 'no'],
    ])
        ->and($detail->actingAs)->toBe('Administración')
        ->and($detail->properties)->toBe(['email' => 'x@itcj.test'])
        ->and($detail->typeLabel)->toBe('Campus')
        ->and($detail->subjectName)->toBe('Campus Nueve')
        ->and($detail->activity->causer->is($this->ana))->toBeTrue();
});

it('el detalle de una entrada sin cambios ni registro sigue funcionando', function () {
    $detail = app(ActivityLogQuery::class)->detail(logEntry());

    expect($detail->changes)->toBe([])
        ->and($detail->actingAs)->toBeNull()
        ->and($detail->subjectName)->toBeNull()
        ->and($detail->typeLabel)->toBe('Sin registro');
});

it('HC-18: las lecturas no escriben en la bitácora', function () {
    $before = Activity::query()->count();
    $query = app(ActivityLogQuery::class);

    $query->forTable(who: 'ana', event: 'created');
    $query->subjectTypes();
    $query->events();
    $query->detail($this->first);

    expect(Activity::query()->count())->toBe($before);
});

it('CF-S5: la migración crea los índices de fecha y de quien causó el cambio', function () {
    $indexes = DB::table('pg_indexes')->where('tablename', 'activity_log')->pluck('indexdef', 'indexname');

    expect($indexes['activity_log_created_at_index'])->toContain('(created_at)')
        ->and($indexes['activity_log_causer_id_id_index'])->toContain('(causer_id, id)')
        ->and($indexes['activity_log_event_index'])->toContain('(event)');
});
