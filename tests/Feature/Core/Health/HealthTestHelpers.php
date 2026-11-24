<?php

use Illuminate\Support\Facades\Cache;

/**
 * Escribe en `$directory` un archivo de migración que no hace nada y devuelve su nombre. Con `$contract`, la clase lleva
 * #[Contract].
 */
function healthFakeMigration(string $directory, string $name = '2999_01_01_000000_health_pending', bool $contract = false): string
{
    if (! is_dir($directory)) {
        mkdir($directory, 0777, true);
    }

    $attribute = $contract ? "#[\\App\\Modules\\Core\\Database\\Contract('ya nadie lee la columna')] " : '';

    file_put_contents($directory.'/'.$name.'.php', "<?php\n\nuse Illuminate\\Database\\Migrations\\Migration;\n\nreturn new {$attribute}class extends Migration\n{\n    public function up(): void {}\n};\n");

    return $name;
}

/** Un directorio temporal vacío para migraciones de prueba. */
function healthTempDirectory(): string
{
    $directory = sys_get_temp_dir().'/health-'.bin2hex(random_bytes(6));
    mkdir($directory);

    return $directory;
}

/** Los dos latidos recién escritos. */
function healthFreshHeartbeats(): void
{
    Cache::put('health:heartbeat:scheduler', now()->timestamp, 600);
    Cache::put('health:heartbeat:queue', now()->timestamp, 600);
}
