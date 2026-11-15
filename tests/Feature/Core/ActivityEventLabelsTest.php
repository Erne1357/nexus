<?php

use App\Modules\Core\Support\ActivityEvents;
use Spatie\Activitylog\Enums\ActivityEvent;

/*
| CF-S5: la «Bitácora» muestra el evento de cada entrada en español, no la clave con que se guarda (`access-invited`,
| `teacher_assigned`). Toda clave que el código escribe tiene su nombre: las de `->event('…')`, las constantes `EVENT` y
| las de spatie (created, updated, deleted, restored). Una clave que no conoce sale tal cual, sin guiones.
*/

/**
 * Las claves de evento que el código de los módulos escribe en la bitácora.
 *
 * @return array<string, list<string>> clave => archivos donde se escribe
 */
function activityEventsWritten(): array
{
    $events = [];
    $root = base_path('app/Modules');

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $file) {
        $path = $file->getPathname();

        if (! str_ends_with($path, '.php')) {
            continue;
        }

        $code = file_get_contents($path);
        preg_match_all('/->event\(\s*\'([a-z][a-z_-]*)\'/', $code, $calls);
        preg_match_all('/const\s+(?:string\s+)?[A-Z_]*EVENT\s*=\s*\'([a-z][a-z_-]*)\'/', $code, $constants);

        foreach ([...$calls[1], ...$constants[1]] as $event) {
            $events[$event][] = substr($path, strlen($root) + 1);
        }
    }

    foreach (ActivityEvent::cases() as $case) {
        $events[$case->value][] = 'spatie';
    }

    return $events;
}

it('CF-S5: todo evento que el código escribe en la bitácora tiene su nombre en español', function () {
    $missing = [];

    foreach (activityEventsWritten() as $event => $files) {
        if (! ActivityEvents::knows($event)) {
            $missing[] = $event.' ('.implode(', ', array_unique($files)).')';
        }
    }

    expect(activityEventsWritten())->toHaveKeys(['created', 'access-invited', 'teacher_assigned', 'session_room_assigned'])
        ->and($missing)->toBe([]);
});

it('CF-S5: el evento sale en español y una clave desconocida, sin guiones', function () {
    expect(ActivityEvents::label('access-invited'))->toBe('Invitación de acceso')
        ->and(ActivityEvents::label('updated'))->toBe('Cambio')
        ->and(ActivityEvents::label('algo_nuevo-raro'))->toBe('algo nuevo raro')
        ->and(ActivityEvents::label(null))->toBe('—');
});
