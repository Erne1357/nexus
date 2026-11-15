<?php

use App\Modules\Core\Enums\Role;
use App\Modules\Core\Support\ActivityAttributes;

/*
| Cómo muestra la bitácora las columnas y los valores (CF-S5). La instancia de la aplicación trae lo que declaran los
| módulos; las de aquí solo lo que cada prueba declara.
*/

enum ActivityAttributesProbeStatus: string
{
    case Open = 'open';
    case Plain = 'plain';

    public function label(): string
    {
        return $this === self::Open ? 'Abierto' : $this->value;
    }
}

function activityLabels(array $declared = []): ActivityAttributes
{
    return new ActivityAttributes($declared);
}

it('CF-S5: las fechas con hora salen dd/mm/aaaa hh:mm en America/Ciudad_Juarez', function () {
    expect(activityLabels()->format(null, 'x', '2026-11-15T14:53:45-07:00'))->toBe('15/11/2026 14:53')
        ->and(activityLabels()->format(null, 'x', '2026-11-15T21:53:45+00:00'))->toBe('15/11/2026 14:53')
        ->and(activityLabels()->format(null, 'x', '2026-11-15 14:53:45'))->toBe('15/11/2026 14:53');
});

it('CF-S5: las fechas solas salen dd/mm/aaaa y las horas hh:mm', function () {
    expect(activityLabels()->format(null, 'x', '2027-01-18'))->toBe('18/01/2027')
        ->and(activityLabels()->format(null, 'x', '07:00:00'))->toBe('07:00');
});

it('CF-S5: los booleanos salen Sí o No y lo nulo o vacío, —', function () {
    expect(activityLabels()->format(null, 'x', true))->toBe('Sí')
        ->and(activityLabels()->format(null, 'x', false))->toBe('No')
        ->and(activityLabels()->format(null, 'x', null))->toBe('—')
        ->and(activityLabels()->format(null, 'x', ''))->toBe('—')
        ->and(activityLabels()->format(null, 'x', []))->toBe('—');
});

it('CF-S5: un enum declarado sale con su label() y, sin label o sin caso, con su valor', function () {
    $attributes = activityLabels(['*' => ['status' => ['Estado', ActivityAttributesProbeStatus::class]]]);

    expect($attributes->format(null, 'status', 'open'))->toBe('Abierto')
        ->and($attributes->format(null, 'status', 'plain'))->toBe('plain')
        ->and($attributes->format(null, 'status', 'desconocido'))->toBe('desconocido')
        ->and($attributes->label(null, 'status'))->toBe('Estado');
});

it('CF-S5: la etiqueta de un tipo gana a la común, y sin mapa sale la columna con espacios', function () {
    $attributes = activityLabels([
        '*' => ['name' => 'Nombre', 'note' => 'Nota'],
        'Role' => ['name' => 'Nombre del puesto'],
    ]);

    expect($attributes->label('App\\Modules\\Core\\Models\\Role', 'name'))->toBe('Nombre del puesto')
        ->and($attributes->label('App\\Modules\\Core\\Models\\Campus', 'name'))->toBe('Nombre')
        ->and($attributes->label(null, 'note'))->toBe('Nota')
        ->and($attributes->label(null, 'close_justified_by'))->toBe('close justified by');
});

it('CF-S5: lo compuesto sale como JSON y los números tal cual', function () {
    expect(activityLabels()->format(null, 'x', ['a' => 'ñ']))->toBe('{"a":"ñ"}')
        ->and(activityLabels()->format(null, 'x', 12))->toBe('12')
        ->and(activityLabels()->format(null, 'x', 'Texto normal'))->toBe('Texto normal');
});

it('CF-S5: los módulos declaran sus columnas y enums, y la aplicación los reúne', function () {
    $attributes = app(ActivityAttributes::class);

    expect($attributes->label(null, 'email'))->toBe('Correo')
        ->and($attributes->label(null, 'access_invited_at'))->toBe('Invitación enviada')
        ->and($attributes->format(null, 'role', 'admin'))->toBe(Role::Admin->label())
        ->and($attributes->label('App\\Modules\\Facilities\\Models\\Room', 'type'))->toBe('Tipo de salón')
        ->and($attributes->format('App\\Modules\\Facilities\\Models\\Room', 'type', 'laboratorio'))->not->toBe('laboratorio');
});

it('CF-S5: una fecha que llega como ISO a medianoche de Ciudad Juárez sale sin hora', function () {
    expect(activityLabels()->format(null, 'valid_from', '2026-11-15T00:00:00-07:00'))->toBe('15/11/2026')
        ->and(activityLabels()->format(null, 'valid_from', '2026-11-15T07:00:00+00:00'))->toBe('15/11/2026')
        ->and(activityLabels()->format(null, 'x', '2026-11-15T00:30:00-07:00'))->toBe('15/11/2026 00:30');
});

it('CF-S5: un texto libre que empieza con una fecha o una hora queda igual', function () {
    expect(activityLabels()->format(null, 'note', '2026-11-15 se pidió el cambio'))->toBe('2026-11-15 se pidió el cambio')
        ->and(activityLabels()->format(null, 'reason', '2026-11-15T10:00 junta'))->toBe('2026-11-15T10:00 junta')
        ->and(activityLabels()->format(null, 'reason', '07:30 en la mañana'))->toBe('07:30 en la mañana');
});
