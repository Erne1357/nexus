<?php

it('usa la zona horaria de Ciudad Juárez', function () {
    expect(config('app.timezone'))->toBe('America/Ciudad_Juarez')
        ->and(now()->timezoneName)->toBe('America/Ciudad_Juarez');
});
