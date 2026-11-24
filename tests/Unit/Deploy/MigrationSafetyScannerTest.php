<?php

use Tests\Support\MigrationSafetyScanner;

/*
| ADR-0033: la guardia expand/contract lee el código de una migración y avisa de lo que rompería al release
| anterior mientras conviven. Estas pruebas le dan migraciones como cadenas, una por regla.
*/

/** Una migración con el cuerpo de `up()` dado y, opcionalmente, el atributo de contracción en la clase. */
function migrationSafetySource(string $up, string $down = '', bool $contract = false): string
{
    $attribute = $contract ? "#[Contract('el release 1.30 dejó de usar la columna')] " : '';

    return <<<PHP
    <?php

    use App\Modules\Core\Database\Contract;
    use Illuminate\Database\Migrations\Migration;
    use Illuminate\Database\Schema\Blueprint;
    use Illuminate\Support\Facades\DB;
    use Illuminate\Support\Facades\Schema;

    return new {$attribute}class extends Migration
    {
        public function up(): void
        {
            {$up}
        }

        public function down(): void
        {
            {$down}
        }
    };
    PHP;
}

function migrationSafetyScan(string $source): array
{
    return (new MigrationSafetyScanner)->scan($source);
}

it('acepta crear una tabla nueva', function () {
    $source = migrationSafetySource("Schema::create('rooms', function (Blueprint \$table) { \$table->uuid('id')->primary(); \$table->string('name'); });");

    expect(migrationSafetyScan($source))->toBe([]);
});

it('acepta una columna nueva nullable o con default en una tabla existente', function () {
    $source = migrationSafetySource("Schema::table('rooms', function (Blueprint \$table) {
        \$table->string('code')->nullable();
        \$table->boolean('active')->default(true);
        \$table->timestampTz('seen_at')->nullable()->index();
        \$table->index('code');
    });");

    expect(migrationSafetyScan($source))->toBe([]);
});

it('rechaza una columna NOT NULL sin default en una tabla existente', function () {
    $source = migrationSafetySource("Schema::table('rooms', function (Blueprint \$table) { \$table->string('code'); });");

    $violations = migrationSafetyScan($source);

    expect($violations)->toHaveCount(1)
        ->and($violations[0])->toContain('nullable() o default()');
});

it('rechaza las operaciones destructivas sin la marca', function (string $up) {
    $violations = migrationSafetyScan(migrationSafetySource($up));

    expect($violations)->toHaveCount(1)
        ->and($violations[0])->toContain('#[Contract');
})->with([
    'dropColumn' => ["Schema::table('rooms', fn (Blueprint \$table) => \$table->dropColumn('code'));"],
    'renameColumn' => ["Schema::table('rooms', fn (Blueprint \$table) => \$table->renameColumn('code', 'slug'));"],
    'drop' => ["Schema::drop('rooms');"],
    'dropIfExists' => ["Schema::dropIfExists('rooms');"],
    'rename' => ["Schema::rename('rooms', 'spaces');"],
    'change' => ["Schema::table('rooms', function (Blueprint \$table) { \$table->text('code')->nullable()->change(); });"],
]);

it('rechaza SQL crudo con DROP, RENAME, ALTER ... TYPE o SET NOT NULL', function (string $sql) {
    $violations = migrationSafetyScan(migrationSafetySource("DB::statement(\"{$sql}\");"));

    expect($violations)->not->toBeEmpty()
        ->and($violations[0])->toContain('SQL crudo');
})->with([
    'DROP' => ['ALTER TABLE rooms DROP COLUMN code'],
    'RENAME' => ['ALTER TABLE rooms RENAME COLUMN code TO slug'],
    'ALTER TYPE' => ['ALTER TABLE rooms ALTER COLUMN code TYPE text'],
    'SET NOT NULL' => ['ALTER TABLE rooms ALTER COLUMN code SET NOT NULL'],
]);

it('acepta cualquiera de las anteriores con el atributo Contract', function () {
    $source = migrationSafetySource(
        "Schema::table('rooms', function (Blueprint \$table) { \$table->dropColumn('code'); \$table->string('x'); });
        DB::statement('ALTER TABLE rooms DROP COLUMN y');",
        contract: true,
    );

    expect(migrationSafetyScan($source))->toBe([]);
});

it('ignora lo que está dentro de down()', function () {
    $source = migrationSafetySource(
        "Schema::create('rooms', function (Blueprint \$table) { \$table->uuid('id')->primary(); });",
        "Schema::dropIfExists('rooms'); Schema::table('x', fn (Blueprint \$table) => \$table->dropColumn('code'));",
    );

    expect(migrationSafetyScan($source))->toBe([]);
});
