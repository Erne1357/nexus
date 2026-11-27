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

        // Las imágenes propias (la app, el servicio del motor, ADR-0027, y el deployer, ADR-0033) se construyen del
        // repositorio: no son de terceros. El deployer fija por release la imagen exacta de cada una.
        if ($image === null || preg_match('/^\$\{(APP|SOLVER|DEPLOYER)_IMAGE/', $image) === 1) {
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
})->with(['app-blue', 'app-green', 'queue', 'scheduler', 'migrate']);

it('migra solo con el servicio migrate, de un solo uso y con lock_timeout', function () {
    $prod = deployServices('compose.prod.yaml');

    expect($prod['migrate']['profiles'])->toBe(['deploy'])
        ->and($prod['migrate']['command'])->toBe(['php', 'artisan', 'migrate', '--force', '--isolated', '--no-interaction'])
        ->and($prod['migrate']['environment']['PGOPTIONS'])->toBe('-c lock_timeout=${MIGRATE_LOCK_TIMEOUT:-10s}');

    foreach (['app-blue', 'app-green', 'queue', 'scheduler'] as $name) {
        expect($prod[$name]['environment']['RUN_MIGRATIONS'])->toBe('false', "{$name} no debe migrar");
    }
});

it('tiene dos colores idénticos salvo el nombre y deja app solo para desarrollo', function () {
    $prod = deployServices('compose.prod.yaml');

    expect($prod['app']['profiles'])->toBe(['dev-only'])
        ->and($prod['app-blue'])->toEqual($prod['app-green'])
        ->and($prod['app-blue']['healthcheck']['test'])->toBe(['CMD', 'curl', '-fsS', 'http://127.0.0.1:8000/up']);
});

it('no hace esperar a la cola, al scheduler ni a Caddy por app: el deployer ordena los pasos', function () {
    $prod = deployServices('compose.prod.yaml');

    foreach (['queue', 'scheduler', 'caddy'] as $name) {
        expect($prod[$name]['depends_on'] ?? [])->not->toHaveKey('app', "{$name} depende de app");
    }
});

it('reparte a un solo color sano en Caddy y sirve los assets compartidos', function () {
    $caddyfile = file_get_contents(dirname(__DIR__, 3).'/docker/caddy/Caddyfile');
    $caddy = deployServices('compose.prod.yaml')['caddy'];

    expect($caddyfile)->toContain('reverse_proxy app-blue:8000 app-green:8000')
        ->and($caddyfile)->toContain('lb_policy first')
        ->and($caddyfile)->toContain('health_uri /health')
        ->and($caddyfile)->toContain('handle /build/*')
        ->and($caddy['volumes'])->toContain('build-assets:/srv/build:ro')
        ->and($caddy['volumes'])->toContain('${NEXUS_CONFIG_DIR:-./docker}/caddy:/etc/caddy:ro');
});

it('monta la configuración de Caddy y de los respaldos desde una carpeta estable', function () {
    $prod = deployServices('compose.prod.yaml');

    // Una ruta que cambia con cada release recrearía Caddy (y cortaría las conexiones) en cada despliegue.
    expect($prod['backup']['volumes'])->toContain('${NEXUS_CONFIG_DIR:-./docker}/backup:/opt/backup:ro');
});

it('corre el deployer sin puertos, con el socket de Docker y la carpeta de releases en la misma ruta', function () {
    $deployer = deployServices('compose.prod.yaml')['deployer'];

    expect($deployer)->not->toHaveKey('ports')
        ->and($deployer['restart'])->toBe('unless-stopped')
        ->and($deployer['volumes'])->toContain('/var/run/docker.sock:/var/run/docker.sock')
        ->and($deployer['volumes'])->toContain('${NEXUS_HOME:-/opt/nexus}:${NEXUS_HOME:-/opt/nexus}')
        ->and($deployer['env_file'][1])->toBe(['path' => '${NEXUS_HOME:-/opt/nexus}/deployer.env', 'required' => false]);
});

it('no pone el token de GitHub del deployer en el .env que leen la app y la cola', function () {
    $template = file_get_contents(dirname(__DIR__, 3).'/.env.production.example');

    expect($template)->not->toMatch('/^DEPLOY_GITHUB_TOKEN=/m');
});

it('fija por digest la imagen base del deployer', function () {
    $dockerfile = file_get_contents(dirname(__DIR__, 3).'/docker/deployer/Dockerfile');

    expect($dockerfile)->toMatch('/^FROM docker:[0-9.]+-cli@sha256:[0-9a-f]{64}$/m');
});

