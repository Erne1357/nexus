<?php

use App\Modules\Core\Queries\TextSearch;

it('escapa %, _ y \ para que LIKE los tome literales', function () {
    expect(TextSearch::escape('100%_a\b'))->toBe('100\%\_a\\\\b');
});

it('deja igual un texto sin comodines', function () {
    expect(TextSearch::escape('Campus II'))->toBe('Campus II');
});
