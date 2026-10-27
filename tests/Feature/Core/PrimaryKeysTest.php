<?php

use App\Modules\Core\Models\Campus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
| ADR-0014: las tablas de Nexus usan UUIDv7 en la llave primaria y en toda columna que apunta a
| un registro, también las polimórficas de la bitácora y de spatie. Solo conservan bigint las
| tablas de infraestructura de Laravel y de spatie, que no son entidades de Nexus: sus llaves
| no salen en URLs ni las referencia otra tabla de Nexus.
*/

$infrastructureTables = ['activity_log', 'failed_jobs', 'jobs', 'permissions', 'roles'];

$columns = fn (): array => DB::select(<<<'SQL'
    select table_name, column_name, data_type, column_default
    from information_schema.columns
    where table_schema = current_schema() and table_name <> 'migrations'
    SQL);

// Las sesiones y los lotes de trabajos tienen llave de texto propia de Laravel, que no es un registro de Nexus.
it('usa uuid en la llave primaria de toda tabla salvo las de infraestructura', function () use ($columns, $infrastructureTables) {
    $bigintKeys = collect($columns())
        ->filter(fn (object $column): bool => $column->column_name === 'id' && in_array($column->data_type, ['bigint', 'integer'], true))
        ->pluck('table_name')
        ->values();

    expect($bigintKeys->all())->toEqualCanonicalizing($infrastructureTables);
});

it('genera la llave UUIDv7 en la base cuando un insert no la trae', function () use ($columns) {
    $withoutDefault = collect($columns())
        ->filter(fn (object $column): bool => $column->column_name === 'id' && $column->data_type === 'uuid' && $column->column_default !== 'uuidv7()')
        ->pluck('table_name');

    DB::table('campuses')->insert(['code' => 'C9', 'name' => 'Campus de prueba', 'modality' => 'presencial']);

    expect($withoutDefault->all())->toBeEmpty()
        ->and(Str::isUuid(DB::table('campuses')->where('code', 'C9')->value('id'), 7))->toBeTrue();
});

it('usa uuid en toda columna que apunta a un registro de Nexus', function () use ($columns) {
    $bigintReferences = collect($columns())
        ->filter(fn (object $column): bool => str_ends_with($column->column_name, '_id') && in_array($column->data_type, ['bigint', 'integer'], true))
        ->map(fn (object $column): string => "{$column->table_name}.{$column->column_name}")
        ->values();

    // Solo las que apuntan a los roles y permisos de spatie, que siguen en bigint.
    expect($bigintReferences->all())->toEqualCanonicalizing([
        'model_has_permissions.permission_id',
        'model_has_roles.role_id',
        'role_has_permissions.permission_id',
        'role_has_permissions.role_id',
    ]);
});

it('genera un UUIDv7 en la app al crear un modelo', function () {
    expect(Str::isUuid(Campus::factory()->create()->id, 7))->toBeTrue();
});