it('reinicia solo todo servicio que corre en producción', function () {
    $base = deployServices('compose.yaml');
    $prod = deployServices('compose.prod.yaml');

    foreach (array_diff_key($base, ['nginx' => true]) as $name => $service) {
        expect($service['restart'] ?? $prod[$name]['restart'] ?? null)->toBe('unless-stopped', "{$name} sin restart");
    }

    // Un color detenido por el deployer sigue detenido tras reiniciar el servidor; el activo vuelve solo.
    foreach (['caddy', 'backup', 'app-blue', 'app-green', 'deployer'] as $name) {
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

it('trae el correo de producción por Microsoft Graph con las tres variables de la app de Azure por llenar (tarea 1.29)', function () {
    $template = file_get_contents(dirname(__DIR__, 3).'/.env.production.example');

    expect($template)->toMatch('/^MAIL_MAILER=microsoft-graph$/m')
        ->and($template)->toMatch('/^MAIL_FROM_ADDRESS=/m')
        ->and($template)->toMatch('/^MAIL_FROM_NAME=/m')
        ->and($template)->toMatch('/^MICROSOFT_GRAPH_TENANT_ID=CAMBIAR$/m')
        ->and($template)->toMatch('/^MICROSOFT_GRAPH_CLIENT_ID=CAMBIAR$/m')
        ->and($template)->toMatch('/^MICROSOFT_GRAPH_CLIENT_SECRET=CAMBIAR$/m')
        ->and($template)->not->toMatch('/^MAIL_MAILER=log$/m');
});

it('deja apagada por defecto la contraseña previa al sitio del túnel de demostración', function () {
    $caddy = deployServices('compose.prod.yaml')['caddy']['environment'];
    $caddyfile = file_get_contents(dirname(__DIR__, 3).'/docker/caddy/Caddyfile');
    $template = file_get_contents(dirname(__DIR__, 3).'/.env.production.example');

    // Vacías por defecto y con un matcher que sin usuario nunca coincide.
    expect($caddy['BASIC_AUTH_USER'])->toBe('${BASIC_AUTH_USER:-}')
        ->and($caddy['BASIC_AUTH_HASH'])->toBe('${BASIC_AUTH_HASH:-}')
        ->and($caddyfile)->toContain('expression `"{$BASIC_AUTH_USER:}" != ""`')
        ->and($caddyfile)->toContain('not path /health')
        ->and($caddyfile)->toContain('basic_auth @gate')
        ->and($caddyfile)->toContain('{$BASIC_AUTH_HASH:b2Zm}')
        ->and($template)->toMatch('/^# BASIC_AUTH_USER=/m')
        ->and($template)->not->toMatch('/^BASIC_AUTH_/m');
});

it('corre lo programado solo en producción y sin migrar', function () {
    $base = deployServices('compose.yaml')['scheduler'];
    $prod = deployServices('compose.prod.yaml')['scheduler'];

    expect($base['command'])->toBe(['php', 'artisan', 'schedule:work'])
        ->and($base['profiles'])->toBe(['scheduler'])
        ->and($prod['profiles'])->toBeInstanceOf(TaggedValue::class)
        ->and($prod['profiles']->getTag())->toBe('reset')
        ->and($prod['environment']['RUN_MIGRATIONS'])->toBe('false')
        ->and($prod['volumes'])->toContain('app-storage:/app/storage/app');
});

it('deja las cabeceras de seguridad a la aplicación y en Caddy solo HSTS', function () {
    $caddyfile = file_get_contents(dirname(__DIR__, 3).'/docker/caddy/Caddyfile');

    expect($caddyfile)->toContain('Strict-Transport-Security')
        ->and($caddyfile)->toContain('-Server')
        ->and($caddyfile)->not->toContain('X-Content-Type-Options')
        ->and($caddyfile)->not->toContain('Referrer-Policy');
});

it('la imagen prod lleva el release por build-arg', function () {
    $dockerfile = file_get_contents(dirname(__DIR__, 3).'/Dockerfile');
    $prod = substr($dockerfile, (int) strpos($dockerfile, 'FROM base AS prod'));

    expect($prod)->toContain('ARG NEXUS_RELEASE')
        ->and($prod)->toContain('ENV NEXUS_RELEASE=$NEXUS_RELEASE');
});

it('escribe los registros de producción en JSON', function (string $service) {
    expect(deployServices('compose.prod.yaml')[$service]['environment']['LOG_STDERR_FORMATTER'])
        ->toBe('Monolog\Formatter\JsonFormatter');
})->with(['app-blue', 'app-green', 'queue', 'scheduler', 'migrate']);

it('pone un X-Request-Id propio en cada petición y lo deja en el registro de Caddy', function () {
    $caddyfile = file_get_contents(dirname(__DIR__, 3).'/docker/caddy/Caddyfile');

    expect($caddyfile)->toContain('request_header X-Request-Id {http.request.uuid}')
        ->and($caddyfile)->toContain('log_append request_id {http.request.uuid}');
});

it('no guarda la query, el Referer ni el Location en el registro de Caddy', function () {
    $caddyfile = file_get_contents(dirname(__DIR__, 3).'/docker/caddy/Caddyfile');

    expect($caddyfile)->toMatch('/request>uri regexp \S+ ""/')
        ->and($caddyfile)->toContain('\?.*')
        ->and($caddyfile)->toContain('request>headers>Referer delete')
        ->and($caddyfile)->toContain('resp_headers>Location delete');
});

it('expone las métricas de Caddy solo por la red interna y nunca la API admin', function () {
    $caddyfile = file_get_contents(dirname(__DIR__, 3).'/docker/caddy/Caddyfile');

    expect($caddyfile)->toMatch('/^:9180 \{\s+metrics\s+\}/m')
        ->and($caddyfile)->not->toMatch('/^\s*admin\s+(0\.0\.0\.0|:2019)/m')
        ->and(deployServices('compose.prod.yaml')['caddy']['ports'])
        ->toBe(['${HTTP_PORT:-80}:80', '${HTTPS_PORT:-443}:443', '${HTTPS_PORT:-443}:443/udp']);
});
