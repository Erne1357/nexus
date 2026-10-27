<?php

arch('does not use debugging or deprecated PHP functions')->preset()->php();

arch('does not use insecure PHP functions')->preset()->security();

arch('does not use Laravel debugging helpers')
    ->expect(['dd', 'ddd'])
    ->not->toBeUsed();

// Con config:cache y Octane, env() fuera de config/ devuelve null.
arch('reads env() only from config files')
    ->expect('env')
    ->not->toBeUsed();
