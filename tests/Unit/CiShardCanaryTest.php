<?php

it('falla a propósito para comprobar que el job tests queda rojo si falla un shard (se quita antes de mezclar)', function () {
    expect(true)->toBeFalse();
});
