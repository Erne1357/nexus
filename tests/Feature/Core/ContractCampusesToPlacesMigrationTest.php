<?php

use App\Modules\Academic\Models\Career;
use App\Modules\Academic\Models\CareerCampus;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\User;
use App\Modules\Students\Models\Student;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Activity;

/*
| Contraer (ronda 2 de la carga por modalidad, ADR-0025): el campus queda solo como el lugar (MS3, supuesto por confirmar).
| La migración quita `campuses.modality`, `campuses.settings` y el puente `campuses.modality_id`, y desactiva y borra
| lógicamente las filas de campus que eran modalidades (EAD, ENL y TNMV: su `modality` no es presencial). Si algo las
| referencia (cualquier llave foránea a `campuses`, cualquier columna `campus_id`, un parámetro de `settings` de campus o
| un aviso guardado con su campus), se detiene, lista cada referencia y no escribe nada. Se prueba sobre la forma vieja:
| `down()`, filas con `DB::table()` y `up()`.
*/

const CONTRACTION = 'app/Modules/Core/Database/Migrations/2026_11_12_211102_contract_campuses_to_places.php';

beforeEach(function () {
    $this->migration = require base_path(CONTRACTION);
    $this->migration->down();
    $this->c1 = campusBeforeContraction('C1');
    $this->c2 = campusBeforeContraction('C2');
    $this->ead = campusBeforeContraction('EAD', 'mixta', active: false);
    $this->enl = campusBeforeContraction('ENL', 'en_linea', active: false);
    $this->tnmv = campusBeforeContraction('TNMV', 'virtual_tecnm', active: false);
});

/**
 * Un aviso guardado con la forma de antes: el campus y no la modalidad.
 *
 * @param  array<string, string>  $parameters
 */
