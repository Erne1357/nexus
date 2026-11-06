<?php

use App\Modules\Academic\Models\StudyPlan;
use App\Modules\Core\Models\User;
use Database\Seeders\DemoSeeder;

/*
| Orden de las materias en el semestre (D21, confirmado el 5 de noviembre de 2026): el admin arrastra una materia
| por su agarre dentro de su semestre en la retícula de ISIC-2010-224 y, al recargar, el orden se conserva.
*/

it('el admin arrastra una materia dentro de su semestre y el orden persiste al recargar', function () {
    $this->seed(DemoSeeder::class);
    $plan = StudyPlan::where('code', 'ISIC-2010-224')->sole();
    User::factory()->admin()->create(['username' => 'ana.admin', 'name' => 'Ana Admin']);

    $codes = <<<'JS'
    (() => Array.from(document.querySelectorAll('[data-semester="1"] [data-subject]')).map((tile) => tile.dataset.subject))()
    JS;

    $page = visit('/login')
        ->fill('username', 'ana.admin')
        ->fill('password', 'password')
        ->press('Entrar')
        ->assertPathIs('/')
        ->navigate('/academic/plans/'.$plan->id)
        ->waitForText('Semestre 1');

    $before = $page->script($codes);
    expect(count($before))->toBeGreaterThan(2);

    $page->drag(
        '[data-semester="1"] [data-subject="'.$before[0].'"] [data-sort-handle]',
        '[data-semester="1"] [data-subject="'.$before[count($before) - 1].'"]',
    );

    $after = [...array_slice($before, 1), $before[0]];
    $page->wait(1.5);

    expect($page->script($codes))->toBe($after);

    $page->navigate('/academic/plans/'.$plan->id)->waitForText('Semestre 1');

    expect($page->script($codes))->toBe($after);
    $page->assertNoJavaScriptErrors();
});
