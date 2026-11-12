<?php

use App\Modules\Academic\Models\Department;
use App\Modules\Academic\Models\Subject;
use App\Modules\CatalogValidation\Actions\CreateTermReviews;
use App\Modules\CatalogValidation\Enums\ReviewStatus;
use App\Modules\CatalogValidation\Models\CatalogReview;
use App\Modules\CatalogValidation\Models\CatalogReviewFlag;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\TermOpening;
use App\Modules\Core\Models\User;

/*
| Validación de catálogos en el navegador: el jefe de departamento entra por el menú, abre Materias, corrige una
| materia en el drawer (eso la marca como revisada, CV7), le agrega una observación, marca la otra con su interruptor,
| vuelve al tablero por su pestaña y valida, que solo se activa con todo revisado.
*/

it('el jefe abre Materias, corrige una materia, la observa, regresa y valida', function () {
    $term = Term::factory()->current()->create();
    $campus = Campus::factory()->create();
    $sis = Department::factory()->create(['code' => 'SIS', 'name' => 'Sistemas y Computación']);
    TermOpening::factory()->create(['term_id' => $term->id, 'modality_id' => $campus->modality_id]);
    app(CreateTermReviews::class)->handle($term, $campus);
    Subject::factory()->create(['code' => 'SCD-1027', 'name' => 'Programación Orientada a Objetos', 'department_id' => $sis->id]);
    Subject::factory()->create(['code' => 'SCD-1028', 'name' => 'Redes de Computadoras', 'department_id' => $sis->id]);
    User::factory()->withRole(Role::JefeDepartamento, ['department_id' => $sis->id])->create(['username' => 'jefe']);

    visit('/login')
        ->fill('username', 'jefe')
        ->fill('password', 'password')
        ->press('Entrar')
        ->assertPathIs('/')
        ->click('#nexus-menu a[data-label="Validar catálogos"]')
        ->assertPathIs('/catalog-validation')
        ->assertSee('0 de 5')
        ->click('@open-subjects')
        ->assertSee('Programación Orientada a Objetos')
        ->click('@edit-SCD-1027')
        ->fill('name', 'Programación Orientada a Objetos I')
        ->press('Guardar')
        ->waitForText('Materia actualizada')
        ->click('@edit-SCD-1027')
        ->select('flag', 'missing_data')
        ->fill('comment', 'Faltan las horas de práctica.')
        ->click('@add-flag')
        ->waitForText('Observación agregada')
        ->assertSee('Faltan las horas de práctica.')
        ->click('[aria-label="Cerrar el panel"]')
        ->assertSee('1 de 2 revisados')
        ->assertSee('Falta 1 registro por revisar.')
        ->click('@reviewed-SCD-1028')
        ->waitForText('2 de 2 revisados')
        ->click('@tab-board')
        ->assertSee('1 observación abierta')
        ->click('@validate-subjects')
        ->click('@confirm-accept')
        ->waitForText('Revisión validada')
        ->assertSee('1 de 5')
        ->assertNoJavaScriptErrors();

    $review = CatalogReview::query()->where('catalog', 'subjects')->sole();

    expect(Subject::where('code', 'SCD-1027')->sole()->name)->toBe('Programación Orientada a Objetos I')
        ->and($review->status)->toBe(ReviewStatus::Validated)
        ->and(CatalogReviewFlag::count())->toBe(1);
});