function storedNoticeWithCampus(string $type, string $campusId, array $parameters, ?string $modalityId = null): string
{
    $id = (string) Str::uuid7();

    DB::table('notifications')->insert([
        'id' => $id,
        'type' => $type,
        'notifiable_type' => User::class,
        'notifiable_id' => User::factory()->create()->id,
        'data' => json_encode([
            'title' => 'Aviso', 'body' => 'Cuerpo', 'icon' => 'o-bell', 'tone' => 'info', 'route' => 'scheduling.agreements',
            'parameters' => $parameters, 'term_id' => null, 'campus_id' => $campusId,
            ...($modalityId === null ? [] : ['modality_id' => $modalityId]),
        ]),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $id;
}

it('MS3: quita la modalidad, el perfil y el puente de campuses', function () {
    $this->migration->up();

    foreach (['modality', 'settings', 'modality_id'] as $column) {
        expect(Schema::hasColumn('campuses', $column))->toBeFalse("campuses.{$column} sigue");
    }
});

it('MS3: desactiva y borra lógicamente las filas de campus que eran modalidades y deja C1 y C2', function () {
    DB::table('campuses')->where('id', $this->ead->id)->update(['is_active' => true]);

    $this->migration->up();

    expect(Campus::query()->orderBy('code')->pluck('code')->all())->toBe(['C1', 'C2'])
        ->and(Campus::onlyTrashed()->orderBy('code')->pluck('code')->all())->toBe(['EAD', 'ENL', 'TNMV'])
        ->and(Campus::withTrashed()->whereIn('code', ['EAD', 'ENL', 'TNMV'])->where('is_active', true)->exists())->toBeFalse()
        ->and(Campus::query()->where('is_active', true)->count())->toBe(2);
});

it('MS3: si algo referencia una fila de campus que era modalidad, se detiene, lo lista y no escribe nada', function (Closure $reference, string $source) {
    DB::table('campuses')->where('id', $this->ead->id)->update(['is_active' => true]);
    $reference($this);

    expect(fn () => $this->migration->up())->toThrow(RuntimeException::class, "{$source}: 1");

    expect(Schema::hasColumn('campuses', 'modality_id'))->toBeTrue()
        ->and(Campus::withTrashed()->whereKey($this->ead->id)->value('deleted_at'))->toBeNull()
        ->and(Campus::withTrashed()->whereKey($this->ead->id)->value('is_active'))->toBeTrue();
})->with([
    'alumno' => [fn ($test) => Student::factory()->create(['campus_id' => $test->ead->id]), 'students.campus_id'],
    'nombramiento' => [fn ($test) => User::factory()->withRole(Role::Coordinador, ['campus_id' => $test->ead->id, 'career_id' => Career::factory()->create()->id])->create(), 'role_assignments.campus_id'],
    'oferta de carrera' => [fn ($test) => CareerCampus::factory()->create(['campus_id' => $test->enl->id]), 'career_campus.campus_id'],
    'parámetro de campus' => [fn ($test) => DB::table('settings')->insert(['scope' => 'campus', 'scope_id' => $test->tnmv->id, 'key' => 'x', 'value' => '1']), 'settings.scope_id (campus)'],
    'aviso con su campus' => [fn ($test) => storedNoticeWithCampus('App\\Modules\\Staffing\\Notifications\\ChangeRequestNotification', $test->ead->id, []), 'notifications.data.campus_id'],
]);

it('MS3: el guardia revisa cada llave foránea a campuses y cada columna campus_id, también las de una tabla nueva', function () {
    Schema::create('campus_guard_probe', function ($table) {
        $table->uuid('id')->primary();
        $table->foreignUuid('place_id')->constrained('campuses');
        $table->uuid('campus_id')->nullable();
    });

    $foreign = collect(DB::select(<<<'SQL'
        select kcu.table_name, kcu.column_name
        from information_schema.referential_constraints rc
        join information_schema.key_column_usage kcu on kcu.constraint_name = rc.constraint_name and kcu.constraint_schema = rc.constraint_schema
        join information_schema.table_constraints target on target.constraint_name = rc.unique_constraint_name and target.constraint_schema = rc.unique_constraint_schema
        where target.table_name = 'campuses'
        SQL))->map(fn (object $row): string => "{$row->table_name}.{$row->column_name}");
    $columns = collect(DB::select("select table_name from information_schema.columns where column_name = 'campus_id' and table_schema = any (current_schemas(false))"))
        ->map(fn (object $row): string => "{$row->table_name}.campus_id");
    $checked = array_keys($this->migration->referenceCounts([$this->ead->id]));

    expect($foreign->merge($columns)->unique()->diff($checked)->values()->all())->toBe([])
        ->and($checked)->toContain('campus_guard_probe.place_id', 'campus_guard_probe.campus_id', 'settings.scope_id (campus)', 'notifications.data.campus_id');

    DB::table('campus_guard_probe')->insert(['id' => (string) Str::uuid7(), 'place_id' => $this->c1->id, 'campus_id' => $this->tnmv->id]);

    expect(fn () => $this->migration->up())->toThrow(RuntimeException::class, 'campus_guard_probe.campus_id: 1');

    DB::table('campus_guard_probe')->update(['place_id' => $this->ead->id, 'campus_id' => null]);

    expect(fn () => $this->migration->up())->toThrow(RuntimeException::class, 'campus_guard_probe.place_id: 1');
});

it('MS3: en una llave foránea compuesta cuenta solo la columna que apunta a campuses.id, y una columna campus_id de texto se compara sin error', function () {
    DB::statement('alter table campuses add constraint campuses_id_code_unique unique (id, code)');
    Schema::create('campus_guard_composite', function ($table) {
        $table->uuid('id')->primary();
        $table->string('place_code');
        $table->uuid('place_id');
        $table->foreign(['place_code', 'place_id'], 'campus_guard_composite_place_foreign')->references(['code', 'id'])->on('campuses');
    });
    Schema::create('campus_guard_text', function ($table) {
        $table->uuid('id')->primary();
        $table->string('campus_id')->nullable();
    });

    $checked = array_keys($this->migration->referenceCounts([$this->ead->id]));

    expect($checked)->toContain('campus_guard_composite.place_id', 'campus_guard_text.campus_id')
        ->not->toContain('campus_guard_composite.place_code');

    DB::table('campus_guard_composite')->insert(['id' => (string) Str::uuid7(), 'place_code' => 'EAD', 'place_id' => $this->ead->id]);
    DB::table('campus_guard_text')->insert(['id' => (string) Str::uuid7(), 'campus_id' => 'no es un uuid']);

    expect($this->migration->referenceCounts([$this->ead->id]))->toMatchArray(['campus_guard_composite.place_id' => 1, 'campus_guard_text.campus_id' => 0]);
});

it('MS33: la bitácora de una fila de campus que era modalidad no detiene la contracción y queda igual', function () {
    activity()->performedOn($this->ead)->withProperties(['campus_id' => $this->ead->id])->event('updated')->log('Actualizó el campus EAD');
    $before = Activity::query()->orderBy('id')->get(['id', 'subject_id', 'properties'])->toArray();

    $this->migration->up();

    expect(Campus::onlyTrashed()->whereKey($this->ead->id)->exists())->toBeTrue()
        ->and(Activity::query()->orderBy('id')->get(['id', 'subject_id', 'properties'])->toArray())->toBe($before)
        ->and(Activity::query()->where('subject_id', $this->ead->id)->count())->toBe(1);
});

it('down() devuelve la modalidad, el perfil y el puente, y regresa las filas que eran modalidades', function () {
    $this->migration->up();
    $this->migration->down();

    $rows = DB::table('campuses')->join('modalities', 'modalities.id', '=', 'campuses.modality_id')->orderBy('campuses.code')
        ->get(['campuses.code', 'campuses.modality', 'modalities.code as catalog', 'campuses.deleted_at', 'campuses.is_active', 'campuses.settings', 'modalities.settings as profile']);

    expect($rows->map(fn (object $row): array => [$row->code, $row->modality, $row->catalog, $row->deleted_at === null, (bool) $row->is_active])->all())->toBe([
        ['C1', 'presencial', 'PRES', true, true],
        ['C2', 'presencial', 'PRES', true, true],
        ['EAD', 'mixta', 'EAD', true, false],
        ['ENL', 'en_linea', 'ENL', true, false],
        ['TNMV', 'virtual_tecnm', 'TNMV', true, false],
    ])->and($rows->every(fn (object $row): bool => json_decode($row->settings, true) == json_decode($row->profile, true)))->toBeTrue();
});
