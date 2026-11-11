<?php

use App\Modules\Academic\Models\Subject;
use App\Modules\Core\Models\Campus;
use Database\Seeders\DemoSeeder;
use Tests\Support\Academic\DemoCatalog;

/*
| Catálogo demo (tarea 1.10 ampliada): departamentos, carreras con su oferta por campus, planes con su retícula,
| especialidades y seriación, los usuarios de prueba y los periodos, para probar las pantallas con datos
| realistas. DemoSeeder siembra Campus II; con los dos campus se siembran las 12 carreras de Campus I.
|
| Las pruebas del demo académico van en cinco archivos (AcademicDemoSeederTest, AcademicDemoCatalogTest,
| AcademicDemoStaffTest, AcademicDemoReseedTest y AcademicDemoMassiveTest) porque casi todas siembran el demo
| completo: juntas tardaban unos 10 minutos en un solo proceso y marcaban el tiempo de toda la suite. Separadas, se
| reparten entre los procesos y los shards del CI (docs/arquitectura/testing.md, «Shards»). Los atajos están en
| tests/Support/Academic/DemoCatalog.php.
|
| Este archivo: sembrar dos veces el demo de Campus II no duplica nada.
*/

it('es idempotente: sembrar dos veces no duplica', function () {
    $this->seed(DemoSeeder::class);
    $first = DemoCatalog::counts();
    $this->seed(DemoSeeder::class);

    expect(DemoCatalog::counts())->toBe($first)
        ->and($first[Subject::class])->toBeGreaterThan(0);
});
