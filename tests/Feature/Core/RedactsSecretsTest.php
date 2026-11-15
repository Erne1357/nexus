<?php

use App\Modules\Core\Actions\RecordMail;
use App\Modules\Core\Enums\MailStatus;
use App\Modules\Core\Models\FailedJob;
use App\Modules\Core\Support\RedactsSecrets;

/*
| RedactsSecrets (CF-S8): lo que parece un token o una firma no se guarda en la bitácora ni en el registro de correos, ni
| se muestra en la lista o el drawer de trabajos fallidos.
*/

it('oculta cada forma de secreto', function (string $text, string $secret) {
    expect(RedactsSecrets::redact($text))->not->toContain($secret)->toContain('[oculto]');
})->with([
    'token en la URL' => ['https://nexus.test/x?token=SECRETO&a=1', 'SECRETO'],
    'firma en la URL' => ['https://nexus.test/x?expires=1&signature=SECRETO', 'SECRETO'],
    'clave con guion bajo' => ['falló reset_token=SECRETO y access_token=SECRETO', 'SECRETO'],
    'mayúsculas' => ['Token=SECRETO', 'SECRETO'],
    'JSON' => ['{"token":"SECRETO","a":1}', 'SECRETO'],
    'JSON con prefijo y espacios' => ['{"reset_signature" : "SECRETO"}', 'SECRETO'],
    'JSON escapado' => ['{\"token\":\"SECRETO\"}', 'SECRETO'],
    'ruta de restablecer contraseña' => ['GET https://nexus.test/reset-password/SECRETO?email=a', 'SECRETO'],
    'ruta de la invitación de acceso' => ['https://nexus.test/access/0190-aaaa/SECRETO?expires=1', 'SECRETO'],
]);

it('no toca el texto sin secretos', function () {
    expect(RedactsSecrets::redact('RuntimeException: Conexión rechazada en /app/x.php:10'))->toBe('RuntimeException: Conexión rechazada en /app/x.php:10');
});

it('RecordMail guarda el error sin secretos', function () {
    $log = app(RecordMail::class)->handle(['a@itcj.test'], 'Asunto', 'Probe', MailStatus::Failed, 'Falló https://nexus.test/x?signature=SECRETO');

    expect($log->error)->not->toContain('SECRETO')->toContain('signature=[oculto]');
});

it('el resumen del trabajo fallido, que va a la bitácora, no lleva secretos', function () {
    $job = (new FailedJob)->forceFill(['exception' => "RuntimeException: https://nexus.test/reset-password/SECRETO\n#0 pila"]);

    expect($job->summary())->not->toContain('SECRETO');
});
