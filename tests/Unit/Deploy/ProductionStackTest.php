<?php

use Symfony\Component\Yaml\Tag\TaggedValue;
use Symfony\Component\Yaml\Yaml;

/*
| Guardas del despliegue de producción (ADR-0013). Leen los archivos de compose
| sin Docker y validan lo que no debe cambiar por descuido: imágenes fijadas,
| puertos publicados, valores seguros y migraciones en un solo contenedor.
*/

/** @return array<string, mixed> */
function deployFile(string $path): array
{
    return Yaml::parseFile(dirname(__DIR__, 3).'/'.$path, Yaml::PARSE_CUSTOM_TAGS);
}

/** @return array<string, array<string, mixed>> */
function deployServices(string $file): array
{
    return deployFile($file)['services'];
}

it('fija por tag y digest toda imagen de terceros del stack de producción', function (string $file) {
    foreach (deployServices($file) as $name => $service) {
        $image = $service['image'] ?? null;

        if ($image === null || str_starts_with($image, '${APP_IMAGE')) {
            continue;
        }

        expect($image)->toMatch('/^[a-z0-9._\/-]+:[A-Za-z0-9._-]+@sha256:[0-9a-f]{64}$/', "El servicio {$name} no fija su imagen por digest");
    }
})->with(['compose.yaml', 'compose.prod.yaml']);

it('respalda con la misma imagen de PostgreSQL que la base de datos', function () {
    // pg_dump debe ser de la misma versión mayor que el servidor.
    expect(deployServices('compose.prod.yaml')['backup']['image'])
        ->toBe(deployServices('compose.yaml')['postgres']['image']);
});

it('publica puertos solo desde Caddy y deja el proxy de desarrollo fuera de producción', function () {
    $prod = deployServices('compose.prod.yaml');

    $publishers = array_keys(array_filter($prod, fn (array $service) => ! empty($service['ports'])));

    expect($publishers)->toBe(['caddy'])
        ->and($prod['nginx']['profiles'])->not->toBeEmpty();

    foreach (['app', 'queue', 'scheduler', 'postgres', 'valkey', 'backup'] as $internal) {
        expect($prod[$internal] ?? [])->not->toHaveKey('ports');
    }
});

it('fuerza valores seguros de producción aunque el .env traiga los de desarrollo', function (string $service) {
    $environment = deployServices('compose.prod.yaml')[$service]['environment'];

    expect($environment['APP_ENV'])->toBe('production')
        ->and($environment['APP_DEBUG'])->toBe('false')
        ->and($environment['SESSION_SECURE_COOKIE'])->toBe('true')
        ->and($environment['APP_URL'])->toStartWith('https://');
})->with(['app', 'queue', 'scheduler']);

it('ejecuta las migraciones solo en el contenedor app y hace esperar a queue', function () {
    $prod = deployServices('compose.prod.yaml');

    expect($prod['app']['environment']['RUN_MIGRATIONS'])->toBe('true')
        ->and($prod['queue']['environment']['RUN_MIGRATIONS'])->toBe('false')
        ->and($prod['queue']['depends_on']['app']['condition'])->toBe('service_healthy');
});

it('reinicia solo todo servicio que corre en producción', function () {
    $base = deployServices('compose.yaml');
    $prod = deployServices('compose.prod.yaml');

    foreach (array_diff_key($base, ['nginx' => true]) as $name => $service) {
        expect($service['restart'] ?? $prod[$name]['restart'] ?? null)->toBe('unless-stopped', "{$name} sin restart");
    }

    foreach (['caddy', 'backup'] as $name) {
        expect($prod[$name]['restart'])->toBe('unless-stopped');
    }
});

it('entrega una plantilla de .env de producción sin secretos reales', function () {
    $lines = file(dirname(__DIR__, 3).'/.env.production.example', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    $env = collect($lines)
        ->reject(fn (string $line) => str_starts_with($line, '#'))
        ->mapWithKeys(function (string $line) {
            [$key, $value] = explode('=', $line, 2);

            return [$key => trim($value, '"')];
        });

    expect($env['APP_ENV'])->toBe('production')
        ->and($env['APP_DEBUG'])->toBe('false')
        ->and($env['APP_KEY'])->toBe('')
        ->and($env['DB_PASSWORD'])->toStartWith('CAMBIAR')
        ->and($env['REDIS_PASSWORD'])->toStartWith('CAMBIAR')
        ->and($env['ADMIN_INITIAL_PASSWORD'])->toStartWith('CAMBIAR')
        ->and($env['APP_DOMAIN'])->not->toBeEmpty()
        ->and($env['ACME_EMAIL'])->not->toBeEmpty();
});

it('deja apagada por defecto la contraseña previa al sitio del túnel de demostración', function () {
    $caddy = deployServices('compose.prod.yaml')['caddy']['environment'];
    $caddyfile = file_get_contents(dirname(__DIR__, 3).'/docker/caddy/Caddyfile');
    $template = file_get_contents(dirname(__DIR__, 3).'/.env.production.example');

    // Vacías por defecto y con un matcher que sin usuario nunca coincide.
    expect($caddy['BASIC_AUTH_USER'])->toBe('${BASIC_AUTH_USER:-}')
        ->and($caddy['BASIC_AUTH_HASH'])->toBe('${BASIC_AUTH_HASH:-}')
        ->and($caddyfile)->toContain('@gate expression `"{$BASIC_AUTH_USER:}" != ""`')
        ->and($caddyfile)->toContain('basic_auth @gate')
        ->and($caddyfile)->toContain('{$BASIC_AUTH_HASH:b2Zm}')
        ->and($template)->toMatch('/^# BASIC_AUTH_USER=/m')
        ->and($template)->not->toMatch('/^BASIC_AUTH_/m');
});

it('corre lo programado solo en producción, sin migrar y después de app', function () {
    $base = deployServices('compose.yaml')['scheduler'];
    $prod = deployServices('compose.prod.yaml')['scheduler'];

    expect($base['command'])->toBe(['php', 'artisan', 'schedule:work'])
        ->and($base['profiles'])->toBe(['scheduler'])
        ->and($prod['profiles'])->toBeInstanceOf(TaggedValue::class)
        ->and($prod['profiles']->getTag())->toBe('reset')
        ->and($prod['environment']['RUN_MIGRATIONS'])->toBe('false')
        ->and($prod['depends_on']['app']['condition'])->toBe('service_healthy')
        ->and($prod['volumes'])->toContain('app-storage:/app/storage/app');
});

it('deja las cabeceras de seguridad a la aplicación y en Caddy solo HSTS', function () {
    $caddyfile = file_get_contents(dirname(__DIR__, 3).'/docker/caddy/Caddyfile');

    expect($caddyfile)->toContain('Strict-Transport-Security')
        ->and($caddyfile)->toContain('-Server')
        ->and($caddyfile)->not->toContain('X-Content-Type-Options')
        ->and($caddyfile)->not->toContain('Referrer-Policy');
});
