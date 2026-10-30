<?php

use App\Modules\Academic\Models\Career;
use App\Modules\Academic\Models\StudyPlan;
use App\Modules\Core\Models\User;

/*
| CRUD de Planes de estudio en el navegador: el admin entra por el menú, da de alta un plan y lo
| edita desde la UI. Planes es del área Académico, la del inicio.
*/

beforeEach(function () {
    $this->isc = Career::factory()->create(['code' => 'ISC', 'name' => 'Ingeniería en Sistemas']);
    StudyPlan::factory()->for($this->isc)->create(['code' => 'ISIC-2010-224', 'name' => 'Sistemas 2010']);
    User::factory()->admin()->create(['username' => 'admin', 'name' => 'Ana Admin']);
});

it('el admin da de alta un plan y luego lo edita desde la UI', function () {
    $page = visit('/login')
        ->fill('username', 'admin')
        ->fill('password', 'password')
        ->press('Entrar')
        ->assertPathIs('/')
        ->click('#nexus-menu a[data-label="Planes de estudio"]')
        ->assertPathIs('/academic/plans')
        ->assertSee('Sistemas 2010')
        ->press('Nuevo plan')
        ->fill('code', 'isic-2025-001')
        ->fill('name', 'Sistemas 2025')
        ->select('career_id', $this->isc->id)
        ->fill('version', '2025')
        ->fill('semesters_count', '9')
        ->fill('total_credits', '270')
        ->press('Guardar')
        ->waitForText('Plan creado')
        ->assertSee('Sistemas 2025')
        ->assertNoJavaScriptErrors();

    expect(StudyPlan::where('code', 'ISIC-2025-001')->sole())
        ->name->toBe('Sistemas 2025')->total_credits->toBe(270);

    $page->click('@edit-ISIC-2025-001')
        ->assertValue('code', 'ISIC-2025-001')
        ->fill('total_credits', '275')
        ->press('Guardar')
        ->waitForText('Plan actualizado')
        ->assertSee('275')
        ->assertNoJavaScriptErrors();

    expect(StudyPlan::where('code', 'ISIC-2025-001')->sole()->total_credits)->toBe(275);
});
