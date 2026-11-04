<?php

use Symfony\Component\Finder\Finder;

/*
| Desde 3.P8 las jefaturas son nombramientos (`role_assignments`, AZ-S14) y `department_heads` es histórica y de solo
| lectura: sus triggers rechazan todo cambio. Fuera de las migraciones que la crearon, cambiaron y congelaron, nada en
| `app/` ni en `resources/` la nombra, ni a su modelo retirado, para que nadie vuelva a escribir ahí.
*/
it('nada fuera de las migraciones nombra department_heads ni DepartmentHead', function () {
    $root = dirname(__DIR__, 2);
    $offenders = [];

    foreach (Finder::create()->files()->in(["{$root}/app", "{$root}/resources"])->notPath('#(^|/)Database/Migrations/#') as $file) {
        if (preg_match('/department_heads|DepartmentHead/', $file->getContents()) === 1) {
            $offenders[] = $file->getRelativePathname();
        }
    }

    expect($offenders)->toBe([]);
});
