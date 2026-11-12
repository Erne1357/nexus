<?php

use App\Modules\Academic\Models\Department;
use App\Modules\CatalogValidation\Actions\CreateTermReviews;
use App\Modules\CatalogValidation\Enums\Catalog;
use App\Modules\CatalogValidation\Enums\ReviewStatus;
use App\Modules\CatalogValidation\Models\CatalogReview;
use App\Modules\CatalogValidation\Models\CatalogReviewMark;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\TermOpening;
use App\Modules\Core\Models\User;
use App\Modules\Facilities\Models\Building;
use App\Modules\Facilities\Models\Room;
use App\Modules\Faculty\Models\Teacher;

/*
| Validación de catálogos en el navegador, opción A «Tabla con columna Revisión» (12 de noviembre de 2026): el jefe de
| departamento mueve un salón a un edificio de otro campus desde su drawer (el campus sigue al edificio y queda revisado,
| CV-S10, CV7); en Docentes marca registros con el teclado (J, Espacio, R), captura un correo institucional en el
| recuadro de la fila (AC-06), da de baja a un docente con su confirmación (CV8), marca los que faltan juntos con
| «Elegir los N por revisar» y, con todo revisado, valida el catálogo desde «Revisaste los N docentes».
*/

it('el jefe revisa salones y docentes registro por registro, con teclado y en lote, y valida', function () {
    $term = Term::factory()->current()->create();
    $c2 = Campus::factory()->create(['code' => 'C2', 'name' => 'Campus II']);
    $c1 = Campus::factory()->create(['code' => 'C1', 'name' => 'Campus I']);
    $sis = Department::factory()->create(['code' => 'SIS', 'name' => 'Sistemas y Computación']);
    TermOpening::factory()->create(['term_id' => $term->id, 'campus_id' => $c2->id]);
    app(CreateTermReviews::class)->handle($term, Catalog::cases());
    User::factory()->withRole(Role::JefeDepartamento, ['department_id' => $sis->id])->create(['username' => 'jefe']);

    $room = Room::factory()->for(Building::factory()->create(['campus_id' => $c2->id, 'code' => 'E2', 'name' => 'Edificio 2']))->create(['code' => 'Y-104', 'name' => 'Aula Y-104', 'department_id' => $sis->id]);
    $north = Building::factory()->create(['campus_id' => $c1->id, 'code' => 'LC', 'name' => 'Laboratorio de cómputo']);

    foreach (['AAAA800101AA1' => 'Adriana Beltrán', 'BBBB800101BB1' => 'Beatriz Olivas', 'CCCC800101CC1' => 'Carlos Mendoza', 'DDDD800101DD1' => 'Diana Trejo', 'FFFF800101FF1' => 'Francisco Lerma'] as $rfc => $name) {
        Teacher::factory()->adscribedTo($sis)->create(['rfc' => $rfc, 'name' => $name, 'institutional_email' => strtolower(substr($rfc, 0, 4)).'@cdjuarez.tecnm.mx']);
    }
    $leaving = Teacher::factory()->adscribedTo($sis)->create(['rfc' => 'EEEE800101EE1', 'name' => 'Elena Saucedo', 'institutional_email' => 'eeee@cdjuarez.tecnm.mx']);
    $withoutEmail = Teacher::factory()->adscribedTo($sis)->create(['rfc' => 'ZZZZ800101ZZ1', 'name' => 'Daniel Esparza']);

    $page = visit('/login')
        ->fill('username', 'jefe')
        ->fill('password', 'password')
        ->press('Entrar')
        ->assertPathIs('/')
        ->navigate('/catalog-validation?catalogo=rooms')
        ->assertSee('0 de 1 revisados')
        ->click('@edit-Y-104')
        ->select('#room-building', $north->id)
        ->waitForText('con el edificio.')
        ->assertSeeIn('@room-campus', 'Campus I')
        ->click('@save-record')
        ->waitForText('Salón actualizado')
        ->assertSee('Revisaste el único salón.');

    expect($room->fresh())->building_id->toBe($north->id)->campus_id->toBe($c1->id);

    $page->click('@tab-teachers')
        ->waitForText('0 de 7 revisados')
        ->keys('@review-table', 'j')
        ->keys('tr[data-row]:focus', ' ')
        ->keys('tr[data-row]:focus', 'j')
        ->keys('tr[data-row]:focus', ' ')
        ->assertSee('Marcar 2 como revisados')
        ->keys('tr[data-row]:focus', 'r')
        ->waitForText('2 de 7 revisados')
        ->assertScript("document.activeElement.getAttribute('aria-label').startsWith('DDDD800101DD1')")
        ->keys('tr[data-row]:focus', 'r')
        ->waitForText('3 de 7 revisados')
        ->click('@add-email-ZZZZ800101ZZ1')
        ->fill('#review-email-input', 'daniel.esparza')
        ->click('@save-email')
        ->waitForText('Correo institucional guardado')
        ->assertSee('4 de 7 revisados')
        ->click('@discharge-EEEE800101EE1')
        ->assertSeeIn('#nexus-confirm', '¿Dar de baja a Elena Saucedo?')
        ->assertSeeIn('#nexus-confirm', 'No tiene otro departamento')
        ->click('#nexus-confirm [data-testid="confirm-accept"]')
        ->waitForText('Docente dado de baja')
        ->assertSee('4 de 6 revisados')
        ->click('@select-FFFF800101FF1')
        ->click('@choose-all-pending')
        ->assertSee('Marcar 2 como revisados')
        ->click('@mark-selected')
        ->waitForText('Revisaste los 6 docentes.')
        ->click('@validate-teachers-done')
        ->click('@confirm-accept')
        ->waitForText('Revisión validada')
        ->assertNoJavaScriptErrors();

    $review = CatalogReview::query()->where('catalog', Catalog::Teachers)->sole();

    expect($review->status)->toBe(ReviewStatus::Validated)
        ->and(CatalogReviewMark::query()->where('catalog_review_id', $review->id)->count())->toBe(6)
        ->and($withoutEmail->fresh()->institutional_email)->toBe('daniel.esparza@cdjuarez.tecnm.mx')
        ->and($leaving->fresh()->is_active)->toBeFalse();
});
