<?php

it('usa la zona horaria de Ciudad Juárez', function () {
    expect(config('app.timezone'))->toBe('America/Ciudad_Juarez')
        ->and(now()->timezoneName)->toBe('America/Ciudad_Juarez');
});

it('abre las sesiones de PostgreSQL en la zona horaria de la aplicación', function () {
    // Sin esto, Postgres interpreta en UTC las fechas que Laravel envía sin desfase.
    expect(config('database.connections.pgsql.timezone'))->toBe(config('app.timezone'));
});
