<?php

use App\Modules\Academic\Models\Department;
use App\Modules\CatalogValidation\Actions\CreateTermReviews;
use App\Modules\CatalogValidation\Enums\Catalog;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\TermOpening;
use App\Modules\Core\Models\User;
use App\Modules\Facilities\Models\Building;
use App\Modules\Facilities\Models\Room;

/*
| CV16 (confirmado el 20 de noviembre de 2026): el jefe de departamento abre «Reclamar salones sin dueño» en su revisión
| de Salones, filtra por campus, reclama 2 y los ve en su lista por revisar, con la insignia «Reclamado».
*/

it('el jefe reclama salones sin dueño desde su revisión y los ve por revisar', function () {
    $term = Term::factory()->current()->create();
    $c1 = Campus::factory()->create(['code' => 'C1', 'name' => 'Campus I']);
    $c2 = Campus::factory()->create(['code' => 'C2', 'name' => 'Campus II']);
    $sis = Department::factory()->create(['code' => 'SIS', 'name' => 'Sistemas y Computación']);
    TermOpening::factory()->create(['term_id' => $term->id, 'modality_id' => modalityOf('PRES')->id]);
    app(CreateTermReviews::class)->handle($term, Catalog::cases());
    User::factory()->withRole(Role::JefeDepartamento, ['department_id' => $sis->id])->create(['username' => 'jefe']);

    $e1 = Building::factory()->create(['campus_id' => $c1->id, 'code' => 'E1', 'name' => 'Edificio 1']);
    $e2 = Building::factory()->create(['campus_id' => $c2->id, 'code' => 'E2', 'name' => 'Edificio 2']);
    $first = Room::factory()->for($e2)->create(['code' => 'B-201']);
    $second = Room::factory()->for($e2)->create(['code' => 'B-202']);
    $other = Room::factory()->for($e1)->create(['code' => 'A-101']);

    visit('/login')
        ->fill('username', 'jefe')
        ->fill('password', 'password')
        ->press('Entrar')
        ->assertPathIs('/')
        ->click('@hare')
        ->click('#nexus-menu a[data-label="Validar catálogos"]')
        ->assertPathIs('/catalog-validation')
        ->click('@tab-rooms')
        ->waitForText('Reclamar salones sin dueño (3)')
        ->click('@claim-rooms')
        ->waitForText('Reclamar salones sin dueño')
        ->assertSee('C1 · A-101')
        ->select('[data-testid="claim-campus"]', $c2->id)
        ->waitForText('2 salones sin dueño')
        ->assertDontSee('C1 · A-101')
        ->click('@claim-select-C2-B-201')
        ->click('@claim-select-C2-B-202')
        ->assertSee('Reclamar 2 seleccionados')
        ->click('@claim-confirm')
        ->waitForText('Reclamaste 2 salones')
        ->assertDontSee('Reclamar 2 seleccionados')
        ->assertSee('Reclamado');

    expect($first->fresh()->department_id)->toBe($sis->id)
        ->and($second->fresh()->department_id)->toBe($sis->id)
        ->and($other->fresh()->department_id)->toBeNull();
});
