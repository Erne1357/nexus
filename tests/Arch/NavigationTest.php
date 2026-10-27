<?php

// Octane: el registro de navegación se arma una vez por worker y lo comparten todas las peticiones,
// así que ni él ni sus entradas pueden cambiar después de construirse.
arch('the navigation registry and its items are final and readonly')
    ->expect('App\Modules\Core\Navigation')
    ->classes()
    ->toBeFinal()
    ->toBeReadonly();
